<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

/**
 * Derives a bill's status and committee-referral history from its Bill
 * History action list.
 *
 * palegis.us reports no status code at all -- only a chronological list of
 * free-text actions ({@see BillHistoryFetcher::parseBill()}'s
 * `actions`), each with a `verb`, the `committee` it names (if any), and a
 * `date`. This walks that list in order and keeps whichever status the most
 * recent recognized verb implies -- later actions naturally supersede earlier
 * ones because the list is already chronological, so no explicit precedence
 * table is needed beyond "last match wins".
 *
 * {@see status()} returns one of the textual tokens the app-level
 * `App\Modules\PolicyPulse\Enums\BillStatus::fromSource()` (an enum this
 * package does not depend on) already accepts, so nothing beyond this mapping
 * is needed downstream. An action this cannot classify (a procedural note
 * like "Laid on the table," or a journal-page cross-reference) leaves the
 * running status untouched rather than resetting it.
 *
 * {@see referrals()} produces one row per committee-referral action, in the
 * shape `WiserWebSolutions\Lobbyist\Data\CommitteeReferral` expects
 * (`committee_id`, `name`, `chamber`, `date`) -- see
 * {@see PalegisMapper::billDto()}, which wraps this output into that DTO.
 * palegis.us publishes no numeric committee id (LegiScan's own
 * `referrals[].committee_id` is a LegiScan id), so `committee_id` here is a
 * synthetic, deterministic key (`"{chamber}:{committee name}"`) -- stable
 * across referrals from this source and never colliding with LegiScan's own
 * numeric ids.
 *
 * {@see history()} produces the `{action, date, chamber, importance}` shape
 * `WiserWebSolutions\Lobbyist\Data\BillHistoryEntry` expects (LegiScan's own
 * `history` array uses the same shape verbatim). Every date this class emits
 * -- here and in {@see referrals()} -- is normalized from the export's
 * `MM/DD/YY` to `YYYY-MM-DD` by {@see normalizeDate()}: a downstream consumer
 * of either DTO may write these dates straight into a raw DB insert/upsert
 * (bypassing Eloquent's own date casting), so an un-normalized `MM/DD/YY`
 * string would corrupt a column silently rather than merely sorting wrong.
 */
class ActionStatusMapper
{
    /**
     * Verb substrings (checked case-insensitively against the start of each
     * action's `verb`) mapped to the status they imply, in no particular
     * order -- the caller applies them in the actions' own chronological
     * order, so whichever recognized verb comes last in the bill's history
     * wins.
     *
     * @var array<string, string>
     */
    private const VERB_STATUSES = [
        'act no.' => 'chaptered',
        'approved by the governor' => 'chaptered',
        'signed by the governor' => 'chaptered',
        'veto' => 'vetoed',
        'override' => 'overridden',
        'third consideration and final passage' => 'passed',
        'final passage' => 'passed',
        're-reported as committed' => 'reported_favourably',
        're-reported as amended' => 'reported_favourably',
        'reported as committed' => 'reported_favourably',
        'reported as amended' => 'reported_favourably',
        'reported negatively' => 'reported_unfavourably',
        'reported unfavorably' => 'reported_unfavourably',
        're-committed to' => 'referred',
        're-referred to' => 'referred',
        'referred to' => 'referred',
    ];

    /**
     * Verb substrings (checked case-insensitively) that mark a committee
     * referral -- a subset of {@see VERB_STATUSES}' "referred" keys, kept
     * separate because not every status-bearing verb is also a referral (a
     * committee reports a bill back out; that is not a new referral to it).
     */
    private const REFERRAL_VERBS = ['referred to', 're-referred to', 're-committed to'];

