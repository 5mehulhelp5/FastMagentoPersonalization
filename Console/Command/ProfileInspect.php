<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\App\ResourceConnection;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\AffinityCalculator;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileBuilder;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileRepository;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PurchaseHistoryProvider;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show the purchase-derived affinity profile for a shopper, or for an arbitrary set of products.
 *
 * Read-only and deliberately so: this milestone ships DARK. Nothing reads a profile at query time
 * yet, so this command is how the maths gets checked against real data before it can affect a
 * storefront.
 *
 *   bin/magento fastmagento:profile:inspect --customer=1
 *   bin/magento fastmagento:profile:inspect --products=53,54,55,56   # prove the entropy guard
 */
class ProfileInspect extends Command
{
    // `category` is added automatically by the history provider; it is not an EAV attribute.
    private const DEFAULT_ATTRIBUTES = 'color,size';

    public function __construct(
        private readonly State $appState,
        private readonly ResourceConnection $resource,
        private readonly PurchaseHistoryProvider $history,
        private readonly AffinityCalculator $calculator,
        private readonly ProfileBuilder $builder,
        private readonly ProfileRepository $repository,
        private readonly ValueDiscrimination $discrimination,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\EventHistoryProvider $events,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig $personalizationConfig,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:profile:inspect')
            ->setDescription('Show the purchase-derived attribute affinities for a shopper (read-only)')
            ->addOption('customer', null, InputOption::VALUE_REQUIRED, 'Customer entity id')
            ->addOption('products', null, InputOption::VALUE_REQUIRED, 'Comma-separated product ids to profile instead of a customer')
            ->addOption('attributes', null, InputOption::VALUE_REQUIRED, 'Attributes to profile', self::DEFAULT_ATTRIBUTES)
            ->addOption('half-life', null, InputOption::VALUE_REQUIRED, 'Recency half-life in days (0 = no decay)', '180')
            ->addOption('save', null, InputOption::VALUE_NONE, 'Persist the profile to the OpenSearch profile index')
            ->addOption('forget-facts', null, InputOption::VALUE_NONE, 'Clear the inferred facts for this shopper (they are proposals, and a shopper may reject them)');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Throwable $e) {
            // Already set — fine.
        }

