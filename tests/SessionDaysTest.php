<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Enums\Chamber;

class SessionDaysTest extends TestCase
{
    /**
     * A trimmed excerpt of a real `/{chamber}/session?days` page.
     *
     * @param  array<int, array{date: string, nonVoting?: bool}>  $days
     */
    private function page(array $days): string
    {
        $buttons = implode('', array_map(
            fn (array $day): string => sprintf(
                '<a class="dateBtn btn btn-info" href="/senate/session/info?SessDate=%1$s" aria-label="link for %1$s">%2$s</a>',
                $day['date'],
                ($day['nonVoting'] ?? false) ? '6 NV' : '6',
            ),
            $days
        ));

        return "<ul class=\"date-list\"><li class=\"list-group-item\">{$buttons}</li></ul>";
    }

    private function driver(): PalegisDriver
    {
        return app(PalegisDriver::class);
    }

    public function test_the_driver_advertises_the_capability(): void
    {
        $this->assertTrue($this->driver()->supports(Capability::ListChamberSessionDays));
    }

    public function test_it_reads_session_days_for_both_chambers(): void
    {
        Http::fake([
            'https://www.palegis.us/house/session?days' => Http::response($this->page([['date' => '09/16/2026']])),
            'https://www.palegis.us/senate/session?days' => Http::response($this->page([['date' => '09/28/2026']])),
        ]);

        $days = $this->driver()->chamberSessionDays();

        $this->assertCount(2, $days);
        $this->assertCount(1, $days->byChamber(Chamber::House));
        $this->assertCount(1, $days->byChamber(Chamber::Senate));
    }

    public function test_a_non_voting_day_is_flagged(): void
    {
        $page = $this->page([['date' => '01/06/2026', 'nonVoting' => true]]);

        Http::fake([
            'https://www.palegis.us/house/session?days' => Http::response($page),
            'https://www.palegis.us/senate/session?days' => Http::response($page),
        ]);

        $day = $this->driver()->chamberSessionDays()->first();

        $this->assertFalse($day->votingDay);
    }

    public function test_a_redesigned_page_throws_rather_than_silently_returning_nothing(): void
    {
        Http::fake([
            'https://www.palegis.us/house/session?days' => Http::response('<html>redesigned</html>'),
            'https://www.palegis.us/senate/session?days' => Http::response($this->page([['date' => '09/28/2026']])),
        ]);

        $this->expectException(PalegisException::class);

        $this->driver()->chamberSessionDays();
    }
}
