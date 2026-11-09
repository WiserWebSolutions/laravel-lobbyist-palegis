<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillDocument;
use WiserWebSolutions\Lobbyist\Enums\BillDocumentType;

final class PalegisDocumentMapper
{
    /** @return list<BillDocument> */
    public static function linkedMemo(int|string $billId, string $title, ?string $url): array
    {
        if ($title === '' && blank($url)) {
            return [];
        }

        parse_str((string) parse_url($url ?? '', PHP_URL_QUERY), $query);
        $query = array_change_key_case($query, CASE_LOWER);
        $chamber = preg_match('~/(house|senate)/co-sponsorship/~i', $url ?? '', $match)
            ? strtolower($match[1]) : match (strtolower($query['chamber'] ?? '')) {
                'h', 'house' => 'house', 's', 'senate' => 'senate', default => null,
            };
        $memoId = $query['memoid'] ?? null;
        $id = $chamber !== null && is_string($memoId) && ctype_digit($memoId)
            ? 'palegis:memo:'.$chamber.':'.$memoId : 'palegis:memo:'.hash('sha256', $url ?: (string) $billId);

        return [new BillDocument(['id' => $id,
            'bill_id' => $billId, 'source' => 'palegis', 'type' => BillDocumentType::CosponsorshipMemo,
            'title' => $title ?: 'Co-sponsorship memorandum', 'url' => $url])];
    }

    /** @param array<string, mixed> $memo */
    public static function memo(array $memo, Bill $bill): BillDocument
    {
        return new BillDocument([
            'id' => 'palegis:memo:'.$memo['chamber'].':'.$memo['id'], 'bill_id' => $bill->id,
            'source' => 'palegis', 'type' => BillDocumentType::CosponsorshipMemo,
            'title' => $memo['subject'], 'body' => $memo['body'] ?? null,
            'url' => $memo['url'], 'mime' => ($memo['content_format'] ?? '') === 'pdf' ? 'application/pdf' : 'text/html',
            'published_at' => $memo['circulated_at'] ?? null,
            'authors' => array_map(fn (array $member): array => ['id' => $member['id'] ?? null, 'name' => $member['name']], $memo['members'] ?? []),
            'attachments' => $memo['attachments'] ?? [],
        ]);
    }
}
