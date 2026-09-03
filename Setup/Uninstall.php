<?php
declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Setup;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use ParkkTech\FastMagentoPersonalization\Model\IndexNames;
use Psr\Log\LoggerInterface;

/**
 * `module:uninstall --remove-data ParkkTech_FastMagentoPersonalization`: removes the five
 * OpenSearch indices this module owns (profiles, events, value discrimination, product
 * exposure, tuning), its configuration, its cron schedule rows and its flags. Each step is
 * failure-tolerant so an unreachable cluster never blocks the database clean-up.
 */
class Uninstall implements UninstallInterface
{
    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly IndexNames $indexNames,
        private readonly LoggerInterface $logger
    ) {
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        try {
            $client = $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
            foreach ([
                $this->indexNames->getUserProfileIndexName(),
                $this->indexNames->getEventIndexName(),
                $this->indexNames->getValueDiscriminationIndexName(),
                $this->indexNames->getProductExposureIndexName(),
                $this->indexNames->getTuningIndexName(),
            ] as $index) {
                if ($index !== '' && $client->indexExists($index)) {
                    $client->deleteIndex($index);
                    $this->logger->info('[FastMagento Personalisation] uninstall: deleted index ' . $index);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[FastMagento Personalisation] uninstall: could not delete indices: ' . $e->getMessage());
        }

        $setup->startSetup();
        $connection = $setup->getConnection();
        try {
            $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'fastmagento/personalization/%']);
            $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'fastmagento/event/%']);
            $connection->delete($setup->getTable('cron_schedule'), ['job_code LIKE ?' => 'fastmagento_personalization_%']);
            $connection->delete($setup->getTable('flag'), ['flag_code LIKE ?' => 'fastmagento_personalization_%']);
            $connection->delete($setup->getTable('flag'), ['flag_code LIKE ?' => 'fastmagento_profile_%']);
            // Schema patches are never reverted by Magento; forget them so a reinstall re-applies.
            // (backslashes doubled twice: once for PHP, once for LIKE's own escaping)
            $connection->delete($setup->getTable('patch_list'), ['patch_name LIKE ?' => 'ParkkTech\\\\FastMagentoPersonalization\\\\Setup\\\\Patch\\\\%']);
        } catch (\Throwable $e) {
            $this->logger->error('[FastMagento Personalisation] uninstall: database clean-up failed: ' . $e->getMessage());
        }
        $setup->endSetup();
    }
}
