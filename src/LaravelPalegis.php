<?php

namespace WiserWebSolutions\LaravelPalegis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

class LaravelPalegis
{
    /**
     * Base URL for the palegis.us bulk-data download endpoint.
     */
    private const BILL_HISTORY_BASE_URL = 'https://www.palegis.us/data/file';

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
     * Perform a GET request and return the raw body, throwing an informative
     * PalegisException (including the attempted URL) on any failure.
     *
     * @param  string|null  $notFoundHint  Extra guidance to include on a 404
     *
     * @throws PalegisException
     */
    protected function fetchBody(string $url, ?string $notFoundHint = null): string
    {
        Log::debug('palegis request: '.$url);

        try {
            $response = Http::timeout($this->request['timeout'] ?? 30)
                ->retry($this->request['retry_times'] ?? 2, $this->request['retry_sleep_ms'] ?? 200)
                ->get($url);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            throw PalegisException::requestFailed($url, $status, $this->hintFor($status, $notFoundHint), $e);
        } catch (ConnectionException $e) {
            throw PalegisException::requestFailed($url, null, 'Could not connect to palegis.us: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw PalegisException::requestFailed($url, $response->status(), $this->hintFor($response->status(), $notFoundHint));
        }

        return $response->body();
    }

    private function hintFor(?int $status, ?string $notFoundHint): ?string
    {
        return ($status === 404 && $notFoundHint !== null) ? $notFoundHint : null;
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
     * Fetch and parse the Bill History Data export for a session.
     *
     * Every bill and resolution in the session with its sponsors, printer
     * numbers, and full action history. The source is a ZIP-wrapped XML
     * document; it is downloaded, extracted, parsed, and cached.
     *
     * NOTE: the export is large (thousands of bills / tens of MB uncompressed);
     * parsing loads the whole document into memory.
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
        $url = $this->billHistoryUrl($session);

        return $this->remember(
            'bill-history:'.$session,
            fn () => $this->fetchAndParseBillHistory($url, $session),
            $ttl ?? ($this->data['ttl'] ?? null),
        );
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

    /**
     * Build the Bill History Data download URL for a session.
     */
    protected function billHistoryUrl(string $session): string
    {
        return self::BILL_HISTORY_BASE_URL.'?'.http_build_query([
            'documentType' => 'BillHistoryData',
            'session' => $session,
        ]);
    }

    /**
     * Download the Bill History ZIP, extract its XML, and parse it.
     *
     * @throws PalegisException
     */
    protected function fetchAndParseBillHistory(string $url, string $session): array
    {
        $body = $this->fetchBody(
            $url,
            "The session [{$session}] may be invalid or not yet published. "
            ."Session ids look like '2025_0' (regular) or '2025_1' (special session)."
        );

        $xmlString = $this->extractXmlFromZip($body);

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($xmlString);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new PalegisException('Invalid XML in Bill History export');
        }

        return $this->parseBillHistoryXml($xml, $session);
    }

    /**
     * Extract the first XML entry from a ZIP archive given as a binary string.
     *
     * @throws PalegisException
     */
    protected function extractXmlFromZip(string $zipBinary): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'palegis_bh_');

        if ($tmp === false) {
            throw new PalegisException('Unable to create a temp file for the Bill History archive');
        }

        try {
            file_put_contents($tmp, $zipBinary);

            $zip = new \ZipArchive;

            if ($zip->open($tmp) !== true) {
                throw new PalegisException('Unable to open the Bill History archive');
            }

            $contents = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), '.xml')) {
                    $contents = $zip->getFromIndex($i);
                    break;
                }
            }

            $zip->close();
        } finally {
            @unlink($tmp);
        }

        if (! is_string($contents) || $contents === '') {
            throw new PalegisException('No XML entry found in the Bill History archive');
        }

        return $contents;
    }

    /**
     * Parse a Bill History <historyExport> document into a structured array.
     *
     * @return array{export_date: string, total: int, session: string, bills: array<int, array>}
     */
    protected function parseBillHistoryXml(\SimpleXMLElement $xml, string $session): array
    {
        $bills = [];

        foreach ($xml->session as $sessionNode) {
            foreach ($sessionNode->bill as $bill) {
                $bills[] = $this->parseBillHistoryBill($bill);
            }
        }

        return [
            'export_date' => (string) $xml['exportDate'],
            'total' => (int) $xml['totalDocuments'],
            'session' => $session,
            'bills' => $bills,
        ];
    }

    /**
     * Parse a single <bill> node from the Bill History export.
     */
    protected function parseBillHistoryBill(\SimpleXMLElement $bill): array
    {
        $id = (string) $bill['id'];

        $sponsors = [];
        foreach ($bill->sponsors->sponsor as $sponsor) {
            $sponsors[] = [
                'name' => (string) $sponsor,
                'party' => (string) $sponsor['party'],
                'body' => (string) $sponsor['body'],
                'district' => (string) $sponsor['districtNumber'],
                'sequence' => (string) $sponsor['sequenceNumber'],
            ];
        }

        $printersNumbers = [];
        foreach ($bill->printersNumberHistory->number as $number) {
            $printersNumbers[] = [
                'sequence' => (string) $number['sequence'],
                'number' => (string) $number,
                'pdf_url' => (string) $number['billTextPdfUrl'],
            ];
        }

        $actions = [];
        foreach ($bill->actionHistory->action as $action) {
            $actions[] = [
                'sequence' => (string) $action['sequence'],
                'chamber' => (string) $action['actionChamber'],
                'verb' => (string) $action->verb,
                'committee' => (string) $action->committee,
                'date' => (string) $action->date,
                'printers_number' => (string) $action->printersNumber,
                'roll_call' => (string) $action->rollCallVote,
                'full_action' => (string) $action->fullAction,
            ];
        }

        return [
            'id' => $id,
            'last_update' => (string) $bill['lastUpdate'],
            'session_year' => (string) $bill->sessionYear,
            'session' => (string) $bill->session,
            'body' => (string) $bill->body,
            'type' => (string) $bill->type,
            'type_description' => (string) $bill->type['description'],
            'sub_type' => (string) $bill->subType,
            'number' => (string) $bill->number,
            'designator' => $this->billDesignatorFromId($id),
            'short_title' => (string) $bill->shortTitle,
            'cosponsorship_memo' => [
                'text' => (string) $bill->cosponsorshipMemo,
                'url' => (string) $bill->cosponsorshipMemo['memoUrl'],
            ],
            'sponsors' => $sponsors,
            'printers_numbers' => $printersNumbers,
            'actions' => $actions,
        ];
    }

    /**
     * Derive a human bill designator (e.g. "HB17") from a bill id
     * like "20250HB0017".
     */
    protected function billDesignatorFromId(string $id): string
    {
        if (preg_match('/([A-Z]+)(\d+)$/', $id, $matches)) {
            return $matches[1].ltrim($matches[2], '0');
        }

        return $id;
    }
}
