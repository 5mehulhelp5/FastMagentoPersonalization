<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

/**
 * Assembles a shopper's profile from order history and persists it.
 *
 * Runs off the request path only — CLI today, a queue consumer later. Nothing about profile
 * maintenance may touch a page render: the whole premise of this module is query count per page,
 * and a profile rebuild is many queries.
 *
 * Honours the BUILD switch, which is independent of whether personalisation is applied. Profiles
 * are built whether or not they change what anyone sees, so the data is warm the moment a merchant
 * enables serving and an A/B has no cold arm.
 */
class ProfileBuilder
{
    /** Categories kept per profile, most-bought first. */
    private const MAX_CATEGORY_AFFINITIES = 40;

    public function __construct(
        private readonly PurchaseHistoryProvider $history,
        private readonly AffinityCalculator $calculator,
        private readonly ProfileRepository $repository,
        private readonly PersonalizationConfig $config,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\EventHistoryProvider $events,
        private readonly ValueNormalizer $normalizer
    ) {
    }

    /**
     * @param string[] $attributeCodes
     * @return array<string, mixed>|null the profile written, or null when there was nothing to write
     */
    public function buildForCustomer(int $customerId, array $attributeCodes, ?int $storeId = null): ?array
    {
        if ($customerId <= 0 || !$this->config->isBuildingProfiles($storeId)) {
            return null;
        }

        $purchases = $this->history->forCustomer(
            $customerId,
            $attributeCodes,
            $this->config->getHalfLifeDays($storeId)
        );

        // Stated signals — what this shopper SEARCHED for and FILTERED on — alongside what they
        // bought. Both feed the same calculator as weighted observations, so the concentration and
        // confidence gates apply to the combined evidence rather than to each source separately: a
        // shopper with two purchases and four facet clicks on the same colour is more credible than
        // either on its own, and the maths should see that.
        // `category` is included explicitly: it is a pseudo-attribute the purchase side
        // synthesises, so it is never in the requested list, and layered navigation's `cat` facet
        // would have nothing to resolve against.
        $valueIds = $this->history->resolveValueIds(array_merge($attributeCodes, ['category']));
        $eventWeight = $this->config->getEventWeight($storeId);

        $observations = $purchases;

        // Saved for later. Weighted like a stated signal because that is what it is — a per-product
        // statement uncontaminated by stock, price or delivery. Read as state rather than caught as
        // an event, so items saved before this module existed still count.
        $wishlistIds = $this->history->getWishlistProductIds($customerId);
        if ($wishlistIds && $eventWeight > 0.0) {
            $codes = array_merge($attributeCodes, ['category']);
            foreach ($this->history->resolveProductAttributes($wishlistIds, $codes) as $values) {
                if ($values) {
                    $observations[] = ['values' => $values, 'weight' => $eventWeight];
                }
            }
        }

        if ($eventWeight > 0.0) {
            $observations = array_merge($observations, $this->events->forShopper(
                $customerId,
                null,
                array_merge($attributeCodes, ['category']),
                $valueIds,
                $this->config->getHalfLifeDays($storeId),
                $eventWeight,
                $this->config->getViewWeight($storeId)
            ));
        }

        if (!$observations) {
            return null;
        }

        $affinities = $this->calculator->calculate(
            $observations,
            $this->history->getCatalogueCardinality($attributeCodes)
        );

        $minStrength = $this->config->getMinStrength($storeId);
        $minConfidence = $this->config->getMinConfidence($storeId);

        // Labels are what the profile records and what the inspector prints; ids are what the
        // search index actually holds. Resolved off the request path, so the query-time read stays
        // a single `get` with no lookups behind it. Resolved against the attributes that actually
        // came back, not the requested list: `category` is a pseudo-attribute the history provider
        // synthesises, so it is never in $attributeCodes and would silently never get ids.
        $valueIds = $this->history->resolveValueIds(array_keys($affinities));

        $stored = [];
        foreach ($affinities as $code => $affinity) {
            $data = $affinity->toArray();
            // Record the verdict against the CONFIGURED thresholds, not the value object's
            // defaults, so a merchant changing the dials changes what the stored profile claims.
            $data['actionable'] = $affinity->isActionable($minStrength, $minConfidence);

            // Only the values this shopper actually bought — the full option table would bloat
            // every profile with ids nothing will ever look up.
            $ids = [];
            foreach (array_keys($data['values']) as $label) {
                if (isset($valueIds[$code][$label])) {
                    $ids[$label] = $valueIds[$code][$label];
                }
            }
            $data['value_ids'] = $ids;

            $stored[$code] = $data;
        }

        $reviews = $this->history->getReviews($customerId);
        $returnedProductIds = $this->history->getReturnedProductIds($customerId);

        $profile = [
            'profile_id' => ProfileRepository::idForCustomer($customerId),
            'customer_id' => $customerId,
            'store_id' => $storeId ?? 0,
            'updated_at' => gmdate('c'),
            'observations' => count($observations),
            'purchase_observations' => count($purchases),
            'wishlist_products' => count($wishlistIds),
            'event_observations' => count($observations) - count($purchases),
            'affinities' => $stored,
            // Requirements the shopper stated rather than preferences we inferred. Kept separate
            // from affinities because they are governed differently — a fact skips the concentration
            // and confidence gates that ask whether an inferred preference is real, and ranks
            // several times harder — and because a cold-start fact is a PROPOSAL the shopper is
            // entitled to correct, not a conclusion. It still only ever boosts.
            'facts' => $this->resolveFacts($this->events->factsForShopper($customerId, null), $storeId),
            // What this shopper buys WITHIN each category they have bought from — the evidence a
            // category listing should weigh first (see categoryAffinities()).
            'category_affinities' => $this->categoryAffinities($purchases, $attributeCodes, $storeId),
            'traits' => $this->history->getTraits($customerId),
            'price_band' => $this->history->getPriceBand($customerId),
            // A one-star review is the only negative signal we have today. Keeping it separate
            // from affinities matters: folding it in as interest would recommend more of the thing
            // the shopper told us they disliked.
            // Two independent ways a shopper tells us "not this one": a one-star review, and
            // sending it back. Product-level, never attribute-level — see
            // PurchaseHistoryProvider::getReturnedProductIds() for why a return must not become a
            // dislike of the colour.
            'negative' => [
                'product_ids' => array_values(array_unique(array_merge(
                    array_map(
                        static fn (array $r) => (int) $r['product_id'],
                        array_filter($reviews, static fn (array $r) => $r['sentiment'] < -0.2)
                    ),
                    $returnedProductIds
                ))),
                'reviewed_badly' => array_values(array_map(
                    static fn (array $r) => (int) $r['product_id'],
                    array_filter($reviews, static fn (array $r) => $r['sentiment'] < -0.2)
                )),
                'returned' => $returnedProductIds,
            ],
            'reviews' => $reviews,
        ];

        return $this->repository->save($profile['profile_id'], $profile) ? $profile : null;
    }

