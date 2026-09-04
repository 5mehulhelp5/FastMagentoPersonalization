<?php
declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig;

class PlpOrderMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => PersonalizationConfig::PLP_ORDER_PERSONALISED, 'label' => __('Position-aware personalised (default)')],
            ['value' => PersonalizationConfig::PLP_ORDER_POSITION, 'label' => __('Merchant position (personalisation breaks ties only)')],
        ];
    }
}
