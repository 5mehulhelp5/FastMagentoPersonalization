<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

/**
 * Choose which variant of a configurable product to show a shopper first.
 *
 * A shopper who has bought size L in black four times should not have to re-pick size L in black.
 * The swatch is the one place personalisation can save a real interaction rather than merely
 * re-order a page.
 *
 * WHY THIS IGNORES THE CATALOGUE-WIDE DISCRIMINATION GATE — and why that is not an inconsistency.
 * {@see ValueDiscrimination} exists because boosting a value carried by half the catalogue cannot
 * re-order a listing: every product matches, every score shifts equally, nothing moves. That is a
 * statement about a CANDIDATE SET of ~187 products. Here the candidate set is the handful of
 * variants of ONE product, and within it "size L" is one option in five — maximally discriminating,
 * and the single most useful thing the profile knows. Size scores 0.65 catalogue-wide and is
 * correctly refused on a listing; the same value picks the right variant here. The gate was always
 * relative to the set being chosen from, and this is a different set.
 *
 * So this applies the SHOPPER-side gates only — concentration and confidence, meaning "is this a
 * real preference" — and then lets the product's own variants decide the rest.
 *
 * Costs nothing: the children, their attribute values and their stock all travel on the product's
 * OpenSearch document, which the product page has already fetched.
 *
 * It preselects; it never restricts. Every other variant remains selectable, the shopper's own
 * choice in the URL still wins (the theme applies query-string selections after these), and an
 * out-of-stock variant is never chosen.
 */
class VariantPreselector
{
    public function __construct(
        private readonly PersonalizationConfig $config,
        private readonly ProfileRepository $repository,
        private readonly RequestScope $requestScope,
        private readonly AbTest $abTest
    ) {
    }

    /**
     * The super-attribute selection to apply, as [attributeId => optionId].
     *
     * Empty when there is nothing worth preselecting — which is most shoppers, and is the correct
     * answer for them.
     *
     * @param array<string, mixed> $productDoc the product's OpenSearch document
     * @return array<int, string>
     */
    public function selectionFor(array $productDoc, ?int $storeId = null, ?int $customerId = null): array
    {
        if (!$this->config->isApplied($storeId) || !$this->config->isPreselectingVariants($storeId)) {
            return [];
        }

        $children = $productDoc['child_products'] ?? null;
        if (!is_array($children) || count($children) < 2) {
            return [];
        }

        $weights = $this->valueWeights($storeId, $customerId);
        if (!$weights) {
            return [];
        }

        $optionToAttribute = $this->optionToAttributeMap($productDoc);
        if (!$optionToAttribute) {
            return [];
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($children as $child) {
            // Never preselect something the shopper cannot buy. An out-of-stock default is worse
            // than no default at all: it reads as "we have nothing for you".
            if (empty($child['is_in_stock'])) {
                continue;
            }

            $attributes = $child['custom_attributes'] ?? null;
            if (!is_array($attributes)) {
                continue;
            }

            $score = 0.0;
            foreach ($attributes as $code => $optionId) {
                $score += $weights[(string) $code][(string) $optionId] ?? 0.0;
            }

            // Strictly greater, so ties keep the FIRST child — which is the order the catalogue
            // already presents and therefore the merchant's implicit default.
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $child;
            }
        }

        if ($best === null || $bestScore <= 0.0) {
            return [];
        }

        // Emit ONLY the attributes this shopper actually has a preference for.
        //
        // The winning child necessarily has a value for every attribute, but that does not mean the
        // shopper expressed one. Customer 2 buys black consistently and buys every size about
        // equally; setting their colour is helpful, and setting a size they never chose is
        // inventing a preference — on apparel, one that could put the wrong size in a basket. A
        // fabricated default is worse than no default, because it looks like a decision.
        //
        // Partial selections are supported all the way down: `defaultValues` is keyed per attribute
        // id, so the colour arrives chosen and the size stays for the shopper to pick.
        $selection = [];
        foreach ($best['custom_attributes'] ?? [] as $code => $optionId) {
            $optionId = (string) $optionId;
            if (!isset($optionToAttribute[$optionId])) {
                continue;
            }
            if (($weights[(string) $code][$optionId] ?? 0.0) <= 0.0) {
                continue;
            }
            $selection[$optionToAttribute[$optionId]] = $optionId;
        }

        return $selection;
    }

    /**
     * The shopper's weight for each attribute value, keyed by code then option id.
     *
     * Only affinities that pass the shopper-side gates contribute — a preference has to be real
     * before it is worth acting on, even when acting on it is this cheap.
     *
     * @return array<string, array<string, float>>
     */
    private function valueWeights(?int $storeId, ?int $customerId): array
    {
        $profile = $this->loadProfile($customerId);
        if ($profile === null) {
            return [];
        }

        $out = [];
        foreach ($profile['affinities'] ?? [] as $code => $affinity) {
            if (empty($affinity['actionable'])) {
                continue;
            }

            $strength = (float) ($affinity['strength'] ?? 0.0);
            $confidence = (float) ($affinity['confidence'] ?? 0.0);
            $valueIds = $affinity['value_ids'] ?? [];

            foreach ($affinity['values'] ?? [] as $label => $share) {
                $optionId = $valueIds[(string) $label] ?? null;
                if ($optionId === null) {
                    continue;
                }
                $out[(string) $code][(string) $optionId] = (float) $share * $strength * $confidence;
            }
        }

        return $out;
    }

    /**
     * option id => configurable attribute id, read off the product's own option blocks.
     *
     * Derived from the document rather than looked up, so this costs no query. The blocks are keyed
     * `configurable_options_{parentId}` and their `options_map` keys carry the value index after a
     * colon (`"2:171"`), which is the same option id the children report.
     *
     * @param array<string, mixed> $productDoc
     * @return array<string, int>
     */
    private function optionToAttributeMap(array $productDoc): array
    {
        $map = [];

        foreach ($productDoc as $key => $blocks) {
            if (strpos((string) $key, 'configurable_options_') !== 0 || !is_array($blocks)) {
                continue;
            }

            foreach ($blocks as $block) {
                $attributeId = (int) ($block['attribute_id'] ?? 0);
                if ($attributeId <= 0 || empty($block['options_map']) || !is_array($block['options_map'])) {
                    continue;
                }
                foreach ($block['options_map'] as $option) {
                    $valueIndex = $option['value_index'] ?? null;
                    if ($valueIndex !== null) {
                        $map[(string) $valueIndex] = $attributeId;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadProfile(?int $customerId): ?array
    {
        if ($customerId === null && !$this->abTest->shouldPersonalize()) {
            // Control arm (production always passes null; the CLI's explicit id bypasses the test
            // so an operator can always inspect what WOULD be preselected). No profile means no
            // preselection and no cache-signature contribution — true control.
            return null;
        }

        $customerId = $customerId ?? $this->requestScope->getCustomerId();
        if ($customerId !== null && $customerId > 0) {
            return $this->repository->get(ProfileRepository::idForCustomer((int) $customerId));
        }

        // The guest tier. A returning guest who has searched "orange hoodie" all week deserves the
        // orange swatch preselected exactly as much as a customer would — the preference is theirs,
        // stated on this browser, and needs no account to be true.
        $anonId = $this->requestScope->getAnonId();
        if ($anonId === null || $anonId === '') {
            return null;
        }

        return $this->repository->get(ProfileRepository::idForAnonymous($anonId));
    }
}
