<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * Caches a Bill History export per-bill instead of as a single blob, so no
 * cache write ever has to serialize more than one bill's data at a time.
 *
 * Each session has a small "index" entry (the ordered list of bill ids plus
 * a normalized-designator lookup) and one entry per bill, keyed by bill id.
 */
class BillHistoryCache
{
    public function __construct(
        protected readonly Repository $store,
        protected readonly ?int $ttl = null,
    ) {}

    public function hasIndex(string $session): bool
    {
        return $this->store->has($this->indexKey($session));
    }

    /**
     * Reassemble the full {export_date, total, session, bills} shape from
     * the cached index and per-bill entries. Returns null if the index is
     * missing, or if any per-bill entry it references has expired/evicted
     * independently — callers should treat that as a cache miss and resync
     * rather than serve a partial bill list.
     */
    public function all(string $session): ?array
    {
        $index = $this->store->get($this->indexKey($session));

        if (! is_array($index) || ! isset($index['bill_ids'], $index['export_date'], $index['total'])) {
            return null;
        }

        if ($index['bill_ids'] === []) {
            return $this->assembled($session, $index, []);
        }

        $keys = array_map(fn (string $id) => $this->billKey($session, $id), $index['bill_ids']);
        $values = $this->store->many($keys);

        $bills = [];
        foreach ($index['bill_ids'] as $i => $id) {
            $record = $values[$keys[$i]] ?? null;

            if (! is_array($record)) {
                return null;
            }

            $bills[] = $record;
        }

        return $this->assembled($session, $index, $bills);
    }

    /**
     * Look up a single bill's raw record by full id or by designator
     * (case/leading-zero insensitive), without materializing the full list.
     * Returns null if the identifier isn't recognized by the index, or if
     * the matching per-bill entry has expired independently of the index.
     */
    public function find(string $session, string $identifier): ?array
    {
        $index = $this->store->get($this->indexKey($session));

        if (! is_array($index) || ! isset($index['bill_ids'])) {
            return null;
        }

        $id = $this->resolveId($index, $identifier);

        if ($id === null) {
            return null;
        }

        $record = $this->store->get($this->billKey($session, $id));

        return is_array($record) ? $record : null;
    }

    /**
     * Write every bill from a parsed Bill History export as its own cache
     * entry, then write the session index last, so a reader can never see
     * an index pointing at bills that haven't been written yet.
     *
     * @param  array{export_date: string, total: int, session: string, bills: array<int, array>}  $parsed
     */
    public function put(string $session, array $parsed, ?int $ttl = null): void
    {
        $ttl ??= $this->ttl;

        $ids = [];
        $designatorMap = [];
        $values = [];

        foreach ($parsed['bills'] ?? [] as $bill) {
            $id = (string) ($bill['id'] ?? '');
            $ids[] = $id;
            $values[$this->billKey($session, $id)] = $bill;

            if (! empty($bill['designator'])) {
                $designatorMap[BillIdentifier::normalize((string) $bill['designator'])] = $id;
            }
        }

        if ($values !== []) {
            $this->store->putMany($values, $ttl);
        }

        $this->store->put($this->indexKey($session), [
            'export_date' => $parsed['export_date'] ?? '',
            'total' => $parsed['total'] ?? count($ids),
            'bill_ids' => $ids,
            'designator_map' => $designatorMap,
        ], $ttl);
    }

    /**
     * @return array{export_date: string, total: int, session: string, bills: array<int, array>}
     */
    protected function assembled(string $session, array $index, array $bills): array
    {
        return [
            'export_date' => $index['export_date'],
            'total' => $index['total'],
            'session' => $session,
            'bills' => $bills,
        ];
    }

    protected function resolveId(array $index, string $identifier): ?string
    {
        foreach ($index['bill_ids'] as $id) {
            if (strtoupper((string) $id) === strtoupper($identifier)) {
                return $id;
            }
        }

        $normalized = BillIdentifier::normalize($identifier);

        return $index['designator_map'][$normalized] ?? null;
    }

    protected function indexKey(string $session): string
    {
        return sprintf('palegis:bill-history:%s:index', $session);
    }

    protected function billKey(string $session, string $id): string
    {
        return sprintf('palegis:bill-history:%s:bill:%s', $session, $id);
    }
}
