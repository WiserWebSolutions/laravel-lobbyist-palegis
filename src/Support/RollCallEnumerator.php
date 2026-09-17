<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use Generator;
use Illuminate\Contracts\Cache\Repository;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\Concerns\FetchesHttp;

/**
 * Walks a chamber's floor roll calls by number (`rcNum=1, 2, 3, ...`),
 * parsing each via {@see RollCallPageParser} and caching every completed one
 * forever -- a finished roll call is history and never changes, unlike
 * everything else this package reads from a live page.
 *
 * There is no index of "how many roll calls exist" to consult first, so this
 * walks forward from a starting number until it sees enough consecutive
 * misses (a failed fetch, or a page with no `.rc-member` rows -- either way
 * {@see RollCallPageParser::parse()} throws) to conclude it has reached the
 * end. The chamber's roll calls are numbered sequentially with no gaps, so a
 * real miss run this long never occurs mid-session; it is generous rather
 * than tight specifically so a single transient failure cannot silently
 * truncate the walk.
 *
 * {@see $hardCeiling} is a second, independent stop condition -- not a
 * realistic session length, but a guarantee against ever walking forever. A
 * consecutive-miss run only ends the walk if the server actually produces a
 * miss; a server that (through some bug, redirect loop, or generic fallback
 * page) kept answering every request with an unrelated success would
 * otherwise never trip {@see $maxConsecutiveMisses} at all.
 */
class RollCallEnumerator
{
    use FetchesHttp;

    private const BASE_URL = 'https://www.palegis.us';

    protected array $request;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $maxConsecutiveMisses = 5,
        ?array $request = null,
        private readonly int $hardCeiling = 5000,
    ) {
        $this->request = $request ?? [];
    }

    /**
     * @param  string  $session  palegis session id (e.g. "2025_0"); roll call
     *                           numbers are scoped to a session, so this must match the one being
     *                           imported.
     * @return Generator<int, array{rc_num: int, session_year: ?string, session_index: ?string, date: ?string, time: ?string, bill: ?array{year: string, body: string, type: string, number: string}, action: ?string, tallies: array<string, int>, positions: list<array{id: string, name: string, party: string, district: string, position: string}>}>
     */
    public function walk(string $chamber, string $session, int $startAt = 1): Generator
    {
        $misses = 0;
        $rcNum = $startAt;

        while ($misses < $this->maxConsecutiveMisses && $rcNum <= $this->hardCeiling) {
            $record = $this->recordFor($chamber, $session, $rcNum);

            if ($record === null) {
                $misses++;
                $rcNum++;

                continue;
            }

            $misses = 0;
            yield ['rc_num' => $rcNum, ...$record];
            $rcNum++;
        }
    }

    /**
     * @return array{session_year: ?string, session_index: ?string, date: ?string, time: ?string, bill: ?array, action: ?string, tallies: array<string, int>, positions: list<array>}|null
     */
    private function recordFor(string $chamber, string $session, int $rcNum): ?array
    {
        $key = $this->cacheKey($chamber, $session, $rcNum);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $record = RollCallPageParser::parse($this->fetchBody($this->url($chamber, $session, $rcNum)));
        } catch (PalegisException) {
            return null;
        }

        $this->cache->forever($key, $record);

        return $record;
    }

    private function url(string $chamber, string $session, int $rcNum): string
    {
        [$sessYr, $sessInd] = array_pad(explode('_', $session, 2), 2, '0');

        return self::BASE_URL."/{$chamber}/roll-calls/summary?sessYr={$sessYr}&sessInd={$sessInd}&rcNum={$rcNum}";
    }

    private function cacheKey(string $chamber, string $session, int $rcNum): string
    {
        return "palegis:roll-call:{$chamber}:{$session}:{$rcNum}";
    }
}