    /**
     * Assemble a profile for a shopper known only by their analytics cookie.
     *
     * The guest tier, and the deliberate asymmetry is the point. A customer profile is built from
     * everything the store knows — orders, wishlist, returns, reviews, traits — because the shopper
     * identified themselves to the account that data belongs to. An anonymous profile is built from
     * ONE thing: behaviour observed under this cookie. Searches, facet selections, views, hover,
     * and the facts stated in them. No order history, no wishlist, no traits, even when the cookie
     * has at some point been seen alongside a customer id.
     *
     * That last clause is a privacy rule, not a limitation (PERSONALIZATION.md §3, locked). The
     * cookie identifies a BROWSER, and a browser is not a person: family computers exist. Behaviour
     * observed on the browser remains true of whoever is using it; the account's purchase history
     * does not, and serving it to whoever holds the cookie would show one person's taste — and
     * sizes — to the household. A shopper who wants their full profile back logs in, which is
     * exactly the promise login makes.
     *
     * Events recorded on this browser WHILE logged in still count — they were observed here, and
     * the shopper who logs out keeps the continuity of their own browsing. This is the same line
     * the logout rule draws: the anonymous thread persists, the account attribution stops.
     *
     * @param string[] $attributeCodes
     * @return array<string, mixed>|null the profile written, or null when there was nothing to write
     */
    public function buildForAnonymous(string $anonId, array $attributeCodes, ?int $storeId = null): ?array
    {
        if ($anonId === '' || !$this->config->isBuildingProfiles($storeId)) {
            return null;
        }

        $eventWeight = $this->config->getEventWeight($storeId);
        if ($eventWeight <= 0.0) {
            // Events are the only source this tier has. With their weight dialled to zero there is
            // nothing to build from, and writing an empty profile would just occupy the id.
            return null;
        }

        $codes = array_merge($attributeCodes, ['category']);
        $valueIds = $this->history->resolveValueIds($codes);

        $observations = $this->events->forShopper(
            null,
            $anonId,
            $codes,
            $valueIds,
            $this->config->getHalfLifeDays($storeId),
            $eventWeight,
            $this->config->getViewWeight($storeId)
        );

        if (!$observations) {
            return null;
        }

        $affinities = $this->calculator->calculate(
            $observations,
            $this->history->getCatalogueCardinality($attributeCodes)
        );

        $minStrength = $this->config->getMinStrength($storeId);
        $minConfidence = $this->config->getMinConfidence($storeId);
        $valueIds = $this->history->resolveValueIds(array_keys($affinities));

        $stored = [];
        foreach ($affinities as $code => $affinity) {
            $data = $affinity->toArray();
            $data['actionable'] = $affinity->isActionable($minStrength, $minConfidence);

            $ids = [];
            foreach (array_keys($data['values']) as $label) {
                if (isset($valueIds[$code][$label])) {
                    $ids[$label] = $valueIds[$code][$label];
                }
            }
            $data['value_ids'] = $ids;

            $stored[$code] = $data;
        }

        $profile = [
            'profile_id' => ProfileRepository::idForAnonymous($anonId),
            'anon_id' => $anonId,
            'store_id' => $storeId ?? 0,
            'updated_at' => gmdate('c'),
            'observations' => count($observations),
            'purchase_observations' => 0,
            'event_observations' => count($observations),
            'affinities' => $stored,
            'facts' => $this->resolveFacts($this->events->factsForShopper(null, $anonId), $storeId),
        ];

        return $this->repository->save($profile['profile_id'], $profile) ? $profile : null;
    }

