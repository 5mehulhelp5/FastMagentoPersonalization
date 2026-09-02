<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Cron;

use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\AutoWeightTuner;

/**
 * The nightly auto-weight step. All judgment lives in {@see AutoWeightTuner}; this only shows up.
 */
class TuneWeight
{
    public function __construct(
        private readonly AutoWeightTuner $tuner,
        private readonly WriteLog $writeLog
    ) {
    }

    public function execute(): void
    {
        try {
            $this->tuner->tune();
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('Auto-weight tuning failed: ' . $e->getMessage());
        }
    }
}
