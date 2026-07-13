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
        protected readonly int $chunkSize = 250,
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
     *
     * For a large session, prefer {@see each()} when every bill needs to be
     * transformed anyway (e.g. into DTOs) — it streams in chunks instead of
     * requiring the full raw bill list and its transformed output to be
     * resident in memory at the same time.
     */
    public function all(string $session): ?array
    {
        $index = $this->store->get($this->indexKey($session));

        if (! is_array($index) || ! isset($index['bill_ids'], $index['export_date'], $index['total'])) {
            return null;
        }

        try {
            $bills = iterator_to_array($this->readChunked($session, $index['bill_ids']), false);
        } catch (BillHistoryCacheMiss) {
            return null;
        }

        return $this->assembled($session, $index, $bills);
    }

    /**
     * Yield each of a session's cached bill records one at a time, reading
     * from the store in chunks of {@see $chunkSize} keys rather than all at
     * once, so a caller transforming every record (e.g. into DTOs) never
     * has to hold the full raw bill list and the full transformed output in
     * memory simultaneously.
     *
     * Throws {@see BillHistoryCacheMiss} if a per-bill entry has expired
     * independently of the index partway through — callers should catch
     * this, discard whatever they've built so far, and resync rather than
     * use a partial result.
     *
     * @return \Generator<int, array>
     *
     * @throws BillHistoryCacheMiss
     */
    public function each(string $session): \Generator
    {
        $index = $this->store->get($this->indexKey($session));

        if (! is_array($index) || ! isset($index['bill_ids'])) {
            return;
        }

        yield from $this->readChunked($session, $index['bill_ids']);
    }

    /**
     * @param  array<int, string>  $billIds
     * @return \Generator<int, array>
     *
     * @throws BillHistoryCacheMiss
     */
    protected function readChunked(string $session, array $billIds): \Generator
    {
        foreach (array_chunk($billIds, $this->chunkSize) as $idBatch) {
            $keys = array_map(fn (string $id) => $this->billKey($session, $id), $idBatch);
            $values = $this->store->many($keys);

            foreach ($idBatch as $i => $id) {
                $record = $values[$keys[$i]] ?? null;

                if (! is_array($record)) {
                    throw new BillHistoryCacheMiss($session, $id);
                }

                yield $record;
            }

            unset($values);
        }
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
     * Write every bill from a Bill History export as its own cache entry,
     * then write the session index last, so a reader can never see an index
     * pointing at bills that haven't been written yet.
     *
     * Accepts any iterable of bills — typically {@see BillHistoryFetcher::fetchStream()}'s
     * generator, so no more than one bill's raw XML fragment and its parsed
     * array are ever resident in memory at once, on top of the current
     * {@see $chunkSize}-sized write batch (mirroring the batching {@see readChunked()}
     * already does for reads — a single putMany() call for a whole
     * session's bills would otherwise require the raw values, their
     * serialized form, and the upsert payload to all be resident at once).
     * When $bills is that generator, its export_date/total (available via
     * {@see \Generator::getReturn()} once fully consumed) populate the
     * index automatically; for a plain array, 'total' falls back to the
     * count of ids written and 'export_date' to ''.
     *
     * @param  iterable<int, array>  $bills
     */
    public function put(string $session, iterable $bills, ?int $ttl = null): void
    {
        $ttl ??= $this->ttl;

        $ids = [];
        $designatorMap = [];
        $values = [];

        foreach ($bills as $bill) {
            $id = (string) ($bill['id'] ?? '');
            $ids[] = $id;
            $values[$this->billKey($session, $id)] = $bill;

            if (! empty($bill['designator'])) {
                $designatorMap[BillIdentifier::normalize((string) $bill['designator'])] = $id;
            }

            if (count($values) >= $this->chunkSize) {
                $this->store->putMany($values, $ttl);
                $values = [];
            }
        }

        if ($values !== []) {
            $this->store->putMany($values, $ttl);
        }

        $meta = $bills instanceof \Generator ? $bills->getReturn() : null;

        $this->store->put($this->indexKey($session), [
            'export_date' => $meta['export_date'] ?? '',
            'total' => $meta['total'] ?? count($ids),
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