    /**
     * Attach the catalogue value each stated fact refers to, so serving can rank on it.
     *
     * A fact is recognised by SHAPE — `year`, `dimension` — and a shape is not a field. Resolving it
     * to an attribute and an option id is catalogue work, and it belongs HERE, off the request path,
     * for the same reason affinity value ids are resolved here: the query-time read of a profile is
     * one `get` by id with no lookups behind it, and that is a promise this module keeps.
     *
     * Three outcomes, all of them recorded rather than silently dropped, because the shopper is
     * entitled to see what was inferred about them even when it changes nothing:
     *
     *   no mapping   the merchant has not said which attribute holds this shape → `attribute` null
     *   no match     mapped, but the catalogue carries no such value → `attribute` set, no value_id
     *   resolved     `label` and `value_id` set; this fact can rank
     *
     * Matched through the same normaliser the affinities use, so `32x10R15` typed in a search box
     * and `32x10-15` in the option table are one value rather than two.
     *
     * @param array<string, array<string, mixed>> $facts
     * @return array<string, array<string, mixed>>
     */
    /**
     * Affinities computed from the purchases made in each category (ancestors included), keyed
     * by category id, actionable attributes only.
     *
     * Why a separate set rather than the global one: a shopper who buys black tops and red shoes
     * has no global colour preference worth acting on, but on the Tops listing "black" is exactly
     * right. The category listing is where returning shoppers browse, and this is the evidence
     * that belongs on it. Computed with the same calculator and the same gates as the global set,
     * over the subset of purchases that count towards the category, so a single purchase in a
     * category still cannot invent a preference — confidence is measured on the subset.
     *
     * Bounded: categories are ranked by the purchase weight they carry and capped, and only
     * actionable attributes are stored, so a profile stays a small document on a store with
     * thousands of categories.
     *
     * @param array<int, array{values: array, weight: float, category_ids?: int[]}> $purchases
     * @param string[] $attributeCodes
     * @return array<string, array<string, array<string, mixed>>> category id => attribute code => affinity
     */
    private function categoryAffinities(array $purchases, array $attributeCodes, ?int $storeId): array
    {
        $byCategory = [];
        $weightByCategory = [];
        foreach ($purchases as $purchase) {
            $values = $purchase['values'] ?? [];
            unset($values['category']);
            if (!$values) {
                continue;
            }
            foreach ((array) ($purchase['category_ids'] ?? []) as $categoryId) {
                $categoryId = (int) $categoryId;
                if ($categoryId <= 0) {
                    continue;
                }
                $byCategory[$categoryId][] = ['values' => $values, 'weight' => (float) ($purchase['weight'] ?? 1.0)];
                $weightByCategory[$categoryId] = ($weightByCategory[$categoryId] ?? 0.0) + (float) ($purchase['weight'] ?? 1.0);
            }
        }
        if (!$byCategory) {
            return [];
        }
        arsort($weightByCategory);
        $minStrength = $this->config->getMinStrength($storeId);
        $minConfidence = $this->config->getMinConfidence($storeId);
        $cardinality = $this->history->getCatalogueCardinality($attributeCodes);
        $valueIds = $this->history->resolveValueIds($attributeCodes);
        $out = [];
        foreach (array_slice(array_keys($weightByCategory), 0, self::MAX_CATEGORY_AFFINITIES, true) as $categoryId) {
            $affinities = $this->calculator->calculate($byCategory[$categoryId], $cardinality);
            $stored = [];
            foreach ($affinities as $code => $affinity) {
                if (!$affinity->isActionable($minStrength, $minConfidence)) {
                    continue;
                }
                $data = $affinity->toArray();
                $data['actionable'] = true;
                $ids = [];
                foreach (array_keys($data['values']) as $label) {
                    if (isset($valueIds[$code][$label])) {
                        $ids[$label] = $valueIds[$code][$label];
                    }
                }
                $data['value_ids'] = $ids;
                $stored[$code] = $data;
            }
            if ($stored) {
                $out[(string) $categoryId] = $stored;
            }
        }
        return $out;
    }

    private function resolveFacts(array $facts, ?int $storeId): array
    {
        if (!$facts) {
            return $facts;
        }

        $map = $this->config->getFactAttributes($storeId);
        $codes = array_values(array_unique(array_values($map)));
        $optionIds = $codes ? $this->history->resolveValueIds($codes) : [];

        foreach ($facts as $name => $fact) {
            $code = $map[(string) $name] ?? null;
            $facts[$name]['attribute'] = $code;
            if ($code === null) {
                continue;
            }

            $wanted = $this->normalizer->canonical((string) ($fact['value'] ?? ''));
            foreach ($optionIds[$code] ?? [] as $label => $optionId) {
                if ($wanted !== '' && $this->normalizer->canonical((string) $label) === $wanted) {
                    $facts[$name]['label'] = (string) $label;
                    $facts[$name]['value_id'] = (int) $optionId;
                    break;
                }
            }
        }

        return $facts;
    }
}
