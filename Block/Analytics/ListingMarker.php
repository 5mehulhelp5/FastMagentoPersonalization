<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Block\Analytics;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\Framework\View\Element\Template;

/**
 * Carries the product ids on a listing page into the DOM, so impressions do not depend on the theme.
 *
 * The client needs to know which DOM node is which product before it can say "this one was on
 * screen". On Hyvä that is free — its listing markup already carries `data-product-id`. On Luma,
 * and therefore on Breeze, it carries nothing of the kind: core's `product/list.phtml` has no such
 * attribute anywhere. Depending on it meant impressions were collected on exactly one of the three
 * themes this module supports, silently, with the other two reporting an empty denominator that
 * looks identical to a quiet catalogue.
 *
 * So the ids are published here instead, paired with the one thing every theme in existence renders
 * for a product: a link to it. The client matches links by path and observes their cards. Nothing
 * theme-specific survives.
 *
 * NO EXTRA QUERY. The collection is taken from the listing block that already loaded it, by name,
 * from the layout — never rebuilt. This block renders at the end of the body precisely so that
 * collection is already loaded by the time it is asked for, rather than this block being the thing
 * that triggers the load before the toolbar has had its say about paging.
 *
 * Page-level data, not shopper-level: it describes the products this URL is showing, which is the
 * same for everyone who receives this cached page — including when personalisation has reordered
 * it, since the reorder and this list come from the same render.
 */
class ListingMarker extends Template
{
    /** Listing blocks worth asking, in the order they are likely to exist. */
    private const LIST_BLOCKS = ['category.products.list', 'search_result_list'];

    /**
     * @return array<int, array{i: int, u: string}>
     */
    public function getListingProducts(): array
    {
        foreach (self::LIST_BLOCKS as $name) {
            $block = $this->getLayout()->getBlock($name);
            if (!$block instanceof ListProduct) {
                continue;
            }

            try {
                $collection = $block->getLoadedProductCollection();
            } catch (\Throwable $e) {
                continue;
            }

            $out = [];
            foreach ($collection as $product) {
                $id = (int) $product->getId();
                $url = (string) $product->getProductUrl();
                if ($id > 0 && $url !== '') {
                    // Path only. The host is noise to a same-origin match and would break the
                    // moment a store is reached over a different domain or scheme than it renders.
                    $path = (string) parse_url($url, PHP_URL_PATH);
                    $out[] = ['i' => $id, 'u' => $path !== '' ? $path : $url];
                }
            }

            if ($out) {
                return $out;
            }
        }

        return [];
    }
}
