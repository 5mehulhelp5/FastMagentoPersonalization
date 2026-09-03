<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * Read and write per-shopper profiles in OpenSearch.
 *
 * The read path is the one with a latency budget: a single `get` by document id, memoised for the
 * request. Not a search — a query would be an order of magnitude more expensive for something every
 * personalised surface on the page needs exactly once.
 *
 * Everything degrades to "no profile" rather than an error. A missing index, an unreachable
 * cluster or a malformed document must leave the storefront rendering exactly as it does today;
 * that is the same rule the rest of the module follows, and it matters more here because
 * personalisation is optional by definition.
 */
class ProfileRepository
{
    /** @var array<string, array<string, mixed>|null> per-request memo, keyed by profile id */
    private array $memo = [];

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $config,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\IndexNames $indexNames,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * Stable document id. Keeping the tier in the key means a guest profile and a customer profile
     * can coexist for the same person mid-stitch, which is exactly what the merge needs.
     */
    public static function idForCustomer(int $customerId): string
    {
        return 'cust:' . $customerId;
    }

    public static function idForAnonymous(string $anonId): string
    {
        return 'anon:' . $anonId;
    }

    /**
     * @return array<string, mixed>|null null when there is no profile, for any reason
     */
    public function get(string $profileId): ?array
    {
        if (array_key_exists($profileId, $this->memo)) {
            return $this->memo[$profileId];
        }

        $profile = null;
        try {
            $response = $this->client()->getOpenSearchClient()->get([
                'index' => $this->indexNames->getUserProfileIndexName(),
                'id' => $profileId,
            ]);
            if (!empty($response['found']) && isset($response['_source'])) {
                $profile = $response['_source'];
            }
        } catch (\Throwable $e) {
            // A missing index or a missing document are both normal — most shoppers have no
            // profile — so this is not worth logging on every request. Anything else is the
            // cluster's problem and the storefront should not care.
            $profile = null;
        }

        return $this->memo[$profileId] = $profile;
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function save(string $profileId, array $profile): bool
    {
        try {
            $this->ensureIndex();
            $this->client()->getOpenSearchClient()->index([
                'index' => $this->indexNames->getUserProfileIndexName(),
                'id' => $profileId,
                'body' => $profile,
            ]);
            unset($this->memo[$profileId]);

            return true;
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] profile save failed for ' . $profileId . ': ' . $e->getMessage()
            );

            return false;
        }
    }

    public function delete(string $profileId): bool
    {
        try {
            $this->client()->getOpenSearchClient()->delete([
                'index' => $this->indexNames->getUserProfileIndexName(),
                'id' => $profileId,
            ]);
            unset($this->memo[$profileId]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function indexExists(): bool
    {
        try {
            return (bool) $this->client()->indexExists($this->indexNames->getUserProfileIndexName());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Make everything written so far visible to `count` and search.
     *
     * OpenSearch indexes are near-real-time, not real-time: a document is durable the moment
     * `index` returns but does not enter the searchable view until the next refresh (1s by
     * default). Counting immediately after a bulk write therefore under-reports — a backfill that
     * had just written six profiles reported zero, which reads as total failure when nothing at
     * all was wrong.
     *
     * Call this once at the end of a write run, never per document: a refresh forces a segment
     * flush and doing it per write would dominate the cost of the run.
     */
    public function refresh(): void
    {
        try {
            $this->client()->getOpenSearchClient()->indices()->refresh([
                'index' => $this->indexNames->getUserProfileIndexName(),
            ]);
        } catch (\Throwable $e) {
            // Nothing to refresh, or the cluster is unhappy — the caller only wanted an accurate
            // count, and a stale count is not worth failing a completed run over.
        }
    }

    public function count(): int
    {
        try {
            $response = $this->client()->getOpenSearchClient()->count([
                'index' => $this->indexNames->getUserProfileIndexName(),
            ]);

            return (int) ($response['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Create the index if it is absent. Called on write, so a merchant never has to remember a
     * setup step — the first profile build brings the index with it.
     */
    /**
     * How many guest-tier profiles exist, or null when the index cannot be asked.
     *
     * Prefix query on the id rather than a stored field, so it needs no mapping change and counts
     * every historical document correctly.
     */
    public function countAnonymous(): ?int
    {
        try {
            $response = $this->client()->getOpenSearchClient()->count([
                'index' => $this->indexNames->getUserProfileIndexName(),
                'body' => ['query' => ['prefix' => ['profile_id' => 'anon:']]],
            ]);

            return (int) ($response['count'] ?? 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function ensureIndex(): void
    {
        $indexName = $this->indexNames->getUserProfileIndexName();
        $client = $this->client();

        if ($client->indexExists($indexName)) {
            return;
        }

        $client->createIndex($indexName, $this->buildMapping());
    }

    /**
     * The mapping this version of the module expects the profile index to have.
     *
     * Public so the doctor can compare it against what is actually deployed. An index created by
     * an older release keeps its original mapping forever — OpenSearch will not retrofit new
     * fields into an existing one — so "the index exists" and "the index is current" are two
     * genuinely different questions.
     *
     * @return array<string, mixed>
     */
    public function getExpectedMapping(): array
    {
        return $this->buildMapping();
    }

    /**
     * The mapping the cluster actually holds, or null when the index is absent or unreadable.
     *
     * @return array<string, mixed>|null
     */
    public function getLiveMapping(): ?array
    {
        $indexName = $this->indexNames->getUserProfileIndexName();
        try {
            $response = $this->client()->getOpenSearchClient()->indices()->getMapping([
                'index' => $indexName,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        // The response is keyed by concrete index name, which differs from the requested name when
        // an alias is in play — so take the first entry rather than looking up by our own string.
        $first = is_array($response) ? reset($response) : null;

        return is_array($first) && isset($first['mappings']) && is_array($first['mappings'])
            ? $first['mappings']
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMapping(): array
    {
        return [
            'settings' => [
                // Profiles are read by id and never searched, so one shard is enough and keeps the
                // get path as short as possible. Replicas are the operator's call.
                'number_of_shards' => 1,
            ],
            'mappings' => [
                'properties' => [
                    'profile_id' => ['type' => 'keyword'],
                    'customer_id' => ['type' => 'long'],
                    'anon_ids' => ['type' => 'keyword'],
                    'store_id' => ['type' => 'short'],
                    'updated_at' => ['type' => 'date'],
                    'observations' => ['type' => 'integer'],

                    // Affinities are stored as an object keyed by attribute code. `enabled: false`
                    // means OpenSearch stores and returns it verbatim without indexing it — we
                    // never query BY a profile, we only fetch one, so indexing it would cost
                    // mapping churn (every new attribute code adds a field) for no benefit.
                    'affinities' => ['type' => 'object', 'enabled' => false],
                    'facts' => ['type' => 'object', 'enabled' => false],
                    'negative' => ['type' => 'object', 'enabled' => false],
                    'traits' => ['type' => 'object', 'enabled' => false],
                    'price_band' => ['type' => 'object', 'enabled' => false],
                ],
            ],
        ];
    }

    private function client()
    {
        return $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
    }
}
