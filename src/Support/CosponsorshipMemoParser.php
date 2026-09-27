<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

/** Parses the public memo search and detail pages, independently of bill exports. */
class CosponsorshipMemoParser
{
    /** @return list<array{id: string, name: string, current: bool}> */
    public static function sessions(string $html): array
    {
        $xpath = self::document($html);
        $sessions = [];

        foreach (self::nodes($xpath, '//select[@id="session"]/option') as $option) {
            if ($option instanceof DOMElement && preg_match('/^\d{4}_\d+$/', $option->getAttribute('value'))) {
                $sessions[] = ['id' => $option->getAttribute('value'), 'name' => self::text($option), 'current' => $option->hasAttribute('selected')];
            }
        }

        if ($sessions === []) {
            throw new PalegisException('Memo session picker not found; palegis.us may have changed its markup.');
        }

        return $sessions;
    }

    /**
     * @return array{session: string, total: int, truncated: bool, member_ids: list<string>, items: list<array<string, mixed>>}
     */
    public static function index(string $html, string $chamber, string $session): array
    {
        $xpath = self::document($html);
        self::assertSession($xpath, $session);
        $text = self::text(self::first($xpath, '//main'));

        if (preg_match('/Showing\s+([\d,]+)(?:\s+of\s+([\d,]+))?\s+results?\b/i', $text, $match)) {
            $total = (int) str_replace(',', '', $match[2] ?? $match[1]);
        } elseif (preg_match('/No (?:memos|results)(?: were)? found/i', $text)) {
            $total = 0;
        } else {
            throw new PalegisException('Memo result count not found; refusing to treat an unrecognized page as an empty search.');
        }

        $items = [];
        foreach (self::nodes($xpath, '//*[@data-memoid]') as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }
            $id = $card->getAttribute('data-memoid');
            $subject = self::text(self::first($xpath, './/a[contains(@href, "co-sponsorship/memo")]', $card));
            if (! ctype_digit($id) || $subject === '') {
                throw new PalegisException('Incomplete memo search result.');
            }
            $members = [];
            foreach (self::nodes($xpath, './/*[@data-clipboard-cell="2"]//a', $card) as $link) {
                if ($link instanceof DOMElement && preg_match('~/members/bio/(\d+)/~', $link->getAttribute('href'), $member)) {
                    $members[$member[1]] = ['id' => $member[1], 'name' => self::text($link), 'url' => self::url($link->getAttribute('href'))];
                }
            }
            $legislation = [];
            foreach (self::nodes($xpath, './/*[@data-clipboard-cell="4"]//a', $card) as $link) {
                if ($link instanceof DOMElement) {
                    $number = BillIdentifier::normalize(self::text($link));
                    if (preg_match('/^[HS][BR]\d+$/', $number)) {
                        $legislation[] = ['session' => $session, 'number' => $number, 'url' => self::url($link->getAttribute('href'))];
                    }
                }
            }
            $date = self::text(self::first($xpath, './/*[@data-clipboard-cell="1"]', $card));
            $items[$id] = [
                'id' => $id, 'subject' => $subject, 'url' => self::memoUrl($chamber, $id),
                'chamber' => $chamber, 'session' => $session,
                'circulated_at' => $date !== '' ? self::date($date) : null,
                'members' => array_values($members), 'legislation' => $legislation,
            ];
        }

        $truncated = $total > count($items);
        if (($total > 0 && $items === []) || count($items) > $total
            || ($truncated && ! str_contains($text, 'Maximum result limit'))) {
            throw new PalegisException('Memo result count does not match the parsed records.');
        }

        $members = [];
        foreach (self::nodes($xpath, '//select[@id="memberID"]/option') as $option) {
            if ($option instanceof DOMElement && ctype_digit($option->getAttribute('value'))) {
                $members[] = $option->getAttribute('value');
            }
        }

