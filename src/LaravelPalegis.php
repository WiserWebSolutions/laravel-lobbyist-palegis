<?php

namespace WiserWebSolutions\LaravelPalegis;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\BillHistoryCache;
use WiserWebSolutions\LaravelPalegis\Support\BillHistoryFetcher;
use WiserWebSolutions\LaravelPalegis\Support\BillIdentifier;
use WiserWebSolutions\LaravelPalegis\Support\Concerns\FetchesHttp;

class LaravelPalegis
{
    use FetchesHttp;

    /**
     * RSS endpoints configuration.
     */
    protected array $endpoints;

    /**
     * Request configuration settings.
     */
    protected array $request;

    /**
     * Cache configuration settings.
     */
    protected array $cache;

    /**
     * Bulk data-download configuration settings.
     */
    protected array $data;

    /**
     * Downloads and parses the Bill History Data export.
     */
    protected BillHistoryFetcher $fetcher;

    /**
     * Caches the Bill History export per-bill.
     */
    protected BillHistoryCache $billHistoryCache;

    /**
     * Constructor to initialize the Palegis client with configuration settings.
     *
     * @throws PalegisException When required configuration is missing
     */
    public function __construct()
    {
        $this->endpoints = Config::get('palegis.endpoints', []);
        $this->request = Config::get('palegis.request', []);
        $this->cache = Config::get('palegis.cache', []);
        $this->data = Config::get('palegis.data', []);

        if (empty($this->endpoints)) {
            throw new PalegisException('RSS endpoints configuration is missing');
        }

        $this->fetcher = new BillHistoryFetcher;
        $this->billHistoryCache = new BillHistoryCache(
            Cache::store($this->cache['store'] ?? null),
            $this->data['ttl'] ?? ($this->cache['ttl'] ?? null),
        );
    }

    /**
     * Fetches an RSS feed from the Pennsylvania legislative portal.
     *
     * @param  string  $chamber  The chamber (house or senate)
     * @param  string  $feedType  The type of feed (calendar, journals, reports, etc.)
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed RSS feed data
     *
     * @throws PalegisException When response is invalid
     */
    public function fetchRssFeed(string $chamber, string $feedType, ?int $ttl = null): array
    {
        if (! isset($this->endpoints[$chamber][$feedType])) {
            throw new PalegisException("RSS feed not found for chamber '{$chamber}' and type '{$feedType}'");
        }

        $url = $this->endpoints[$chamber][$feedType];

        return $this->remember('rss:'.$url, fn () => $this->fetchAndParseRss($url), $ttl);
    }

    /**
     * Run a producer through the configured cache, or directly if caching is off.
     *
     * @param  string  $key  A stable identifier for the cached value
     */
    protected function remember(string $key, callable $producer, ?int $ttl = null): mixed
    {
        if (! ($this->cache['enabled'] ?? false)) {
            return $producer();
        }

        // A null store name resolves to the application's default cache store.
        return Cache::store($this->cache['store'] ?? null)
            ->remember('palegis:'.md5($key), $ttl ?? ($this->cache['ttl'] ?? 3600), $producer);
    }

    /**
     * Fetches and parses RSS XML into structured data.
     *
     * @param  string  $url  The RSS feed URL
     * @return array The parsed RSS data
     *
     * @throws PalegisException When the request fails or the response is invalid
     */
    protected function fetchAndParseRss(string $url): array
    {
        $body = $this->fetchBody($url);

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false || ! isset($xml->channel)) {
            throw new PalegisException("Invalid RSS response from [{$url}].");
        }

