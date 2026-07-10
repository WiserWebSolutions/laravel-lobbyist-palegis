<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use Illuminate\Support\Facades\Config;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\Concerns\FetchesHttp;

/**
 * Downloads and parses the palegis.us Bill History Data export for a
 * session. Pure fetch+parse — no caching knowledge.
 *
 * NOTE: the export is large (thousands of bills / tens of MB uncompressed);
 * parsing loads the whole document into memory. Callers that cache the
 * result should do so per-bill rather than as a single blob.
 */
class BillHistoryFetcher
{
    use FetchesHttp;

    /**
     * Base URL for the palegis.us bulk-data download endpoint.
     */
    private const BILL_HISTORY_BASE_URL = 'https://www.palegis.us/data/file';

    /**
     * Request configuration settings.
     */
    protected array $request;

    public function __construct()
    {
        $this->request = Config::get('palegis.request', []);
    }

    /**
     * Download the Bill History ZIP, extract its XML, and parse it.
     *
     * @return array{export_date: string, total: int, session: string, bills: array<int, array>}
     *
     * @throws PalegisException When the archive or XML is invalid
     */
    public function fetch(string $session): array
    {
        $body = $this->fetchBody(
            $this->url($session),
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

        return $this->parse($xml, $session);
    }

    /**
     * Build the Bill History Data download URL for a session.
     */
    protected function url(string $session): string
    {
        return self::BILL_HISTORY_BASE_URL.'?'.http_build_query([
            'documentType' => 'BillHistoryData',
            'session' => $session,
        ]);
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
    protected function parse(\SimpleXMLElement $xml, string $session): array
    {
        $bills = [];

        foreach ($xml->session as $sessionNode) {
            foreach ($sessionNode->bill as $bill) {
                $bills[] = $this->parseBill($bill);
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
    protected function parseBill(\SimpleXMLElement $bill): array
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
            'designator' => $this->designatorFromId($id),
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
    protected function designatorFromId(string $id): string
    {
        if (preg_match('/([A-Z]+)(\d+)$/', $id, $matches)) {
            return $matches[1].ltrim($matches[2], '0');
        }

        return $id;
    }
}
