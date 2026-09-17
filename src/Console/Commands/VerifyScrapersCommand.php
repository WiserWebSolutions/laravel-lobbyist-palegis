<?php

namespace WiserWebSolutions\LaravelPalegis\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\Support\CommitteeRollCallPageParser;
use WiserWebSolutions\LaravelPalegis\Support\Concerns\FetchesHttp;
use WiserWebSolutions\LaravelPalegis\Support\RollCallPageParser;

/**
 * Checks every scraped-HTML page this package reads against known-good
 * identifiers, so a palegis.us redesign is caught here -- as a CI failure --
 * rather than the next time the real sync runs, where every one of these
 * pages fails the same way: silently, by parsing to an empty result rather
 * than throwing (unlike a malformed RSS response, which fails loudly on its
 * own). Read-only; makes no writes and touches no cache.
 *
 * The RSS-backed feeds are not checked here -- a malformed RSS response
 * already fails loudly, which is exactly the property this command exists to
 * give the scraped pages that don't have it.
 */
class VerifyScrapersCommand extends Command
{
    use FetchesHttp;

    protected $signature = 'palegis:verify-scrapers';

    protected $description = 'Check every scraped palegis.us page against known-good identifiers, to catch a redesign before the next real sync does.';

    protected array $request = [];

    public function __construct(private readonly LaravelPalegis $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->request = (array) config('palegis.request', []);

        $failures = 0;

        foreach ($this->checks() as $label => $check) {
            $this->components->task($label, function () use ($check, &$failures): bool {
                try {
                    $count = $check();
                } catch (Throwable) {
                    $failures++;

                    return false;
                }

                if ($count < 1) {
                    $failures++;

                    return false;
                }

                return true;
            });
        }

        if ($failures > 0) {
            $this->components->error("{$failures} scraper(s) found nothing where real content was expected. palegis.us may have redesigned a page -- see the failing check(s) above.");

            return self::FAILURE;
        }

        $this->components->info('Every scraper still parses real content.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, callable(): int> Each check returns how many
     *                                        records it found, so a check that quietly starts returning zero (the
     *                                        exact failure mode a page redesign produces) is treated the same as
     *                                        one that throws outright.
     */
    private function checks(): array
    {
        $verify = (array) config('palegis.roll_calls.verify', []);
        $session = (string) ($verify['session'] ?? '2025_0');
        $floorRcNum = (int) ($verify['floor_rc_num'] ?? 1);
        $committeeCode = (string) ($verify['committee_code'] ?? '');
        $committeeRcNum = (int) ($verify['committee_rc_num'] ?? 1);

        return [
            'House session-day calendar' => fn (): int => count($this->client->getHouseSessionDays(ttl: 1)),
            'Senate session-day calendar' => fn (): int => count($this->client->getSenateSessionDays(ttl: 1)),
            'House member roster' => fn (): int => count($this->client->getHouseMembersForSession(ttl: 1)),
            'Senate member roster' => fn (): int => count($this->client->getSenateMembersForSession(ttl: 1)),
            'House committee list' => fn (): int => count($this->client->getHouseCommitteeList(ttl: 1)),
            'Senate committee list' => fn (): int => count($this->client->getSenateCommitteeList(ttl: 1)),
            "House floor roll call #{$floorRcNum} ({$session})" => fn (): int => count(
                RollCallPageParser::parse($this->fetchBody($this->floorRollCallUrl('house', $session, $floorRcNum)))['positions']
            ),
            "House committee #{$committeeCode} roll call #{$committeeRcNum} ({$session})" => fn (): int => count(
                CommitteeRollCallPageParser::parse($this->fetchBody($this->committeeRollCallUrl('house', $session, $committeeCode, $committeeRcNum)))['positions']
            ),
        ];
    }

    private function floorRollCallUrl(string $chamber, string $session, int $rcNum): string
    {
        [$sessYr, $sessInd] = array_pad(explode('_', $session, 2), 2, '0');

        return "https://www.palegis.us/{$chamber}/roll-calls/summary?sessYr={$sessYr}&sessInd={$sessInd}&rcNum={$rcNum}";
    }

    private function committeeRollCallUrl(string $chamber, string $session, string $committeeCode, int $rcNum): string
    {
        [$sessYr, $sessInd] = array_pad(explode('_', $session, 2), 2, '0');

        return "https://www.palegis.us/{$chamber}/committees/roll-call-votes/vote-list/vote-summary"
            ."?sessyr={$sessYr}&sessind={$sessInd}&committeecode={$committeeCode}&rollcallid={$rcNum}";
    }
}