    /**
     * @param  array<int, array{verb?: string, committee?: string, date?: string, chamber?: string}>  $actions
     * @return array{status: ?string, status_date: ?string}
     */
    public static function status(array $actions): array
    {
        $status = null;
        $statusDate = null;

        foreach ($actions as $action) {
            $verb = strtolower(trim((string) ($action['verb'] ?? '')));

            if ($verb === '') {
                continue;
            }

            foreach (self::VERB_STATUSES as $needle => $value) {
                if (str_starts_with($verb, $needle) || str_contains($verb, $needle)) {
                    $status = $value;
                    $statusDate = ($action['date'] ?? '') !== '' ? $action['date'] : $statusDate;

                    break;
                }
            }
        }

        return ['status' => $status ?? 'introduced', 'status_date' => $statusDate];
    }

    /**
     * @param  array<int, array{verb?: string, committee?: string, date?: string, chamber?: string}>  $actions
     * @return list<array{committee_id: string, name: string, chamber: string, date: ?string}>
     */
    public static function referrals(array $actions): array
    {
        $referrals = [];

        foreach ($actions as $action) {
            $verb = strtolower(trim((string) ($action['verb'] ?? '')));
            $committee = trim((string) ($action['committee'] ?? ''));

            if ($committee === '' || ! self::isReferralVerb($verb)) {
                continue;
            }

            $chamber = strtoupper((string) ($action['chamber'] ?? ''));

            $referrals[] = [
                'committee_id' => $chamber.':'.strtoupper($committee),
                'name' => self::titleCase($committee),
                'chamber' => $chamber,
                'date' => self::normalizeDate($action['date'] ?? null),
            ];
        }

        return $referrals;
    }

    /**
     * The bill's procedural actions, in the shape
     * `App\Modules\PolicyPulse\Sync\BillEventRecorder::historyEvents()`
     * expects -- LegiScan's own `history` array, which that class was
     * written against, uses this same shape natively.
     *
     * @param  array<int, array{verb?: string, full_action?: string, date?: string, chamber?: string}>  $actions
     * @return list<array{action: string, date: ?string, chamber: ?string, importance: bool}>
     */
    public static function history(array $actions): array
    {
        $history = [];

        foreach ($actions as $action) {
            $description = trim((string) ($action['full_action'] ?? ''));

            if ($description === '') {
                continue;
            }

            $history[] = [
                'action' => $description,
                'date' => self::normalizeDate($action['date'] ?? null),
                'chamber' => ($action['chamber'] ?? '') !== '' ? $action['chamber'] : null,

                // palegis.us flags no action as procedurally significant the
                // way LegiScan's own `importance` does, so this approximates
                // it: an action whose verb also drives a status transition
                // (see VERB_STATUSES) is exactly the subset a reader would
                // call a milestone rather than routine housekeeping (a
                // second reading, a journal-page cross-reference).
                'importance' => self::isMajorVerb((string) ($action['verb'] ?? '')),
            ];
        }

        return $history;
    }

    private static function isMajorVerb(string $verb): bool
    {
        $verb = strtolower(trim($verb));

        if ($verb === '') {
            return false;
        }

        foreach (self::VERB_STATUSES as $needle => $ignored) {
            if (str_starts_with($verb, $needle) || str_contains($verb, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The export reports every date as `MM/DD/YY`. Both {@see referrals()}
     * and {@see history()} feed callers that write the result straight into
     * a raw DB insert/upsert, bypassing Eloquent's own date casting -- an
     * un-normalized date string would corrupt the column rather than merely
     * sort wrong, so every date this class emits goes through here first.
     */
    private static function normalizeDate(?string $date): ?string
    {
        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        $parsed = \DateTime::createFromFormat('m/d/y', $date);

        return $parsed !== false ? $parsed->format('Y-m-d') : null;
    }

    private static function isReferralVerb(string $verb): bool
    {
        foreach (self::REFERRAL_VERBS as $needle) {
            if (str_contains($verb, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The action's committee arrives shouting ("EDUCATION"); re-cased to match
     * how a committee name reads everywhere else this package reports one --
     * see {@see PalegisMapper::normaliseCommitteeName()}.
     */
    private static function titleCase(string $committee): string
    {
        if ($committee === mb_strtoupper($committee)) {
            return mb_convert_case(mb_strtolower($committee), MB_CASE_TITLE, 'UTF-8');
        }

        return $committee;
    }
}
