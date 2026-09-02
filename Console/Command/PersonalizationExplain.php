<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\QueryPersonalizer;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show exactly what personalisation would do to one shopper's query, and why.
 *
 * The whole mechanism is two gates deep — a preference has to be real AND has to be able to reorder
 * the index being ranked — so "it isn't boosting" has several honest causes that look identical
 * from outside. This prints which one applies, per surface, without needing a browser session.
 *
 *   bin/magento fastmagento:personalization:explain --customer=2
 *   bin/magento fastmagento:personalization:explain --customer=6 --surface=plp
 */
class PersonalizationExplain extends Command
{
    public function __construct(
        private readonly State $appState,
        private readonly QueryPersonalizer $personalizer,
        private readonly PersonalizationConfig $config,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:personalization:explain')
            ->setDescription('Show the scoring clauses personalisation would add for one shopper, and why')
            ->addOption('customer', null, InputOption::VALUE_REQUIRED, 'Customer entity id')
            ->addOption('surface', null, InputOption::VALUE_REQUIRED, 'search | plp | recommendations (default: all)')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store id', '1');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Throwable $e) {
            // Already set — fine.
        }

        $customerId = (int) $input->getOption('customer');
        if ($customerId <= 0) {
            $output->writeln('<error>Pass --customer=<id></error>');

            return Command::INVALID;
        }

        $storeId = (int) $input->getOption('store');
        $only = (string) $input->getOption('surface');

        // Surface => the index that surface actually ranks. Getting this pairing wrong is the
        // subtle failure the two-target discrimination table exists to prevent.
        $surfaces = [
            // Search ranks Magento's index too — InstantSearch only hydrates from FastMagento's.
            PersonalizationConfig::SURFACE_SEARCH => ValueDiscrimination::TARGET_NATIVE,
            PersonalizationConfig::SURFACE_PLP => ValueDiscrimination::TARGET_NATIVE,
            PersonalizationConfig::SURFACE_RECOMMENDATIONS => ValueDiscrimination::TARGET_NATIVE,
        ];

        $output->writeln('');
        $output->writeln(sprintf('<info>Personalisation for customer %d</info>', $customerId));
        $output->writeln(sprintf(
            '  master switch: %s      building profiles: %s',
            $this->config->isApplied($storeId) ? '<info>ON</info>' : '<comment>OFF</comment>',
            $this->config->isBuildingProfiles($storeId) ? '<info>ON</info>' : '<comment>OFF</comment>'
        ));

        foreach ($surfaces as $surface => $target) {
            if ($only !== '' && $only !== $surface) {
                continue;
            }

            $impact = $this->config->getImpact($surface, $storeId);
            $terms = $this->personalizer->explainTerms($surface, $target, $storeId, $customerId);

            $output->writeln('');
            $output->writeln(sprintf(
                '<comment>%s</comment>  (ranks the %s index, impact %.0f%%)',
                strtoupper($surface),
                $target,
                $impact * 100
            ));

            if ($impact <= 0.0) {
                $output->writeln('  <comment>no boost — surface impact is 0, so the query is returned untouched</comment>');
                continue;
            }

            if (!$terms) {
                $output->writeln('  <comment>no boost — nothing survived both gates (see profile:inspect for which)</comment>');
                continue;
            }

            foreach ($terms as $term) {
                // Which TIER a clause came from is the one thing the emitted function form cannot
                // say, and it is the thing a merchant asking "why did this rank here" wants first:
                // a requirement the shopper stated, or a preference we inferred.
                $output->writeln(sprintf(
                    '  <info>+%.4f</info>  %s = %-8s  %s',
                    round((float) $term['weight'], 4),
                    (string) $term['field'],
                    (string) $term['term'],
                    isset($term['fact'])
                        ? sprintf('<info>STATED</info> %s = %s', (string) $term['fact'], (string) $term['label'])
                        : sprintf('<comment>inferred</comment> %s', (string) $term['label'])
                ));
            }
            $output->writeln(sprintf(
                '  <comment>%d clause(s); every one is a boost — nothing filters, nothing is hidden</comment>',
                count($terms)
            ));
        }

        $output->writeln('');

        return Command::SUCCESS;
    }
}
