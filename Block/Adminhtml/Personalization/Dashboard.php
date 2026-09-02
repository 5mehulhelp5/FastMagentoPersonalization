<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Block\Adminhtml\Personalization;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\AbReport;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig;

/**
 * Data for the personalisation dashboard. All reads, no writes, and every chart is drawn from
 * numbers this block hands over — the template renders inline SVG so the page needs no chart
 * library, no CDN, and works under any CSP.
 */
class Dashboard extends Template
{
    public function __construct(
        Context $context,
        private readonly AbReport $report,
        private readonly PersonalizationConfig $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getWindowDays(): int
    {
        $days = (int) $this->getRequest()->getParam('days', 30);

        return max(7, min(90, $days));
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return $this->report->summary($this->getWindowDays());
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    public function getDaily(): array
    {
        return $this->report->daily($this->getWindowDays());
    }

    /** @return array<int, array<string, mixed>> */
    public function getTuningHistory(): array
    {
        return $this->report->tuningHistory();
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return [
            'enabled' => $this->config->isApplied(),
            'weight_mode' => $this->config->getWeightMode(),
            'weight_factor' => $this->config->getWeightFactor(),
            'ab_enabled' => $this->config->isAbTestEnabled(),
        ];
    }

    /**
     * The pooled per-weight evidence — delegated to the report so this chart and the tuner's
     * decisions are drawn from one method and can never disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPerformanceByWeight(): array
    {
        return $this->report->performanceByWeight();
    }

    /**
     * Points for an SVG polyline, scaled into a width×height box.
     *
     * @param array<int, float|null> $values
     */
    public function polyline(array $values, float $max, int $width, int $height, int $pad = 4): string
    {
        $values = array_values($values);
        $n = count($values);
        if ($n === 0 || $max <= 0) {
            return '';
        }

        $points = [];
        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }
            $x = $n > 1 ? $pad + ($width - 2 * $pad) * $i / ($n - 1) : $width / 2;
            $y = $height - $pad - ($height - 2 * $pad) * min((float) $value, $max) / $max;
            $points[] = sprintf('%.1f,%.1f', $x, $y);
        }

        return implode(' ', $points);
    }
}
