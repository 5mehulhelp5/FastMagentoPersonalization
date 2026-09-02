<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ProductExposure;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Measure how well each product converts for the exposure it actually got.
 *
 * The answer to "which products are being buried because they have never had the chance to sell",
 * which raw sales counts cannot give and which PERSONALIZATION.md §5 needs before an exploration
 * slot can be built. Belongs next to a reindex or on a nightly cron: it is a fact about traffic, so
 * it goes stale as shoppers browse rather than as the catalogue changes.
 *
 *   bin/magento fastmagento:personalization:exposure
 *   bin/magento fastmagento:personalization:exposure --show=20
 */
class ExposureBuild extends Command
{
    public function __construct(
        private readonly State $appState,
        private readonly ProductExposure $exposure,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:personalization:exposure')
            ->setDescription('Measure conversion per impression, so a product that was never shown is not read as one that never sold')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Restrict to one store id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many products the impression aggregation may list', '5000')
            ->addOption('show', null, InputOption::VALUE_REQUIRED, 'Print the top N products by defensible rate');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Throwable $e) {
            // Already set — fine.
        }

        $storeId = $input->getOption('store') !== null ? (int) $input->getOption('store') : null;
        $limit = max(1, (int) $input->getOption('limit'));

        $written = $this->exposure->rebuild($storeId, $limit);
        $this->exposure->refresh();

        if ($written === 0) {
            $output->writeln('<comment>Nothing measured — no impressions have been recorded yet.</comment>');
            $output->writeln('<comment>  → Impressions come from the storefront bundle on a listing page, and only when</comment>');
            $output->writeln('<comment>    fastmagento/personalization/collect_events is on. Until some exist there is no</comment>');
            $output->writeln('<comment>    denominator, and no table is written: absence means "no opinion", where an empty</comment>');
            $output->writeln('<comment>    table would mean "measured, and every product is under-exposed".</comment>');

            return Command::SUCCESS;
        }

        $summary = $this->exposure->summary($storeId);
        $output->writeln('');
        $output->writeln(sprintf('<info>Measured %d store view%s</info>', $written, $written === 1 ? '' : 's'));

        if ($summary !== null) {
            $output->writeln(sprintf(
                '  %d product(s) shown, %s impression(s) since %s',
                $summary['products'],
                number_format($summary['impressions']),
                substr($summary['window_from'], 0, 10)
            ));
            $output->writeln(sprintf(
                '  %d judged (>= %d impressions); %d still under-exposed and deliberately unrated',
                $summary['judged'],
                ProductExposure::MIN_IMPRESSIONS,
                $summary['products'] - $summary['judged']
            ));
            if ($summary['unlisted_impressions'] > 0) {
                $output->writeln(sprintf(
                    '  <comment>%d impression(s) fell outside the top %d products and were NOT counted — '
                    . 're-run with a higher --limit</comment>',
                    $summary['unlisted_impressions'],
                    $limit
                ));
            }
        }

        $show = (int) $input->getOption('show');
        if ($show > 0) {
            $output->writeln('');
            $output->writeln('<info>Conversion per impression — defensible rate, strongest first</info>');
            $output->writeln('  product      shown    sold      rate   verdict');
            foreach ($this->exposure->describe($storeId, $show) as $productId => $row) {
                $output->writeln(sprintf(
                    '  #%-10d %5d  %6d  %8s   %s',
                    $productId,
                    $row['impressions'],
                    $row['units'],
                    $row['rate'] === null ? '—' : sprintf('%.4f', $row['rate']),
                    $row['rate'] === null
                        ? '<comment>not shown enough to judge — an exploration candidate</comment>'
                        : 'measured'
                ));
            }
        }

        $output->writeln('');

        return Command::SUCCESS;
    }
}
