<?php

namespace WiserWebSolutions\LaravelPalegis;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

class LaravelPalegis
{
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
     * Constructor to initialize the Palegis client with configuration settings.
     *
     * @throws PalegisException When required configuration is missing
     */
    public function __construct()
    {
        $this->endpoints = Config::get('palegis.endpoints', []);
        $this->request = Config::get('palegis.request', []);
        $this->cache = Config::get('palegis.cache', []);

        if (empty($this->endpoints)) {
            throw new PalegisException('RSS endpoints configuration is missing');
        }
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
        $cacheKey = 'palegis:rss:'.md5($url);

        if ($this->cache['enabled'] ?? false) {
            // A null store name resolves to the application's default cache store.
            $cacheStore = $this->cache['store'] ?? null;

            return Cache::store($cacheStore)
                ->remember($cacheKey, $ttl ?? ($this->cache['ttl'] ?? 3600), function () use ($url) {
                    return $this->fetchAndParseRss($url);
                });
        }

        return $this->fetchAndParseRss($url);
    }

    /**
     * Fetches and parses RSS XML into structured data.
     *
     * @param  string  $url  The RSS feed URL
     * @return array The parsed RSS data
     *
     * @throws PalegisException When response is invalid
     * @throws RequestException When HTTP request fails
     */
    protected function fetchAndParseRss(string $url): array
    {
        Log::debug('RSS Request (from API): '.$url);

        $response = Http::timeout($this->request['timeout'] ?? 30)
            ->retry($this->request['retry_times'] ?? 2, $this->request['retry_sleep_ms'] ?? 200)
            ->get($url);

        $response->throw();

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($response->body());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false || ! isset($xml->channel)) {
            throw new PalegisException('Invalid XML response from RSS feed');
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

    /**
     * Get House calendar RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed calendar data
     */
    public function getHouseCalendar(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'calendar', $ttl);
    }

    /**
     * Get House journals RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed journals data
     */
    public function getHouseJournals(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'journals', $ttl);
    }

    /**
     * Get House reports RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed reports data
     */
    public function getHouseReports(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'reports', $ttl);
    }

    /**
     * Get House votes RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed votes data
     */
    public function getHouseVotes(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'votes', $ttl);
    }

    /**
     * Get House bills RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed bills data
     */
    public function getHouseBills(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'bills', $ttl);
    }

    /**
     * Get House members RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed members data
     */
    public function getHouseMembers(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'members', $ttl);
    }

    /**
     * Get House committee schedule RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed committee schedule data
     */
    public function getHouseCommitteeSchedule(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'committee-schedule', $ttl);
    }

    /**
     * Get House roll calls RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed roll calls data
     */
    public function getHouseRollCalls(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'roll-calls', $ttl);
    }

    /**
     * Get House amendments RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed amendments data
     */
    public function getHouseAmendments(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'amendments', $ttl);
    }

    /**
     * Get House cosponsorship RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed cosponsorship data
     */
    public function getHouseCosponsorship(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'cosponsorship', $ttl);
    }

    /**
     * Get House committee assignments RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed committee assignments data
     */
    public function getHouseCommitteeAssignments(?int $ttl = null): array
    {
        return $this->fetchRssFeed('house', 'committee-assignments', $ttl);
    }

    /**
     * Get Senate calendar RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed calendar data
     */
    public function getSenateCalendar(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'calendar', $ttl);
    }

    /**
     * Get Senate journals RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed journals data
     */
    public function getSenateJournals(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'journals', $ttl);
    }

    /**
     * Get Senate notes RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed notes data
     */
    public function getSenateNotes(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'notes', $ttl);
    }

    /**
     * Get Senate votes RSS feed.
     *
     * @param  int|null  $ttl  Optional cache time-to-live in seconds
     * @return array The parsed votes data
     */
    public function getSenateVotes(?int $ttl = null): array
    {
        return $this->fetchRssFeed('senate', 'votes', $ttl);
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
}
