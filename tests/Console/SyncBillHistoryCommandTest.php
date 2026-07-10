<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Console;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class SyncBillHistoryCommandTest extends TestCase
{
    private function enableCache(): void
    {
        $this->app['config']->set('palegis.cache.enabled', true);
        $this->app['config']->set('palegis.cache.store', 'array');
    }

    public function test_command_downloads_and_caches_the_current_session_by_default(): void
    {
        $this->enableCache();
        $this->fakeBillHistory();

        $this->artisan('palegis:sync-bill-history', ['--session' => '2025_0'])
            ->assertSuccessful();

        (new LaravelPalegis)->getBillHistory('2025_0');

        Http::assertSentCount(1);
    }

    public function test_command_accepts_an_explicit_session_option(): void
    {
        $this->enableCache();
        $this->fakeBillHistory();

        $this->artisan('palegis:sync-bill-history', ['--session' => '2025_0'])
            ->assertSuccessful()
            ->expectsOutputToContain('Cached 2 bill(s)');
    }

    public function test_command_reports_failure_on_download_error(): void
    {
        $this->enableCache();

        Http::fake([
            'www.palegis.us/data/file*' => Http::response('not found', 404),
        ]);

        $this->artisan('palegis:sync-bill-history', ['--session' => '2099_0'])
            ->assertFailed()
            ->expectsOutputToContain('may be invalid');
    }
}
