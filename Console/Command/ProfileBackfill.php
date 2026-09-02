<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\FlagManager;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileBuilder;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Build a profile for every shopper who has ever ordered — resumably.
 *
 * The profile builder is per-customer by design, so the only thing missing for a store that
 * installs personalisation with ten years of order history is the loop. This is that loop.
 *
 * Resumable is not a nicety here. On a real catalogue this is a long-running job measured in tens
 * of minutes, and it is exactly the kind of command that gets run over SSH and loses its
 * connection at 80%. The cursor is persisted so a re-run continues instead of restarting; that
 * also makes it safe to schedule in slices ("an hour a night") rather than one enormous pass.
 *
 * Ordering is keyset, not OFFSET — `customer_id > cursor ORDER BY customer_id`. Two reasons: an
 * OFFSET walk over a large sales_order gets quadratically slower as it advances, and a stable key
 * means an interrupted run resumes at a well-defined point rather than a row number whose meaning
 * shifts when orders are placed mid-run.
 *
 * Reads order history and writes to the OpenSearch profile index only. It never touches the
 * request path and nothing it writes is read at query time yet — this milestone ships dark.
 *
 *   bin/magento fastmagento:profile:backfill                # full pass, from the start
 *   bin/magento fastmagento:profile:backfill --resume       # continue where the last run stopped
 *   bin/magento fastmagento:profile:backfill --limit=100 --dry-run
 */
class ProfileBackfill extends Command
{
    /** One shared definition — the cron rebuilds what this seeds, so they must not drift apart. */
    private const DEFAULT_ATTRIBUTES = PersonalizationConfig::DEFAULT_PROFILE_ATTRIBUTES;

    private const FLAG_CURSOR = 'fastmagento_profile_backfill_cursor';

    private const OPT_ATTRIBUTES = 'attributes';
    private const OPT_BATCH = 'batch';
    private const OPT_LIMIT = 'limit';
    private const OPT_FROM = 'from';
    private const OPT_RESUME = 'resume';
    private const OPT_RESTART = 'restart';
    private const OPT_STORE = 'store';
    private const OPT_DRY_RUN = 'dry-run';

    /** Set by the signal handler; the loop finishes the customer in flight, then stops cleanly. */
    private bool $stopRequested = false;

