<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\AutoWeightTuner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run one auto-weight tuning step by hand and see exactly what it decided and why.
 *
 *   bin/magento fastmagento:personalization:tune
 *   bin/magento fastmagento:personalization:tune --window=14
 */
class TuneWeight extends Command
{
    public function __construct(
        private readonly State $appState,
        private readonly AutoWeightTuner $tuner,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:personalization:tune')
            ->setDescription('Run one auto-weight tuning step against the measured A/B conversion')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Days this record measures (1 keeps nights disjoint for pooling)', '1');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable $e) {
            // Already set — fine.
        }

        $decision = $this->tuner->tune(max(1, (int) $input->getOption('window')));

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>%s</info>  weight=%.3f  %s',
            strtoupper((string) $decision['action']),
            (float) $decision['weight'],
            (string) $decision['reason']
        ));
        if (($decision['sessions_personalized'] ?? 0) > 0 || ($decision['sessions_control'] ?? 0) > 0) {
            $output->writeln(sprintf(
                '  with personalisation: %d shopper(s), %d order(s), conv %s   without: %d shopper(s), %d order(s), conv %s',
                $decision['sessions_personalized'],
                $decision['orders_personalized'],
                $decision['conversion_personalized'] !== null ? sprintf('%.2f%%', $decision['conversion_personalized'] * 100) : '—',
                $decision['sessions_control'],
                $decision['orders_control'],
                $decision['conversion_control'] !== null ? sprintf('%.2f%%', $decision['conversion_control'] * 100) : '—'
            ));
        }
        $output->writeln('');

        return Command::SUCCESS;
    }
}
