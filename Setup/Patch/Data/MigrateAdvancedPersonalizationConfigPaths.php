<?php
declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Rescue Advanced-group personalisation settings stranded at a dead config path.
 *
 * Before 2026-08-19, `etc/adminhtml/system.xml` nested the fourteen Advanced personalisation
 * fields inside `<group id="advanced">` WITHOUT a `<config_path>`, so an Admin save wrote to
 * `fastmagento/personalization/advanced/<field>` — while `PersonalizationConfig` reads the flat
 * `fastmagento/personalization/<field>` with no exception. The save reported success and the
 * storefront never saw the value. (Found by the v1 M2 acceptance audit; the fields now carry an
 * explicit `<config_path>` pointing at the flat path.)
 *
 * This patch moves any value a merchant saved to the nested path over to the flat path the
 * runtime reads, then deletes the nested row:
 *
 *  - A nested row migrates ONLY when no flat row exists for the same field at the same
 *    scope/scope_id. A flat row that already exists is live, working configuration (CLI-written
 *    or post-fix Admin-written) and always wins — the stranded value must not clobber it.
 *  - Nested rows are deleted afterwards in both cases: once the fields carry `<config_path>`,
 *    nothing can ever read or write the nested path again, so leftovers are pure confusion.
 *
 * Idempotent by construction (a second run finds no nested rows), so no PatchRevertableInterface:
 * reverting would re-strand values at a path nothing reads.
 */
class MigrateAdvancedPersonalizationConfigPaths implements DataPatchInterface
{
    private const NESTED_PREFIX = 'fastmagento/personalization/advanced/';
    private const FLAT_PREFIX = 'fastmagento/personalization/';

    /** Keep in sync with the `<group id="advanced">` fields in etc/adminhtml/system.xml. */
    private const FIELDS = [
        'build_profiles',
        'collect_events',
        'impact_plp',
        'impact_search',
        'impact_recommendations',
        'preselect_variant',
        'max_boost_terms',
        'fact_attributes',
        'fact_weight',
        'event_weight',
        'exploration_percent',
        'min_strength',
        'min_confidence',
        'half_life_days',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $migrated = 0;
        $discarded = 0;

        foreach (self::FIELDS as $field) {
            $nestedPath = self::NESTED_PREFIX . $field;
            $flatPath = self::FLAT_PREFIX . $field;

            $nestedRows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['config_id', 'scope', 'scope_id', 'value'])
                    ->where('path = ?', $nestedPath)
            );

            foreach ($nestedRows as $row) {
                $flatExists = (int) $connection->fetchOne(
                    $connection->select()
                        ->from($table, 'COUNT(*)')
                        ->where('path = ?', $flatPath)
                        ->where('scope = ?', $row['scope'])
                        ->where('scope_id = ?', $row['scope_id'])
                );

                if ($flatExists === 0) {
                    $connection->insert($table, [
                        'scope' => $row['scope'],
                        'scope_id' => $row['scope_id'],
                        'path' => $flatPath,
                        'value' => $row['value'],
                    ]);
                    $migrated++;
                    $this->logger->info(sprintf(
                        'FastMagento: migrated stranded Admin value %s (%s/%s) to %s',
                        $nestedPath,
                        $row['scope'],
                        $row['scope_id'],
                        $flatPath
                    ));
                } else {
                    $discarded++;
                    $this->logger->info(sprintf(
                        'FastMagento: discarded stranded Admin value %s (%s/%s) — a live value already exists at %s',
                        $nestedPath,
                        $row['scope'],
                        $row['scope_id'],
                        $flatPath
                    ));
                }

                $connection->delete($table, ['config_id = ?' => $row['config_id']]);
            }
        }

        if ($migrated > 0) {
            // Migrated values must become visible to the storefront without a manual flush.
            $this->cacheTypeList->cleanType('config');
        }

        $this->logger->info(sprintf(
            'FastMagento: advanced config-path migration complete — %d migrated, %d discarded (flat value won)',
            $migrated,
            $discarded
        ));

        $this->moduleDataSetup->endSetup();
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * The patch first shipped in FastMagento core; patch_list records it under that class name.
     * Naming it here makes the applier treat the row as this patch, so an already-migrated store
     * does not re-run it after the package split.
     */
    public function getAliases(): array
    {
        return ['ParkkTech\\FastMagento\\Setup\\Patch\\Data\\MigrateAdvancedPersonalizationConfigPaths'];
    }
}