    public function __construct(
        private readonly State $appState,
        private readonly ResourceConnection $resource,
        private readonly ProfileBuilder $builder,
        private readonly ProfileRepository $repository,
        private readonly PersonalizationConfig $config,
        private readonly FlagManager $flagManager,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\EventHistoryProvider $events,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fastmagento:profile:backfill')
            ->setDescription('Build shopper profiles for every customer with order history (resumable)')
            ->addOption(self::OPT_ATTRIBUTES, null, InputOption::VALUE_REQUIRED, 'Attributes to profile', implode(',', self::DEFAULT_ATTRIBUTES))
            ->addOption(self::OPT_BATCH, null, InputOption::VALUE_REQUIRED, 'Customers per batch', '500')
            ->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'Stop after this many customers (0 = all)', '0')
            ->addOption(self::OPT_FROM, null, InputOption::VALUE_REQUIRED, 'Start after this customer id')
            ->addOption(self::OPT_RESUME, null, InputOption::VALUE_NONE, 'Continue from the stored cursor')
            ->addOption(self::OPT_RESTART, null, InputOption::VALUE_NONE, 'Clear the stored cursor and start from the beginning')
            ->addOption(self::OPT_STORE, null, InputOption::VALUE_REQUIRED, 'Restrict to one store id')
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Count what would be built without writing anything')
            ->addOption('skip-anonymous', null, InputOption::VALUE_NONE, 'Customers only — skip the guest-tier pass')
            ->addOption('anonymous-limit', null, InputOption::VALUE_REQUIRED, 'Most guest profiles to build in one run', '1000');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        } catch (\Throwable $e) {
            // Already set — fine.
        }

        $dryRun = (bool) $input->getOption(self::OPT_DRY_RUN);
        $storeId = $input->getOption(self::OPT_STORE) !== null
            ? (int) $input->getOption(self::OPT_STORE)
            : null;

        // Fail loudly rather than looping over every customer to write nothing. buildForCustomer()
        // returns null when the build switch is off, so without this check the command would spend
        // real time and exit 0 having done exactly nothing — the silent-failure shape this
        // module's doctor exists to eliminate.
        if (!$this->config->isBuildingProfiles($storeId)) {
            $output->writeln('<error>Profile building is disabled — this run would write nothing.</error>');
            $output->writeln('<comment>  → Enable Stores > Configuration > FastMagento > Personalisation > Build Shopper Profiles,</comment>');
            $output->writeln('<comment>    or: bin/magento config:set fastmagento/personalization/build_profiles 1</comment>');

            return Command::FAILURE;
        }

        $attributes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $input->getOption(self::OPT_ATTRIBUTES))
        )));
        if (!$attributes) {
            $output->writeln('<error>--attributes resolved to an empty list.</error>');

            return Command::INVALID;
        }

        $batchSize = max(1, (int) $input->getOption(self::OPT_BATCH));
        $limit = max(0, (int) $input->getOption(self::OPT_LIMIT));

        if ($input->getOption(self::OPT_RESTART)) {
            $this->flagManager->deleteFlag(self::FLAG_CURSOR);
            $output->writeln('<comment>Cursor cleared — starting from the beginning.</comment>');
        }

        $cursor = $this->resolveStartCursor($input, $output);

        // Create the index up front. Doing it here rather than relying on the first successful
        // write means a store whose customers all turn out to have unprofilable history still ends
        // with a valid empty index, which is what the doctor check expects to find.
        if (!$dryRun) {
            try {
                $this->repository->ensureIndex();
            } catch (\Throwable $e) {
                $output->writeln('<error>Could not create the profile index: ' . $e->getMessage() . '</error>');
                $output->writeln('<comment>  → Check the cluster is reachable: bin/magento fastmagento:doctor</comment>');

                return Command::FAILURE;
            }
        }

        $total = $this->countCandidates($storeId, $cursor);
        if ($total === 0) {
            $output->writeln('<comment>No customers with order history past the cursor — nothing to do.</comment>');

            return Command::SUCCESS;
        }

        $target = $limit > 0 ? min($limit, $total) : $total;

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Backfilling %d shopper profile%s</info>  attributes=%s  batch=%d%s%s',
            $target,
            $target === 1 ? '' : 's',
            implode(',', $attributes),
            $batchSize,
            $storeId !== null ? '  store=' . $storeId : '',
            $dryRun ? '  <comment>[DRY RUN]</comment>' : ''
        ));
        if ($cursor > 0) {
            $output->writeln(sprintf('<comment>Resuming after customer %d.</comment>', $cursor));
        }
        $output->writeln('');

        $this->installSignalHandlers();

        $processed = 0;
        $built = 0;
        $skipped = 0;
        $failed = 0;
        $started = microtime(true);

        while (!$this->stopRequested) {
            $remaining = $limit > 0 ? $limit - $processed : $batchSize;
            if ($remaining <= 0) {
                break;
            }

            $customerIds = $this->fetchBatch($storeId, $cursor, min($batchSize, $remaining));
            if (!$customerIds) {
                break;
            }

            foreach ($customerIds as $customerId) {
                if ($this->stopRequested) {
                    break;
                }

                try {
                    if ($dryRun) {
                        $skipped++;
                    } elseif ($this->builder->buildForCustomer($customerId, $attributes, $storeId) !== null) {
                        $built++;
                    } else {
                        // No usable history — a customer whose orders hold none of the profiled
                        // attributes. Normal, not an error.
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    // One unprofilable shopper must not sink a run that has hours of work behind
                    // it. Record it, keep the cursor moving, report the count at the end.
                    $failed++;
                    if ($output->isVerbose()) {
                        $output->writeln(sprintf(
                            '  <error>customer %d failed: %s</error>',
                            $customerId,
                            $e->getMessage()
                        ));
                    }
                }

                $cursor = $customerId;
                $processed++;
            }

            // Persist per batch, not per customer: the cursor write is a DB round trip, and
            // re-doing at most one batch after a crash is cheaper than paying for that round trip
            // on every single shopper. Profile writes are idempotent, so the overlap is harmless.
            if (!$dryRun) {
                $this->flagManager->saveFlag(self::FLAG_CURSOR, $cursor);
            }

            $output->writeln($this->progressLine($processed, $target, $built, $skipped, $failed, $started));
        }

        $elapsed = microtime(true) - $started;
        $output->writeln('');

        if ($this->stopRequested) {
            $output->writeln(sprintf(
                '<comment>Interrupted after %d customer(s). Cursor saved at %d.</comment>',
                $processed,
                $cursor
            ));
            $output->writeln('<comment>  → Continue with: bin/magento fastmagento:profile:backfill --resume</comment>');
        } elseif ($dryRun) {
            $output->writeln(sprintf(
                '<info>Dry run complete — %d customer(s) would be processed. Nothing was written.</info>',
                $processed
            ));
        } else {
            $output->writeln(sprintf(
                '<info>Backfill complete — %d built, %d skipped (no usable history)%s, in %s.</info>',
                $built,
                $skipped,
                $failed > 0 ? sprintf(', <error>%d failed</error>', $failed) : '',
                $this->duration($elapsed)
            ));
            // The guest tier. After the customers, not interleaved: this pass is driven by an
            // OpenSearch aggregation rather than the SQL cursor, so it neither advances nor
            // disturbs the resumable customer walk above.
            if (!$input->getOption('skip-anonymous')) {
                $anonBuilt = 0;
                $anonSkipped = 0;
                $anonIds = $this->events->activeAnonIds(
                    3,
                    max(1, (int) $input->getOption('anonymous-limit'))
                );
                foreach ($anonIds as $anonId) {
                    if ($this->stopRequested) {
                        break;
                    }
                    try {
                        if ($this->builder->buildForAnonymous($anonId, $attributes, $storeId) !== null) {
                            $anonBuilt++;
                        } else {
                            $anonSkipped++;
                        }
                    } catch (\Throwable $e) {
                        $anonSkipped++;
                    }
                }
                if ($anonIds) {
                    $output->writeln(sprintf(
                        '<info>Guest tier: %d anonymous profile(s) built, %d skipped, from %d active cookie(s).</info>',
                        $anonBuilt,
                        $anonSkipped,
                        count($anonIds)
                    ));
                }
            }

            // Make the writes visible before counting them — OpenSearch is near-real-time, and
            // without this the summary under-reports a run that in fact succeeded.
            $this->repository->refresh();
            $output->writeln(sprintf('<info>%d profile(s) now in the index.</info>', $this->repository->count()));

            // A completed pass leaves no cursor: the next run without --resume is a fresh full
            // rebuild, which is what "run the backfill again" should mean.
            if ($limit === 0) {
                $this->flagManager->deleteFlag(self::FLAG_CURSOR);
            }
        }
        $output->writeln('');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * --from beats --resume beats a cold start. Explicit beats remembered.
     */
    private function resolveStartCursor(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption(self::OPT_FROM) !== null) {
            return max(0, (int) $input->getOption(self::OPT_FROM));
        }

        if ($input->getOption(self::OPT_RESUME)) {
            $stored = $this->flagManager->getFlagData(self::FLAG_CURSOR);
            if ($stored === null) {
                $output->writeln('<comment>No stored cursor — starting from the beginning.</comment>');

                return 0;
            }

            return max(0, (int) $stored);
        }

        // A cold start with a cursor still on disk means the last run was interrupted. Say so
        // rather than silently redoing work the operator thinks is already done.
        $stored = $this->flagManager->getFlagData(self::FLAG_CURSOR);
        if ($stored !== null && (int) $stored > 0) {
            $output->writeln(sprintf(
                '<comment>A previous run stopped at customer %d. Starting from the beginning anyway — '
                . 'pass --resume to continue from there instead.</comment>',
                (int) $stored
            ));
        }

        return 0;
    }

    /**
     * Customers who have actually ordered. Profiling anyone else is a guaranteed no-op, so they
     * are excluded here rather than discovered one wasted build at a time.
     *
     * @return int[]
     */
    private function fetchBatch(?int $storeId, int $cursor, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from($this->resource->getTableName('sales_order'), ['customer_id'])
            ->where('customer_id IS NOT NULL')
            ->where('customer_id > ?', $cursor)
            ->order('customer_id ASC')
            ->limit($limit);

        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    private function countCandidates(?int $storeId, int $cursor): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('sales_order'),
                ['n' => new \Zend_Db_Expr('COUNT(DISTINCT customer_id)')]
            )
            ->where('customer_id IS NOT NULL')
            ->where('customer_id > ?', $cursor);

        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }

        return (int) $connection->fetchOne($select);
    }

    private function progressLine(
        int $processed,
        int $target,
        int $built,
        int $skipped,
        int $failed,
        float $started
    ): string {
        $elapsed = microtime(true) - $started;
        $rate = $elapsed > 0 ? $processed / $elapsed : 0.0;
        $remaining = max(0, $target - $processed);
        $eta = $rate > 0 ? $remaining / $rate : 0.0;

        return sprintf(
            '  %6d/%-6d  %3d%%  built=%-6d skipped=%-6d%s  %.1f/s  ETA %s',
            $processed,
            $target,
            $target > 0 ? (int) round($processed / $target * 100) : 100,
            $built,
            $skipped,
            $failed > 0 ? sprintf(' failed=%-4d', $failed) : '',
            $rate,
            $this->duration($eta)
        );
    }

    private function duration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%ds', (int) round($seconds));
        }
        if ($seconds < 3600) {
            return sprintf('%dm%02ds', (int) ($seconds / 60), (int) fmod($seconds, 60));
        }

        return sprintf('%dh%02dm', (int) ($seconds / 3600), (int) (fmod($seconds, 3600) / 60));
    }

    /**
     * Stop at the next customer boundary on Ctrl-C or SIGTERM, so the cursor is saved and the run
     * is resumable. Without this, the interrupt that this command is built to survive is precisely
     * the one that loses the batch in flight.
     *
     * pcntl is not guaranteed to be present in a PHP CLI build; when it is absent the command
     * still works, an interrupt just costs the current batch.
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $handler = function (): void {
            $this->stopRequested = true;
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }
}
