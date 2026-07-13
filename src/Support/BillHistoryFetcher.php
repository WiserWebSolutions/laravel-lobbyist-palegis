<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use Illuminate\Support\Facades\Config;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\Concerns\FetchesHttp;

/**
 * Downloads and parses the palegis.us Bill History Data export for a
 * session. Pure fetch+parse — no caching knowledge.
 *
 * The export is large (thousands of bills / tens of MB uncompressed once
 * unzipped), so {@see fetchStream()} never holds the parsed side of it in
 * memory at once: the (much smaller, compressed) ZIP is downloaded and
 * written to a temp file, its XML entry is copied to a second temp file,
 * and that file is read one <bill> at a time via XMLReader — expanding just
 * that element into SimpleXML — rather than parsed whole into a DOM tree
 * and a single giant PHP array. {@see fetch()} is a convenience wrapper
 * around it for callers that explicitly want (and can afford) the whole
 * array at once.
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
     * Download, parse, and fully materialize the Bill History export for a
     * session. Prefer {@see fetchStream()} whenever every bill is about to
     * be transformed or cached anyway.
     *
     * @return array{export_date: string, total: int, session: string, bills: array<int, array>}
     *
     * @throws PalegisException When the archive or XML is invalid
     */
    public function fetch(string $session): array
    {
        $bills = $this->fetchStream($session);
        $collected = iterator_to_array($bills, false);
        $meta = $bills->getReturn();

        return [
            'export_date' => $meta['export_date'] ?? '',
            'total' => $meta['total'] ?? count($collected),
            'session' => $session,
            'bills' => $collected,
        ];
    }

    /**
     * Stream a session's Bill History export one bill at a time.
     *
     * Never holds more than one bill's parsed data (plus XMLReader's own
     * small lookahead buffer) in memory: the ZIP downloads straight to a
     * temp file, its XML entry is copied to a second temp file, and
     * XMLReader walks that file expanding one <bill> subtree at a time into
     * SimpleXML (so the existing per-bill parsing logic can stay the same).
     * Both temp files are removed once the generator is exhausted or
     * abandoned.
     *
     * The export's `exportDate`/`totalDocuments` attributes (on the
     * document root, read before the first bill is yielded) are available
     * via the generator's return value once it's fully consumed, e.g.
     * `$meta = $bills->getReturn();` after a `foreach`.
     *
     * @return \Generator<int, array, mixed, array{export_date: string, total: int}>
     *
     * @throws PalegisException
     */
    public function fetchStream(string $session): \Generator
    {
        $zipPath = $this->downloadZip($session);

        try {
            $xmlPath = $this->extractXmlToTempFile($zipPath);
        } finally {
            @unlink($zipPath);
        }

        try {
            return yield from $this->streamXml($xmlPath);
        } finally {
            @unlink($xmlPath);
        }
    }

    /**
     * @throws PalegisException
     */
    protected function downloadZip(string $session): string
    {
        $body = $this->fetchBody(
            $this->url($session),
            "The session [{$session}] may be invalid or not yet published. "
            ."Session ids look like '2025_0' (regular) or '2025_1' (special session)."
        );

        $tmp = tempnam(sys_get_temp_dir(), 'palegis_bh_zip_');

        if ($tmp === false) {
            throw new PalegisException('Unable to create a temp file for the Bill History archive');
        }

        file_put_contents($tmp, $body);

        return $tmp;
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
     * Copy the first .xml entry out of a ZIP archive into its own temp file,
     * streaming through ZipArchive's own stream rather than reading the
     * entry into a PHP string.
     *
     * @throws PalegisException
     */
    protected function extractXmlToTempFile(string $zipPath): string
    {
        $zip = new \ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new PalegisException('Unable to open the Bill History archive');
        }

        try {
            $entryName = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), '.xml')) {
                    $entryName = $zip->getNameIndex($i);
                    break;
                }
            }

            if ($entryName === null) {
                throw new PalegisException('No XML entry found in the Bill History archive');
            }

            $source = $zip->getStream($entryName);

            if ($source === false) {
                throw new PalegisException('Unable to read the XML entry from the Bill History archive');
            }

            $destPath = tempnam(sys_get_temp_dir(), 'palegis_bh_xml_');

            if ($destPath === false) {
                fclose($source);

                throw new PalegisException('Unable to create a temp file for the Bill History XML');
            }

            $dest = fopen($destPath, 'wb');
            stream_copy_to_stream($source, $dest);
            fclose($source);
            fclose($dest);

            return $destPath;
        } finally {
            $zip->close();
        }
    }

    /**
     * Walk a Bill History XML file one <bill> at a time via XMLReader,
     * expanding just that element into a SimpleXMLElement for
     * {@see parseBill()} rather than parsing the whole document at once.
     *
     * @return \Generator<int, array, mixed, array{export_date: string, total: int}>
     *
     * @throws PalegisException
     */
    protected function streamXml(string $path): \Generator
    {
        $reader = new \XMLReader;
        $previousErrorHandling = libxml_use_internal_errors(true);

        if (! $reader->open($path)) {
            libxml_use_internal_errors($previousErrorHandling);

            throw new PalegisException('Invalid XML in Bill History export');
        }

        $meta = ['export_date' => '', 'total' => 0];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->name === 'historyExport') {
                    $meta['export_date'] = (string) $reader->getAttribute('exportDate');
                    $meta['total'] = (int) $reader->getAttribute('totalDocuments');

                    continue;
                }

                if ($reader->name !== 'bill') {
                    continue;
                }

                $node = $reader->expand();
                $dom = new \DOMDocument;
                $dom->appendChild($dom->importNode($node, true));

                yield $this->parseBill(simplexml_import_dom($dom->documentElement));

                // Skip past the subtree we just expanded rather than
                // re-walking its children node by node.
                $reader->next();
            }

            if (libxml_get_errors() !== []) {
                throw new PalegisException('Invalid XML in Bill History export');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
        }

        return $meta;
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
