<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Measure how much each attribute VALUE actually separates the catalogue.
 *
 * Belongs next to a reindex, not next to a backfill: it is a fact about the catalogue, and it goes
 * stale when the catalogue changes rather than when shoppers do.
 *
 *   bin/magento fastmagento:personalization:discrimination
 *   bin/magento fastmagento:personalization:discrimination --show=size
 */
class DiscriminationBuild extends Command
{
    public function __construct(
        private readonly State $appState,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileAttributes $profileAttributes,
        private readonly ValueDiscrimination $discrimination,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:personalization:discrimination')
            ->setDescription('Measure per-value catalogue discrimination (IDF) used to gate personalisation boosts')
            ->addOption('attributes', null, InputOption::VALUE_REQUIRED, 'Attributes to measure (blank = the profiled attributes plus category)', '')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'With --show: print the table as gated on this category listing')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Restrict to one store id')
            ->addOption('show', null, InputOption::VALUE_REQUIRED, 'Print the measured table for one attribute')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Which index to print: native (Magento, option ids) or serving (FastMagento, labels)', ValueDiscrimination::TARGET_NATIVE);

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Throwable $e) {
            // Already set — fine.
        }

        $attributes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $input->getOption('attributes'))
        )));
        if (!$attributes) {
            $attributes = $this->profileAttributes->forDiscrimination();
        }
        if (!$attributes) {
            $output->writeln('<error>--attributes resolved to an empty list.</error>');

            return Command::INVALID;
        }

        $storeId = $input->getOption('store') !== null ? (int) $input->getOption('store') : null;

        $built = $this->discrimination->rebuild($attributes, $storeId);
        $this->discrimination->refresh();

        if ($built === 0) {
            $output->writeln('<error>Measured nothing.</error>');
            $output->writeln('<comment>  → Magento\'s catalogue search index is the source. Reindex it first:</comment>');
            $output->writeln('<comment>    bin/magento indexer:reindex catalogsearch_fulltext</comment>');

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Measured %d store view%s: %s</info>',
            $built,
            $built === 1 ? '' : 's',
            implode(', ', $attributes)
        ));
        foreach ([ValueDiscrimination::TARGET_NATIVE, ValueDiscrimination::TARGET_SERVING] as $t) {
            $docs = $this->discrimination->getTotalDocs($t, $storeId);
            $output->writeln(sprintf(
                '  %-8s %s',
                $t,
                $docs > 0 ? sprintf('%d document(s)', $docs) : '<comment>not measured</comment>'
            ));
        }

        $output->writeln(sprintf(
            '  %-8s %d categor%s with %d+ products gated on their own population',
            'category',
            $this->discrimination->getCategoriesMeasured($storeId),
            $this->discrimination->getCategoriesMeasured($storeId) === 1 ? 'y' : 'ies',
            ValueDiscrimination::MIN_CATEGORY_DOCS
        ));

        $show = (string) $input->getOption('show');
        if ($show !== '') {
            $categoryId = $input->getOption('category') !== null ? (int) $input->getOption('category') : null;
            $this->renderTable($output, $show, (string) $input->getOption('target'), $storeId, $categoryId);
        }

        $output->writeln('');

        return Command::SUCCESS;
    }

    private function renderTable(
        OutputInterface $output,
        string $attributeCode,
        string $target,
        ?int $storeId,
        ?int $categoryId = null
    ): void {
        if ($categoryId !== null && !$this->discrimination->hasCategorySection($categoryId, $storeId)) {
            $output->writeln(sprintf(
                '<comment>Category %d has fewer than %d products, so listings there are gated on the store-wide table.</comment>',
                $categoryId,
                ValueDiscrimination::MIN_CATEGORY_DOCS
            ));
        }
        $rows = $this->discrimination->describe($attributeCode, $target, $storeId, $categoryId);
        if (!$rows) {
            $output->writeln(sprintf(
                '<comment>Nothing measured for "%s" on target "%s".</comment>',
                $attributeCode,
                $target
            ));

            return;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>%s — per value on the %s index, strongest first</info>',
            $attributeCode,
            $target
        ));
        $output->writeln('  <comment>value        docs    share      idf   verdict</comment>');

        foreach ($rows as $valueId => $row) {
            $discriminating = $row['share'] > 0.0 && $row['share'] <= ValueDiscrimination::NEAR_UNIFORM_SHARE;
            $output->writeln(sprintf(
                '  %-10s %5d  %6.1f%%  %7.2f   %s',
                $valueId,
                $row['docs'],
                $row['share'] * 100,
                $row['idf'],
                $discriminating
                    ? '<info>separates the catalogue</info>'
                    : '<comment>too common to reorder anything</comment>'
            ));
        }
    }
}
