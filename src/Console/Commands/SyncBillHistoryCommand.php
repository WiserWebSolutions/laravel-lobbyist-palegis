<?php

namespace WiserWebSolutions\LaravelPalegis\Console\Commands;

use Illuminate\Console\Command;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;

class SyncBillHistoryCommand extends Command
{
    protected $signature = 'palegis:sync-bill-history
        {--session= : palegis session id (e.g. "2025_0"); defaults to the current regular session}';

    protected $description = 'Download the PA Bill History Data export and cache it, one entry per bill.';

    public function __construct(private readonly LaravelPalegis $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $session = $this->option('session') ?: $this->client->currentSession();

        $this->info("Syncing PA Bill History for session [{$session}]...");

        try {
            $parsed = $this->client->syncBillHistory($session);
        } catch (PalegisException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Cached {$parsed['total']} bill(s) for session [{$session}].");

        return self::SUCCESS;
    }
}