        return $this->parseRssXml($xml);
    }

    /**
     * Parses RSS XML into a structured array.
     *
     * @param  \SimpleXMLElement  $xml  The RSS XML
     * @return array Structured RSS data
     */
    protected function parseRssXml(\SimpleXMLElement $xml): array
    {
        $channel = $xml->channel;

        $result = [
            'title' => (string) $channel->title,
            'link' => (string) $channel->link,
            'description' => (string) $channel->description,
            'pub_date' => (string) $channel->pubDate,

            'language' => (string) $channel->language,
            'last_build_date' => (string) $channel->lastBuildDate,
            'categories' => [],
            'items' => [],
        ];

        // Parse channel categories
        foreach ($channel->category as $category) {
            $result['categories'][] = (string) $category;
        }

        // Parse items
        foreach ($channel->item as $item) {
            $itemData = [
                'title' => (string) $item->title,
                'link' => (string) $item->link,
                'description' => (string) $item->description,
                'pub_date' => (string) $item->pubDate,
                'guid' => (string) $item->guid,
                'categories' => [],
            ];

            // Parse item categories
            foreach ($item->category as $category) {
                $itemData['categories'][] = (string) $category;
            }

            $result['items'][] = $itemData;
        }

        return $result;
    }

    // -----------------------------------------------------------------
    // House feeds
    // -----------------------------------------------------------------

    /** Get the House Calendar RSS feed. */
    public function getHouseCalendar(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'calendar', $ttl);
    }

    /** Get the House Tabled Bill Calendar RSS feed. */
    public function getHouseTabledBillCalendar(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'calendar-tabled-bill', $ttl);
    }

    /** Get the House Journals RSS feed. */
    public function getHouseJournals(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'journals', $ttl);
    }

    /** Get the House Daily Session Reports RSS feed. */
    public function getHouseReports(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'reports', $ttl);
    }

    /** Get the House Roll Call Votes RSS feed. */
    public function getHouseVotes(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'votes', $ttl);
    }

    /** Get the House Voted Amendments RSS feed. */
    public function getHouseAmendments(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'amendments', $ttl);
    }

    /** Get the House Members RSS feed. */
    public function getHouseMembers(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'members', $ttl);
    }

    /** Get the House Committee Meeting Schedule RSS feed. */
    public function getHouseCommitteeSchedule(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'committee-schedule', $ttl);
    }

    /** Get the House Committee Assignments RSS feed. */
    public function getHouseCommitteeAssignments(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'committee-assignments', $ttl);
    }

    /** Get the House Co-Sponsorship Memoranda RSS feed. */
    public function getHouseCosponsorshipMemos(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'memos', $ttl);
    }

    // -----------------------------------------------------------------
    // Senate feeds
    // -----------------------------------------------------------------

    /** Get the Senate Calendar RSS feed. */
    public function getSenateCalendar(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'calendar', $ttl);
    }

    /** Get the Senate Journals RSS feed. */
    public function getSenateJournals(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'journals', $ttl);
    }

    /** Get the Senate Session Notes RSS feed. */
    public function getSenateNotes(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'notes', $ttl);
    }

    /** Get the Senate Roll Call Votes RSS feed. */
    public function getSenateVotes(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'votes', $ttl);
    }

    /** Get the Senate Floor Amendments RSS feed. */
    public function getSenateAmendments(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'amendments', $ttl);
    }

    /** Get the Senate Members RSS feed. */
    public function getSenateMembers(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'members', $ttl);
    }

    /** Get the Senate Executive Nominations Calendar RSS feed. */
    public function getSenateExecutiveNominations(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'executive-nominations', $ttl);
    }

    /** Get the Senate Committee Meeting Schedule RSS feed. */
    public function getSenateCommitteeSchedule(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'committee-schedule', $ttl);
    }

    /** Get the Senate Committee Assignments RSS feed. */
    public function getSenateCommitteeAssignments(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'committee-assignments', $ttl);
    }

    /** Get the Senate Co-Sponsorship Memoranda RSS feed. */
    public function getSenateCosponsorshipMemos(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'memos', $ttl);
    }

    /**
     * Get all available RSS feed types for a chamber.
     *
     * @param  string  $chamber  The chamber (house or senate)
     * @return array Available feed types
     */
    public function getAvailableFeeds(string $chamber): array
    {
        return array_keys($this->endpoints[$chamber] ?? []);
    }

    /**
     * Get all configured RSS endpoints.
     *
     * @return array All RSS endpoints
     */
    public function getAllEndpoints(): array
    {
        return $this->endpoints;
    }

    // -----------------------------------------------------------------
    // Bulk data downloads
    // -----------------------------------------------------------------

    /**
     * Fetch the Bill History Data export for a session, keyed per bill in
     * cache. Every bill and resolution in the session with its sponsors,
     * printer numbers, and full action history.
     *
     * If the cache has been populated (typically by the scheduled
     * `palegis:sync-bill-history` command), this reassembles the result
     * from the per-bill cache entries without a live download. On a cold or
     * expired cache it falls back to downloading and parsing the export
     * directly, populating the cache for next time.
     *
     * @param  string|null  $session  palegis session id (e.g. "2025_0"); null
     *                                auto-detects the current regular session.
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array{export_date: string, total: int, session: string, bills: array<int, array>}
     *
     * @throws PalegisException When the archive or XML is invalid
     */
    public function getBillHistory(?string $session = null, ?int $ttl = null): array
    {
        $session ??= $this->currentSession();

        if (! ($this->cache['enabled'] ?? false)) {
            return $this->fetcher->fetch($session);
        }

        return $this->billHistoryCache->all($session) ?? $this->syncBillHistory($session, $ttl);
    }

    /**
     * Download, parse, and cache the Bill History Data export for a
     * session, one cache entry per bill. This is the reusable "sync"
     * operation shared by the `palegis:sync-bill-history` command and the
     * live fallback inside {@see getBillHistory()}.
     *
     * @return array{export_date: string, total: int, session: string, bills: array<int, array>}
     *
     * @throws PalegisException
     */
    public function syncBillHistory(?string $session = null, ?int $ttl = null): array
    {
        $session ??= $this->currentSession();

        $parsed = $this->fetcher->fetch($session);

        if ($this->cache['enabled'] ?? false) {
            $this->billHistoryCache->put($session, $parsed, $ttl ?? ($this->data['ttl'] ?? null));
        }

        return $parsed;
    }

    /**
     * Look up a single bill's raw Bill History record by id or designator,
     * without materializing the full bill list when the cache can answer
     * directly.
     *
     * @return array|null The raw bill record, or null if not found
     *
     * @throws PalegisException
     */
    public function findBill(?string $session, string $identifier): ?array
    {
        $session ??= $this->currentSession();

        if (! ($this->cache['enabled'] ?? false)) {
            return $this->scanBills($this->fetcher->fetch($session)['bills'] ?? [], $identifier);
        }

        if ($this->billHistoryCache->hasIndex($session)) {
            return $this->billHistoryCache->find($session, $identifier);
        }

        return $this->scanBills($this->syncBillHistory($session)['bills'] ?? [], $identifier);
    }

    /**
     * @param  array<int, array>  $bills
     */
    private function scanBills(array $bills, string $identifier): ?array
    {
        foreach ($bills as $record) {
            if (BillIdentifier::matches($record, $identifier)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * The current regular-session palegis identifier, inferred from the year
     * (Pennsylvania sessions run two years, starting in the odd year). For
     * example, both 2025 and 2026 resolve to "2025_0".
     */
    public function currentSession(): string
    {
        $year = (int) date('Y');
        $start = $year % 2 === 0 ? $year - 1 : $year;

        return $start.'_0';
    }
}