        $attributes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $input->getOption('attributes'))
        )));

        $customerId = (int) $input->getOption('customer');
        $productList = (string) $input->getOption('products');

        if ($productList !== '') {
            $purchases = $this->purchasesFromProducts($productList, $attributes);
            $source = 'products ' . $productList;
        } elseif ($customerId > 0) {
            $purchases = $this->history->forCustomer(
                $customerId,
                $attributes,
                (float) $input->getOption('half-life')
            );
            $source = 'customer ' . $customerId;
        } else {
            $output->writeln('<error>Pass --customer=<id> or --products=<ids></error>');
            return Command::INVALID;
        }

        if (!$purchases && $customerId <= 0) {
            $output->writeln(sprintf('<comment>No purchase history with those attributes for %s.</comment>', $source));
            return Command::SUCCESS;
        }

        // Traits are per-customer facts, not products — only meaningful on the customer path.
        if ($customerId > 0) {
            $traits = $this->history->getTraits($customerId);
            $band = $this->history->getPriceBand($customerId);

            if ($traits) {
                $output->writeln('');
                $output->writeln('<info>Shopper traits</info>');
                $output->writeln(sprintf(
                    '  orders=%d  coupon_rate=%.0f%%  discount_rate=%.0f%%  avg_discount=%.0f%%  %s',
                    $traits['orders'],
                    $traits['coupon_rate'] * 100,
                    $traits['discount_rate'] * 100,
                    $traits['avg_discount_share'] * 100,
                    $traits['discount_driven'] ? '<comment>DISCOUNT-DRIVEN</comment>' : ''
                ));
                if (!empty($traits['order_total'])) {
                    $t = $traits['order_total'];
                    $output->writeln(sprintf(
                        '  order total    p25=%.2f  p50=%.2f  p90=%.2f',
                        $t['p25'], $t['p50'], $t['p90']
                    ));
                }
                if ($band) {
                    $output->writeln(sprintf(
                        '  item price     p25=%.2f  p50=%.2f  p90=%.2f   (n=%d)',
                        $band['p25'], $band['p50'], $band['p90'], $band['n']
                    ));
                }
            }
        }

        if ($customerId > 0 && $input->getOption('forget-facts')) {
            $profileId = ProfileRepository::idForCustomer($customerId);
            $stored = $this->repository->get($profileId);
            if ($stored !== null) {
                $stored['facts'] = [];
                $stored['facts_forgotten_at'] = gmdate('c');
                $this->repository->save($profileId, $stored);
                $this->repository->refresh();
            }
            $output->writeln('<info>Inferred facts cleared.</info>');
            $output->writeln('<comment>  They will be re-proposed if the shopper searches the same thing again —</comment>');
            $output->writeln('<comment>  a correction is not a permanent gag, and pretending otherwise would be the lie.</comment>');
            $output->writeln('');
        }

        if ($customerId > 0) {
            // Read from the STORED profile where there is one, because that is the copy serving
            // uses and the only copy carrying the resolution — which attribute the shape was mapped
            // to, and whether the catalogue actually holds the value. Falling back to a live
            // extraction keeps the command useful before the first backfill.
            $storedProfile = $this->repository->get(ProfileRepository::idForCustomer($customerId));
            $facts = $storedProfile['facts'] ?? null;
            $live = !is_array($facts) || !$facts;
            if ($live) {
                $facts = $this->events->factsForShopper($customerId, null);
            }

            if ($facts) {
                $output->writeln('');
                $output->writeln(sprintf(
                    '<info>Stated requirements (inferred from searches)</info>%s',
                    $live ? '  <comment>[not in the stored profile yet — run profile:backfill]</comment>' : ''
                ));
                foreach ($facts as $name => $fact) {
                    $output->writeln(sprintf(
                        '  %-10s %-14s confidence=%.2f  from %d search(es): "%s"',
                        $name,
                        $fact['value'] ?? '',
                        $fact['confidence'] ?? 0,
                        $fact['observations'] ?? 0,
                        $fact['evidence'] ?? ''
                    ));

                    // Where the shape lands in THIS catalogue — the difference between a
                    // requirement that ranks and one that is merely recorded. Said plainly,
                    // because "nothing happened" has three different causes here.
                    if (!array_key_exists('attribute', $fact)) {
                        continue;
                    }
                    if ($fact['attribute'] === null) {
                        $output->writeln(sprintf(
                            '             <comment>ranks nothing — no attribute mapped for "%s"'
                            . ' (fastmagento/personalization/fact_attributes)</comment>',
                            $name
                        ));
                    } elseif (empty($fact['value_id'])) {
                        $output->writeln(sprintf(
                            '             <comment>ranks nothing — %s carries no value matching "%s"</comment>',
                            (string) $fact['attribute'],
                            (string) ($fact['value'] ?? '')
                        ));
                    } else {
                        $output->writeln(sprintf(
                            '             <info>ranks on %s = %s (option %d)</info>',
                            (string) $fact['attribute'],
                            (string) ($fact['label'] ?? ''),
                            (int) $fact['value_id']
                        ));
                    }
                }
                $output->writeln('  <comment>proposals, not conclusions — low confidence by design, and clearable</comment>');
                $output->writeln('  <comment>with --forget-facts. A fact RANKS HARDER than an affinity and skips the</comment>');
                $output->writeln('  <comment>concentration gate, so being confidently wrong here costs more than knowing</comment>');
                $output->writeln('  <comment>nothing. It still only boosts — nothing a fact touches is hidden.</comment>');
            }

            $returned = $this->history->getReturnedProductIds($customerId);
            if ($returned) {
                $output->writeln('');
                $output->writeln('<info>Returned</info>');
                $output->writeln(sprintf(
                    '  %d product(s) sent back: %s',
                    count($returned),
                    implode(', ', array_map(static fn ($id) => '#' . $id, $returned))
                ));
                $output->writeln('  <comment>netted out of the affinities above; recorded as negative at PRODUCT level only —</comment>');
                $output->writeln('  <comment>a return is usually the wrong size, not the wrong colour</comment>');
            }

            $reviews = $this->history->getReviews($customerId);
            if ($reviews) {
                $output->writeln('');
                $output->writeln('<info>Reviews written</info>');
                foreach ($reviews as $review) {
                    $verdict = $review['sentiment'] > 0.2
                        ? '<info>positive</info>'
                        : ($review['sentiment'] < -0.2 ? '<error>NEGATIVE — suppress</error>' : '<comment>neutral</comment>');
                    $output->writeln(sprintf(
                        '  %-22s %s%s  sentiment=%+.2f  %s  "%s"',
                        $review['sku'] !== '' ? $review['sku'] : ('#' . $review['product_id']),
                        str_repeat('★', (int) round($review['rating'])),
                        str_repeat('☆', max(0, 5 - (int) round($review['rating']))),
                        $review['sentiment'],
                        $verdict,
                        $review['title']
                    ));
                }
            }
        }

        // Blend in stated signals exactly as ProfileBuilder does. If this command showed only
        // purchases it would contradict the profile it exists to explain — and it did, briefly:
        // a shopper whose stored colour affinity was actionable from their searches was reported
        // here as "ignored, too little evidence".
        $eventObservations = [];
        if ($customerId > 0) {
            $eventWeight = $this->personalizationConfig->getEventWeight();
            if ($eventWeight > 0.0) {
                $eventObservations = $this->events->forShopper(
                    $customerId,
                    null,
                    array_merge($attributes, ['category']),
                    $this->history->resolveValueIds(array_merge($attributes, ['category'])),
                    (float) $input->getOption('half-life'),
                    $eventWeight,
                    $this->personalizationConfig->getViewWeight()
                );
            }
        }
        $wishlistObservations = [];
        if ($customerId > 0) {
            $wishlistIds = $this->history->getWishlistProductIds($customerId);
            $eventWeight = $this->personalizationConfig->getEventWeight();
            if ($wishlistIds && $eventWeight > 0.0) {
                $codes = array_merge($attributes, ['category']);
                foreach ($this->history->resolveProductAttributes($wishlistIds, $codes) as $values) {
                    if ($values) {
                        $wishlistObservations[] = ['values' => $values, 'weight' => $eventWeight];
                    }
                }
            }
        }
        $observations = array_merge($purchases, $eventObservations, $wishlistObservations);

        $cardinality = $this->history->getCatalogueCardinality($attributes);
        $affinities = $this->calculator->calculate($observations, $cardinality);

        $output->writeln('');
        $output->writeln(sprintf('<info>Affinity profile — %s</info>', $source));
        $output->writeln(sprintf(
            '  items counted: %d  (%d purchased, %d searched or filtered, %d saved for later)',
            count($observations),
            count($purchases),
            count($eventObservations),
            count($wishlistObservations)
        ));
        $output->writeln('');

        // The catalogue-side gate. An affinity can pass every shopper-side test and still be
        // unable to move the page, because the value it favours is on most of the catalogue.
        $valueIds = $this->history->resolveValueIds(array_keys($affinities));

        foreach ($affinities as $affinity) {
            $data = $affinity->toArray();
            $topLabel = (string) array_key_first($data['values']);
            $topId = $valueIds[$data['attribute']][$topLabel] ?? null;
            // The native index is what BOTH the listing and the search grid rank against — the
            // serving index is only ever hydrated from — so this is the share that decides
            // whether a boost can move anything, and the only one worth printing.
            $share = $topId !== null
                ? $this->discrimination->getShare(
                    $data['attribute'],
                    (string) $topId,
                    ValueDiscrimination::TARGET_NATIVE
                )
                : null;

            if (!$data['actionable']) {
                $verdict = '<comment>ignored (too spread out, or too little evidence)</comment>';
            } elseif ($share === null) {
                $verdict = '<info>ACTIONABLE</info> <comment>(catalogue not measured — run '
                    . 'fastmagento:personalization:discrimination)</comment>';
            } elseif ($share > ValueDiscrimination::NEAR_UNIFORM_SHARE) {
                // The honest verdict, and the one that would otherwise look like success: a real
                // preference that cannot change this listing.
                // "Cannot re-order a listing" is not the same as "useless". The catalogue-side
                // gate is relative to the set being chosen from: across 187 products size L is
                // everywhere, but among ONE product's five variants it is exactly the right
                // signal. Saying only the first half reads as "this preference is unusable".
                $verdict = sprintf(
                    '<comment>ACTIONABLE but NON-DISCRIMINATING — "%s" is on %.0f%% of the catalogue, '
                    . 'so boosting it reorders nothing (still used to preselect the variant)</comment>',
                    $topLabel,
                    $share * 100
                );
            } else {
                $verdict = sprintf(
                    '<info>ACTIONABLE</info> <comment>("%s" on %.0f%% of listings, idf %.2f)</comment>',
                    $topLabel,
                    $share * 100,
                    (float) $this->discrimination->getIdf(
                        $data['attribute'],
                        (string) $topId,
                        ValueDiscrimination::TARGET_NATIVE
                    )
                );
            }


            $output->writeln(sprintf(
                '  %-12s strength=%.2f  confidence=%.2f  n=%-3d  catalogue offers %d  %s',
                $data['attribute'],
                $data['strength'],
                $data['confidence'],
                $data['observations'],
                $cardinality[$data['attribute']] ?? 0,
                $verdict
            ));

            foreach ($data['values'] as $value => $weight) {
                $bar = str_repeat('█', (int) round($weight * 24));
                $output->writeln(sprintf('       %-14s %5.1f%%  %s', $value, $weight * 100, $bar));
            }
            $output->writeln('');
        }

        if ($input->getOption('save')) {
            if ($customerId <= 0) {
                $output->writeln('<error>--save needs --customer</error>');
                return Command::INVALID;
            }
            $saved = $this->builder->buildForCustomer($customerId, $attributes);
            // OpenSearch is near-real-time; without a refresh the count below reports the state
            // from before this save.
            $this->repository->refresh();
            $output->writeln($saved
                ? sprintf(
                    '<info>Saved %s to %s (%d profiles in index)</info>',
                    $saved['profile_id'],
                    'the profile index',
                    $this->repository->count()
                )
                : '<comment>Nothing saved — check FastMagento > Personalisation > Build Shopper Profiles.</comment>');
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    /**
     * Profile an explicit product set — how the entropy guard gets demonstrated without needing a
     * shopper who happens to have the right order history.
     *
     * @param string[] $attributes
     * @return array<int, array{values: array<string, string>, weight: float}>
     */
    private function purchasesFromProducts(string $productList, array $attributes): array
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $productList))));
        if (!$ids) {
            return [];
        }

        $reflection = new \ReflectionMethod(PurchaseHistoryProvider::class, 'loadAttributeValues');
        $reflection->setAccessible(true);
        $valuesByProduct = $reflection->invoke($this->history, $ids, $attributes);

        $purchases = [];
        foreach ($ids as $id) {
            if (!empty($valuesByProduct[$id])) {
                $purchases[] = ['values' => $valuesByProduct[$id], 'weight' => 1.0];
            }
        }

        return $purchases;
    }
}
