<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Link\Product\Collection;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Plugin\LinkProductCollectionPlugin;

/**
 * Personalise the order of a Related / Up-sell / Cross-sell row for the current shopper.
 *
 * Decorates core's LinkProductCollectionPlugin::orderForDisplay() seam. Re-order only, never add,
 * never drop: the ids stay exactly the ids the merchant linked; only their sequence can change,
 * and only where the shopper has an actionable, discriminating preference. Costs no query — the
 * documents are already in hand.
 *
 * Returns the input unchanged on any doubt at all — personalisation off, no profile, nothing past
 * the gates, or any error. A recommendation row that renders in the merchant's order is a
 * perfectly good row; one that has quietly lost or gained a product is a bug.
 */
class LinkProductCollectionPersonalizePlugin
{
    public function __construct(
        private readonly \ParkkTech\FastMagento\Model\Personalization\QueryPersonalizer $personalizer,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param int[] $result the order core decided (merchant order)
     * @param int[] $ids
     * @param array<int, array<string, mixed>> $docs
     * @return int[]
     */
    public function afterOrderForDisplay(
        LinkProductCollectionPlugin $subject,
        array $result,
        array $ids,
        array $docs,
        Collection $collectionSubject
    ): array {
        try {
            $ordered = [];
            foreach ($result as $id) {
                if (isset($docs[$id])) {
                    $ordered[] = ['__id' => $id] + $docs[$id];
                }
            }
            if (count($ordered) < 2) {
                return $result;
            }

            $reordered = $this->personalizer->reorderDocuments(
                $ordered,
                \ParkkTech\FastMagento\Model\Personalization\PersonalizationConfig::SURFACE_RECOMMENDATIONS,
                (int) $this->storeManager->getStore()->getId()
            );
            $newIds = [];
            foreach ($reordered as $doc) {
                if (isset($doc['__id'])) {
                    $newIds[] = (int) $doc['__id'];
                }
            }

            // Belt and braces: only accept the new order if it is a permutation of the old set.
            // Anything else means a bug upstream, and the merchant's row is not the place to find
            // out.
            $original = $result;
            $check = $newIds;
            sort($original);
            sort($check);
            if ($original !== $check) {
                return $result;
            }

            return $newIds;
        } catch (\Throwable $e) {
            return $result;
        }
    }
}