        return ['session' => $session, 'total' => $total, 'truncated' => $truncated, 'member_ids' => $members, 'items' => array_values($items)];
    }

    /**
     * Source HTML is untrusted archival content, not safe to render without sanitizing.
     *
     * @return array{id: string, chamber: string, session: string, subject: string, circulated_at: string, updated_at: ?string, body: string, body_html: string, members: list<array{id: string, name: string, url: string}>, legislation: list<array{session: string, number: string, url: string}>, attachments: list<array{title: string, url: string}>, documents: list<array{title: string, text: string}>, url: string}
     */
    public static function memo(string $html, string $chamber, string $id): array
    {
        $xpath = self::document($html);
        $title = self::text(self::first($xpath, '//title'));
        if (! preg_match('/'.ucfirst($chamber).' Co-Sponsorship Memo '.preg_quote($id, '/').' Information;\s*(\d{4})-\d{4}\s+(Regular Session|Special Session\s*#\s*(\d+))/i', $title, $match)) {
            throw new PalegisException('Unexpected memo identity at ['.self::memoUrl($chamber, $id).'].');
        }
        $session = $match[1].'_'.($match[3] ?? '0');
        $heading = self::first($xpath, '//main//div[normalize-space(.)="Memo" and contains(@class,"h3")]');
        $body = $heading ? self::first($xpath, 'following-sibling::div[1]', $heading) : null;
        $root = $heading?->parentNode;
        if (! $root || ! $body) {
            throw new PalegisException('Memo content section not found.');
        }
        $subject = self::text(self::first($xpath, './/div[contains(@class,"header-title")]', $root));
        $date = self::text(self::first($xpath, './/div[contains(@class,"header-pretitle")]', $root));
        $date = preg_replace('/\s+to\s+All\s+(House|Senate)\s+Members.*$/i', '', $date);
        if ($subject === '' || $date === '') {
            throw new PalegisException('Memo subject or circulation date missing.');
        }

        $bodyHtml = '';
        foreach ($body->childNodes as $child) {
            $bodyHtml .= $xpath->document->saveHTML($child);
        }
        $bodyText = self::plainHtml($bodyHtml);
        $members = [];
        foreach (self::nodes($xpath, './/div[normalize-space(.)="Circulated By" or normalize-space(.)="Along With"]', $root) as $authorHeading) {
            $authors = self::first($xpath, 'following-sibling::div[1]', $authorHeading);
            if (! $authors) {
                continue;
            }
            foreach (self::nodes($xpath, './/a[contains(@href,"/members/bio/")]', $authors) as $link) {
                if ($link instanceof DOMElement && preg_match('~/members/bio/(\d+)/~', $link->getAttribute('href'), $member)) {
                    $members[$member[1]] = ['id' => $member[1], 'name' => self::text($link), 'url' => self::url($link->getAttribute('href'))];
                }
            }
        }

        $legislation = [];
        $documents = [];
        foreach (self::nodes($xpath, './/div[contains(concat(" ",normalize-space(@class)," ")," portlet ")]', $root) as $card) {
            $documents[] = [
                'title' => self::text(self::first($xpath, './/span[@class="portlet-title"]', $card)),
                'text' => self::text(self::first($xpath, './/div[contains(@class,"card-body")]', $card)),
            ];
            foreach (self::nodes($xpath, './/a[contains(@href,"/legislation/bills/") and contains(concat(" ",normalize-space(@class)," ")," btn ")]', $card) as $link) {
                if (! $link instanceof DOMElement) {
                    continue;
                }
                $url = self::url($link->getAttribute('href'));
                if (! preg_match('~/legislation/bills/(\d{4})/(?:([^/]+)/)?([hs][br])0*(\d+)~i', $url, $bill)) {
                    throw new PalegisException("Unrecognized introduced-bill URL [{$url}].");
                }
                $number = strtoupper($bill[3]).((int) $bill[4]);
                $legislation[$number] = ['session' => $session, 'number' => $number, 'url' => $url];
            }
        }

        $attachments = [];
        foreach (self::nodes($xpath, './/a[contains(translate(@href,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"attachmentid=")]', $root) as $link) {
            if ($link instanceof DOMElement) {
                $url = self::url($link->getAttribute('href'));
                $attachments[$url] = ['title' => self::text($link), 'url' => $url];
            }
        }
        $updated = null;
        foreach (self::nodes($xpath, './/em', $root) as $node) {
            if (preg_match('/^Last updated on (.+)$/i', self::text($node), $updatedMatch)) {
                $updated = self::date($updatedMatch[1]);
            }
        }
        if ($members === [] || ($bodyText === '' && $attachments === [])) {
            throw new PalegisException('Memo authors or content missing.');
        }

        return [
            'id' => $id, 'chamber' => $chamber, 'session' => $session,
            'subject' => $subject, 'circulated_at' => self::date($date), 'updated_at' => $updated,
            'body' => $bodyText, 'body_html' => $bodyHtml, 'members' => array_values($members),
            'content_format' => 'html',
            'legislation' => array_values($legislation), 'attachments' => array_values($attachments),
            'documents' => $documents, 'url' => self::memoUrl($chamber, $id),
        ];
    }

    /** @return list<DOMNode> */
    private static function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $result = $xpath->query($expression, $context);
        if ($result === false) {
            throw new PalegisException('Invalid memo page selector: '.$expression);
        }
        $nodes = [];
        foreach ($result as $node) {
            if ($node instanceof DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private static function first(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?DOMNode
    {
        return self::nodes($xpath, $expression, $context)[0] ?? null;
    }

    private static function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private static function assertSession(DOMXPath $xpath, string $session): void
    {
        $year = self::first($xpath, '//input[@name="sessYr"]');
        $index = self::first($xpath, '//input[@name="sessInd"]');
        if (! $year instanceof DOMElement || ! $index instanceof DOMElement
            || $year->getAttribute('value').'_'.$index->getAttribute('value') !== $session) {
            throw new PalegisException("Memo search returned the wrong session; expected [{$session}].");
        }
    }

    private static function text(?DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $node->textContent ?? '')));
    }

    private static function plainHtml(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        $html = preg_replace('~<br\s*/?>|</(?:p|div|li|tr|h[1-6])>~i', "\n", $html);
        $text = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim(preg_replace('/\n{3,}/', "\n\n", preg_replace('/[^\S\n]+/u', ' ', $text)));
    }

    private static function date(string $value): string
    {
        try {
            return CarbonImmutable::parse($value, 'America/New_York')->utc()->toIso8601String();
        } catch (\Throwable $exception) {
            throw new PalegisException("Invalid memo date [{$value}].", previous: $exception);
        }
    }

    private static function url(string $value): string
    {
        $value = trim($value);

        return str_starts_with($value, 'https://') ? $value : 'https://www.palegis.us/'.ltrim($value, '/');
    }

    private static function memoUrl(string $chamber, string $id): string
    {
        return "https://www.palegis.us/{$chamber}/co-sponsorship/memo?memoID={$id}";
    }
}
