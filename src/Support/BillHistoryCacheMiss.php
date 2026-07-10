<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

/**
 * Thrown by {@see BillHistoryCache::each()} when a per-bill entry has
 * expired/evicted independently of its session index while streaming.
 * Internal control-flow signal — callers should catch this and resync
 * rather than let it propagate to package consumers.
 */
class BillHistoryCacheMiss extends \RuntimeException
{
    public function __construct(public readonly string $session, public readonly string $billId)
    {
        parent::__construct("Bill [{$billId}] for session [{$session}] is missing from the per-bill cache.");
    }
}
