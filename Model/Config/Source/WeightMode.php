<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The four positions of the one weight dial. `auto_weight_value` — the number Auto resolves to —
 * is owned by the nightly tuner and deliberately has no admin field: a hand-edited "auto" value is
 * a contradiction the UI should not offer.
 */
class WeightMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'less', 'label' => __('Less — half strength')],
            ['value' => 'normal', 'label' => __('Normal')],
            ['value' => 'more', 'label' => __('More — near double')],
            ['value' => 'auto', 'label' => __('Auto — self-tunes from measured conversion')],
        ];
    }
}
