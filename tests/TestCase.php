<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use WiserWebSolutions\LaravelPalegis\LaravelPalegisServiceProvider;
use WiserWebSolutions\Lobbyist\LobbyistServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Guard: any HTTP request that isn't explicitly faked should fail the
        // test rather than hit the live palegis.us site.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LobbyistServiceProvider::class,
            LaravelPalegisServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('palegis.cache.enabled', false);
        $app['config']->set('palegis.request', [
            'timeout' => 5,
            'retry_times' => 1,
            'retry_sleep_ms' => 0,
        ]);
    }

    /**
     * Build a minimal RSS document with the given items.
     *
     * @param  array<int, array{title?: string, link?: string, description?: string, guid?: string}>  $items
     */
    protected function rssXml(array $items, string $channelTitle = 'PA Feed'): string
    {
        $itemsXml = '';
        foreach ($items as $item) {
            $itemsXml .= sprintf(
                '<item><title>%s</title><link>%s</link><description>%s</description><pubDate>%s</pubDate><guid>%s</guid></item>',
                htmlspecialchars($item['title'] ?? ''),
                htmlspecialchars($item['link'] ?? ''),
                htmlspecialchars($item['description'] ?? ''),
                htmlspecialchars($item['pub_date'] ?? 'Mon, 01 May 2023 12:00:00 -0400'),
                htmlspecialchars($item['guid'] ?? ''),
            );
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel>'
            ."<title>{$channelTitle}</title><link>https://www.palegis.us</link>"
            .'<description>Feed</description>'
            .$itemsXml
            .'</channel></rss>';
    }

    /**
     * Fake the Bill History download with a ZIP-wrapped XML fixture (two bills).
     */
    protected function fakeBillHistory(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<historyExport exportDate="July 9, 2026 7:30:08 PM EDT" totalDocuments="2">
    <session year="2025" session="0">
        <bill id="20250HB0017" lastUpdate="May 13, 2026 12:05:00 PM EDT">
            <sessionYear>2025</sessionYear>
            <session>0</session>
            <body>H</body>
            <type description="House Bill">B</type>
            <subType>B</subType>
            <number>0017</number>
            <shortTitle>An Act providing for cursive handwriting instruction.</shortTitle>
            <cosponsorshipMemo memoUrl="https://www.palegis.us/house/co-sponsorship/memo?memoID=43567">Mandating Cursive Handwriting</cosponsorshipMemo>
            <sponsors>
                <sponsor sequenceNumber="01" fillSequence="0" party="R" body="H" districtNumber="116">WATRO</sponsor>
                <sponsor sequenceNumber="02" fillSequence="0" party="D" body="H" districtNumber="174">NEILSON</sponsor>
            </sponsors>
            <printersNumberHistory>
                <number sequence="01" billTextPdfUrl="https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0002">0002</number>
            </printersNumberHistory>
            <actionHistory>
                <action sequence="01" actionChamber="H">
                    <verb>Referred to</verb>
                    <committee>EDUCATION</committee>
                    <date>01/08/25</date>
                    <printersNumber>0002</printersNumber>
                    <rollCallVote></rollCallVote>
                    <fullAction>Referred to EDUCATION, Jan. 8, 2025</fullAction>
                </action>
                <action sequence="02" actionChamber="H">
                    <verb>Reported as committed,</verb>
                    <committee>EDUCATION</committee>
                    <date>03/12/25</date>
                    <printersNumber>0002</printersNumber>
                    <rollCallVote></rollCallVote>
                    <fullAction>Reported as committed, March 12, 2025</fullAction>
                </action>
            </actionHistory>
        </bill>
        <bill id="20250SB0100" lastUpdate="Feb 1, 2026 9:00:00 AM EST">
            <sessionYear>2025</sessionYear>
            <session>0</session>
            <body>S</body>
            <type description="Senate Bill">B</type>
            <subType>B</subType>
            <number>0100</number>
            <shortTitle>An Act concerning appropriations.</shortTitle>
            <sponsors></sponsors>
            <printersNumberHistory></printersNumberHistory>
            <actionHistory>
                <action sequence="01" actionChamber="S">
                    <verb>Introduced and referred to</verb>
                    <committee>APPROPRIATIONS</committee>
                    <date>02/01/25</date>
                    <printersNumber>0101</printersNumber>
                    <rollCallVote></rollCallVote>
                    <fullAction>Introduced and referred to APPROPRIATIONS, Feb. 1, 2025</fullAction>
                </action>
            </actionHistory>
        </bill>
    </session>
</historyExport>
XML;

        $zipBytes = $this->zipString('PA-Bill-History-2025-RegularSession.xml', $xml);

        Http::fake([
            'www.palegis.us/data/file*' => Http::response($zipBytes, 200),
        ]);
    }

    /**
     * Build an in-memory ZIP archive containing a single named entry.
     */
    protected function zipString(string $entryName, string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'palegis_test_zip_');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString($entryName, $contents);
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
