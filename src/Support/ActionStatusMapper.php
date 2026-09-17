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
 * shape `App\Modules\PolicyPulse\Sync\CommitteeSynchronizer::referralRowsFor()`
 * expects (`committee_id`, `name`, `chamber`, `date`). palegis.us publishes no
 * numeric committee id (LegiScan's `referrals[].committee_id` is a LegiScan
 * id), so `committee_id` here is a synthetic, deterministic key
 * (`"{chamber}:{committee name}"`) -- stable across referrals from this
 * source and never colliding with LegiScan's own numeric ids.
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
                'date' => ($action['date'] ?? '') !== '' ? $action['date'] : null,
            ];
        }

        return $referrals;
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
