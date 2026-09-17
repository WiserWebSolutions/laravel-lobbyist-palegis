<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use Generator;
use Illuminate\Contracts\Cache\Repository;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\Concerns\FetchesHttp;

/**
 * Walks every committee's roll calls, by number, for a chamber.
 *
 * Unlike a floor roll call ({@see RollCallEnumerator}), a committee roll
 * call's `rollcallid` is scoped to one committee -- the same id under the
 * wrong `committeecode` renders a page with no vote data (verified live:
 * not a 404, an empty result), so this must first know every committee's
 * code (see {@see CommitteeListPageParser}) and then run one independent
 * walk per committee, each with its own consecutive-miss/hard-ceiling stop
 * condition -- see {@see RollCallEnumerator}'s class doc for why both exist.
 *
 * A completed roll call is cached forever, same as a floor roll call: it is
 * immutable history, and a chamber's ~40-50 committees each having their own
 * sequence to walk makes that caching more important here, not less.
 */
class CommitteeRollCallEnumerator
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
     * @param  list<array{code: string, slug: string, name: string}>  $committees
     * @param  string  $session  palegis session id (e.g. "2025_0")
     * @return Generator<int, array{rc_num: int, committee_code: string, committee: ?string, date: ?string, bill: ?array{year: string, body: string, type: string, number: string}, motion: ?string, tallies: array<string, int>, positions: list<array{id: string, name: string, position: string}>}>
     */
    public function walkAll(string $chamber, string $session, array $committees): Generator
    {
        foreach ($committees as $committee) {
            // Not `yield from`: that preserves each inner walk()'s own keys
            // (0, 1, 2, ...), so a second committee's first record would
            // collide with the first committee's under the same key --
            // silently clobbering it for any caller (e.g. iterator_to_array())
            // that preserves keys by default. A plain yield inside this loop
            // always gets a fresh key from this outer generator instead.
            foreach ($this->walk($chamber, $session, $committee['code']) as $record) {
                yield $record;
            }
        }
    }

    /**
     * @param  string  $session  palegis session id (e.g. "2025_0")
     * @return Generator<int, array{rc_num: int, committee_code: string, committee: ?string, date: ?string, bill: ?array, motion: ?string, tallies: array<string, int>, positions: list<array>}>
     */
    public function walk(string $chamber, string $session, string $committeeCode, int $startAt = 1): Generator
    {
        $misses = 0;
        $rcNum = $startAt;

        while ($misses < $this->maxConsecutiveMisses && $rcNum <= $this->hardCeiling) {
            $record = $this->recordFor($chamber, $session, $committeeCode, $rcNum);

            if ($record === null) {
                $misses++;
                $rcNum++;

                continue;
            }

            $misses = 0;
            yield ['rc_num' => $rcNum, 'committee_code' => $committeeCode, ...$record];
            $rcNum++;
        }
    }

    /**
     * @return array{committee: ?string, date: ?string, bill: ?array, motion: ?string, tallies: array<string, int>, positions: list<array>}|null
     */
    private function recordFor(string $chamber, string $session, string $committeeCode, int $rcNum): ?array
    {
        $key = $this->cacheKey($chamber, $session, $committeeCode, $rcNum);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $record = CommitteeRollCallPageParser::parse($this->fetchBody($this->url($chamber, $session, $committeeCode, $rcNum)));
        } catch (PalegisException) {
            return null;
        }

        $this->cache->forever($key, $record);

        return $record;
    }

    private function url(string $chamber, string $session, string $committeeCode, int $rcNum): string
    {
        [$sessYr, $sessInd] = array_pad(explode('_', $session, 2), 2, '0');

        return self::BASE_URL."/{$chamber}/committees/roll-call-votes/vote-list/vote-summary"
            ."?sessyr={$sessYr}&sessind={$sessInd}&committeecode={$committeeCode}&rollcallid={$rcNum}";
    }

    private function cacheKey(string $chamber, string $session, string $committeeCode, int $rcNum): string
    {
        return "palegis:committee-roll-call:{$chamber}:{$session}:{$committeeCode}:{$rcNum}";
    }
}
