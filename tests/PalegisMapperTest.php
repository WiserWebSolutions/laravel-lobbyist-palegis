<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use WiserWebSolutions\LaravelPalegis\Support\PalegisMapper;
use WiserWebSolutions\Lobbyist\Data\BillText;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\SponsorType;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;
use WiserWebSolutions\Lobbyist\Enums\VotePosition;
use WiserWebSolutions\Lobbyist\Exceptions\LobbyistException;

class PalegisMapperTest extends TestCase
{
    public function test_maps_vote_without_tallies(): void
    {
        $vote = PalegisMapper::vote([
            'title' => 'House Vote on HB100',
            'link' => 'https://www.palegis.us/vote/1',
            'guid' => 'v1',
        ], Chamber::House);

        $this->assertSame('v1', $vote->id);
        $this->assertSame(Chamber::House, $vote->chamber);
        $this->assertNull($vote->yea);
    }

    /**
     * @return array{session_year: string, session_index: string, date: string, time: string, bill: array{year: string, body: string, type: string, number: string}|null, action: string, tallies: array<string, int>, positions: list<array{id: string, name: string, party: string, district: string, position: string}>}
     */
    private function rollCallRecord(): array
    {
        return [
            'session_year' => '2025',
            'session_index' => '0',
            'date' => '2026-07-23',
            'time' => '3:07 PM',
            'bill' => ['year' => '2025', 'body' => 'H', 'type' => 'B', 'number' => '1042'],
            'action' => 'CONCURRENCE',
            'tallies' => ['yea' => 102, 'nay' => 100, 'no_vote' => 0, 'leave' => 1],
            'positions' => [
                ['id' => '1933', 'name' => 'Aerion Abney', 'party' => 'D', 'district' => '19', 'position' => 'Yea'],
                ['id' => '2029', 'name' => 'Marc Anderson', 'party' => 'R', 'district' => '116', 'position' => 'Nay'],
            ],
        ];
    }

    public function test_vote_from_roll_call_maps_the_tallies_and_result(): void
    {
        $vote = PalegisMapper::voteFromRollCall($this->rollCallRecord(), 1350, Chamber::House);

        $this->assertSame('house:1350', $vote->id);
        $this->assertSame(Chamber::House, $vote->chamber);
        $this->assertSame(102, $vote->yea);
        $this->assertSame(100, $vote->nay);
        $this->assertSame(0, $vote->notVoting);
        $this->assertSame(1, $vote->absent);
        $this->assertTrue($vote->passed);
        $this->assertSame('2026-07-23', $vote->date?->format('Y-m-d'));
        $this->assertSame('CONCURRENCE', $vote->description);
    }

    public function test_vote_from_roll_call_rebuilds_the_bill_history_id_format(): void
    {
        $vote = PalegisMapper::voteFromRollCall($this->rollCallRecord(), 1350, Chamber::House);

        // Not the bare designator ("HB1042") -- the same "20250HB0017"-style
        // id billFromHistory() uses for Bill::$id, since that is what
        // VoteSynchronizer's bill lookup is keyed on.
        $this->assertSame('20250HB1042', $vote->billId);
    }

    public function test_vote_from_roll_call_has_no_bill_id_for_a_procedural_roll_call(): void
    {
        $record = $this->rollCallRecord();
        $record['bill'] = null;

        $vote = PalegisMapper::voteFromRollCall($record, 1, Chamber::Senate);

        $this->assertNull($vote->billId);
        $this->assertSame('senate:1', $vote->id);
    }

    public function test_vote_from_roll_call_maps_every_members_individual_position(): void
    {
        $vote = PalegisMapper::voteFromRollCall($this->rollCallRecord(), 1350, Chamber::House);
        $positions = $vote->positions();

        $this->assertCount(2, $positions);
        $this->assertSame('1933', $positions->first()->legislatorId);
        $this->assertSame(VotePosition::Yea, $positions->first()->position);
        $this->assertSame(VotePosition::Nay, $positions->last()->position);
    }

    /**
     * @return array{committee_code: string, rc_num: int, committee: string, date: string, bill: array{year: string, body: string, type: string, number: string}|null, motion: string, tallies: array<string, int>, positions: list<array{id: string, name: string, position: string}>}
     */
    private function committeeRollCallRecord(): array
    {
        return [
            'committee_code' => '64',
            'rc_num' => 1920,
            'committee' => 'Housing & Community Development',
            'date' => '2026-04-13',
            'bill' => ['year' => '2025', 'body' => 'H', 'type' => 'B', 'number' => '2367'],
            'motion' => 'Report Bill As Committed',
            'tallies' => ['yea' => 14, 'nay' => 12, 'no_vote' => 0],
            'positions' => [
                ['id' => '1825', 'name' => 'Brandon Markosek', 'position' => 'Yea'],
                ['id' => '1933', 'name' => 'Aerion Abney', 'position' => 'Nay'],
            ],
        ];
    }

    public function test_vote_from_committee_roll_call_sets_the_committee_and_tallies(): void
    {
        $vote = PalegisMapper::voteFromCommitteeRollCall($this->committeeRollCallRecord(), '2025_0', Chamber::House);

        $this->assertSame('Housing & Community Development', $vote->committee);
        $this->assertSame(14, $vote->yea);
        $this->assertSame(12, $vote->nay);
        $this->assertTrue($vote->passed);
        $this->assertSame('2026-04-13', $vote->date?->format('Y-m-d'));
        $this->assertSame('Report Bill As Committed', $vote->description);
    }

    public function test_vote_from_committee_roll_call_id_is_distinct_from_a_floor_votes(): void
    {
        // A committee and a floor roll call can otherwise share the same
        // rc_num -- they are entirely different sequences.
        $vote = PalegisMapper::voteFromCommitteeRollCall($this->committeeRollCallRecord(), '2025_0', Chamber::House);

        $this->assertSame('committee:house:64:1920', $vote->id);
    }

    public function test_vote_from_committee_roll_call_rebuilds_the_bill_history_id_format(): void
    {
        $vote = PalegisMapper::voteFromCommitteeRollCall($this->committeeRollCallRecord(), '2025_0', Chamber::House);

        $this->assertSame('20250HB2367', $vote->billId);
    }

    public function test_vote_from_committee_roll_call_has_no_bill_id_without_a_bill(): void
    {
        $record = $this->committeeRollCallRecord();
        $record['bill'] = null;

        $vote = PalegisMapper::voteFromCommitteeRollCall($record, '2025_0', Chamber::House);

        $this->assertNull($vote->billId);
    }

    public function test_vote_from_committee_roll_call_maps_every_members_individual_position(): void
    {
        $vote = PalegisMapper::voteFromCommitteeRollCall($this->committeeRollCallRecord(), '2025_0', Chamber::House);
        $positions = $vote->positions();

        $this->assertCount(2, $positions);
        $this->assertSame('1825', $positions->first()->legislatorId);
        $this->assertSame(VotePosition::Yea, $positions->first()->position);
        $this->assertSame(VotePosition::Nay, $positions->last()->position);
    }

    public function test_maps_legislator(): void
    {
        $legislator = PalegisMapper::legislator([
            'title' => 'Rep. Jane Doe',
            'link' => 'https://www.palegis.us/member/1',
            'guid' => 'm1',
        ], Chamber::House);

        $this->assertSame('Rep. Jane Doe', $legislator->name);
        $this->assertSame(Chamber::House, $legislator->chamber);
        $this->assertSame(StateEnum::PA, $legislator->state);
    }

    public function test_maps_legislator_from_members_page(): void
    {
        $legislator = PalegisMapper::legislatorFromMembersPage([
            'id' => '209',
            'name' => 'William F. Adolph',
            'party' => 'R',
            'district' => '165',
            'county' => 'DELAWARE',
            'leadership' => 'Minority Whip',
            'image_url' => 'https://www.palegis.us/resources/images/members/200/209.jpg',
            'url' => 'https://www.palegis.us/house/members/bio/209/rep-adolph',
        ], Chamber::House);

        $this->assertSame('209', $legislator->id);
        $this->assertSame('William F. Adolph', $legislator->name);
        $this->assertSame(Chamber::House, $legislator->chamber);
        $this->assertSame(StateEnum::PA, $legislator->state);
        $this->assertSame('DELAWARE', $legislator->county);
        $this->assertSame('Minority Whip', $legislator->role);
        $this->assertSame('https://www.palegis.us/resources/images/members/200/209.jpg', $legislator->imageUrl);
        $this->assertTrue($legislator->active);
    }

    public function test_maps_legislator_from_members_page_with_no_leadership_title(): void
    {
        $legislator = PalegisMapper::legislatorFromMembersPage([
            'id' => '209',
            'name' => 'William F. Adolph',
            'party' => 'R',
            'district' => '165',
            'county' => 'DELAWARE',
            'leadership' => '',
            'image_url' => 'https://www.palegis.us/resources/images/members/200/209.jpg',
            'url' => 'https://www.palegis.us/house/members/bio/209/rep-adolph',
        ], Chamber::House);

        $this->assertNull($legislator->role);
    }

    public function test_current_session_is_pennsylvania(): void
    {
        $session = PalegisMapper::currentSession();

        $this->assertSame(StateEnum::PA, $session->state);
    }

    public function test_maps_a_chamber_session_day(): void
    {
        $day = PalegisMapper::chamberSessionDay([
            'date' => '2026-09-28',
            'voting_day' => true,
            'url' => 'https://www.palegis.us/senate/session/info?SessDate=09/28/2026',
        ], Chamber::Senate);

        $this->assertSame(Chamber::Senate, $day->chamber);
        $this->assertSame('2026-09-28', $day->date?->format('Y-m-d'));
        $this->assertTrue($day->votingDay);
        $this->assertSame('senate:2026-09-28', $day->identifier);
        $this->assertSame('https://www.palegis.us/senate/session/info?SessDate=09/28/2026', $day->url);
    }

    public function test_a_non_voting_session_day_maps_accordingly(): void
    {
        $day = PalegisMapper::chamberSessionDay([
            'date' => '2026-01-06',
            'voting_day' => false,
            'url' => 'https://www.palegis.us/senate/session/info?SessDate=01/06/2026',
        ], Chamber::Senate);

        $this->assertFalse($day->votingDay);
    }

    private function billRecord(): array
    {
        return [
            'id' => '20250HB0017',
            'designator' => 'HB17',
            'short_title' => 'Cursive handwriting',
            'body' => 'H',
            'last_update' => 'May 13, 2026 12:05:00 PM EDT',
            'cosponsorship_memo' => ['text' => '', 'url' => ''],
            'sponsors' => [
                ['name' => 'WATRO', 'party' => 'R', 'body' => 'H', 'district' => '116', 'sequence' => '01'],
            ],
            'printers_numbers' => [
                ['sequence' => '01', 'number' => '0002', 'pdf_url' => 'https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0002'],
            ],
            'actions' => [
                ['sequence' => '01', 'verb' => 'Referred to', 'committee' => 'EDUCATION', 'chamber' => 'H', 'full_action' => 'Referred to EDUCATION', 'date' => '01/08/25'],
                ['sequence' => '02', 'verb' => 'Reported as committed,', 'committee' => 'EDUCATION', 'chamber' => 'H', 'full_action' => 'Reported as committed', 'date' => '03/12/25'],
            ],
        ];
    }

    public function test_bill_from_history_includes_the_full_raw_record(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $this->assertSame('HB17', $bill->number);
        $this->assertSame('Reported as committed', $bill->lastAction);
        $this->assertArrayHasKey('raw', $bill->meta);
        $this->assertCount(1, $bill->meta['raw']['sponsors']);
        $this->assertCount(2, $bill->meta['raw']['actions']);
    }

    public function test_bill_summary_from_history_omits_the_raw_record(): void
    {
        $bill = PalegisMapper::billSummaryFromHistory($this->billRecord());

        $this->assertSame('HB17', $bill->number);
        $this->assertSame('Reported as committed', $bill->lastAction);
        $this->assertStringContainsString('HB0017/PN0002', $bill->url);
        $this->assertArrayNotHasKey('raw', $bill->meta);
    }

    public function test_bill_text_exposes_the_html_and_pdf_links(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $text = $bill->text();

        $this->assertInstanceOf(BillText::class, $text);
        $this->assertSame('https://www.palegis.us/legislation/bills/text/HTM/2025/0/HB0017/PN0002', $text->toHTML());
        $this->assertSame('https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0002', $text->toPDF());
    }

    public function test_bill_text_to_string_throws_without_a_driver_fetch(): void
    {
        // Bill::text() is a pure read of already-mapped data — it never
        // performs I/O on its own. Fetching the literal text requires
        // PalegisDriver::billText(), which is what actually calls the HTTP
        // client and strips the HTML down to plain text.
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $this->expectException(LobbyistException::class);
        $this->expectExceptionMessageMatches('/does not have bill text support \(toString\(\)\)/');

        $bill->text()->toString();
    }

    public function test_bill_text_falls_back_to_unsupported_without_a_printers_number(): void
    {
        $record = $this->billRecord();
        $record['printers_numbers'] = [];

        $bill = PalegisMapper::billFromHistory($record);

        $this->assertCount(0, $bill->texts());
        $this->expectException(LobbyistException::class);

        $bill->text()->toHTML();
    }

    public function test_bill_text_history_maps_each_printers_number_with_no_content(): void
    {
        $record = $this->billRecord();
        $record['printers_numbers'] = [
            ['sequence' => '01', 'number' => '0002', 'pdf_url' => 'https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0002'],
            ['sequence' => '02', 'number' => '0101', 'pdf_url' => 'https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0101'],
        ];
        $record['actions'] = [
            ['full_action' => 'Referred to EDUCATION', 'date' => '01/08/25', 'printers_number' => '0002'],
            ['full_action' => 'Amended on third reading', 'date' => '03/12/25', 'printers_number' => '0101'],
        ];

        $history = PalegisMapper::billTextHistory($record);

        $this->assertCount(2, $history);
        $this->assertContainsOnlyInstancesOf(BillText::class, $history);
        $this->assertSame('https://www.palegis.us/legislation/bills/text/HTM/2025/0/HB0017/PN0101', $history->last()->url);
        $this->assertSame('text/html', $history->last()->mime);
        $this->assertSame('01/08/25', $history->first()->date?->format('m/d/y'));
        $this->assertNull($history->first()->content);
        $this->assertSame('20250HB0017', $history->first()->billId);
    }

    public function test_bill_text_history_leaves_date_null_when_no_action_references_the_printers_number(): void
    {
        $record = $this->billRecord();

        $history = PalegisMapper::billTextHistory($record);

        $this->assertCount(1, $history);
        $this->assertNull($history->first()->date);
    }

    public function test_bill_from_history_derives_status_from_its_action_history(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        // The fixture's last recognized action is "Reported as committed,".
        $this->assertSame('reported_favourably', $bill->status);
        $this->assertSame('03/12/25', $bill->statusDate?->format('m/d/y'));
    }

    public function test_bill_from_history_uses_last_update_as_the_change_hash(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $this->assertSame('May 13, 2026 12:05:00 PM EDT', $bill->changeHash);
    }

    public function test_bill_from_history_falls_back_to_the_short_title_with_no_cosponsorship_memo(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $this->assertSame('Cursive handwriting', $bill->description);
    }

    public function test_bill_from_history_prefers_the_cosponsorship_memo_as_description(): void
    {
        $record = $this->billRecord();
        $record['cosponsorship_memo'] = ['text' => 'Mandating Cursive Handwriting', 'url' => 'https://example.test'];

        $bill = PalegisMapper::billFromHistory($record);

        $this->assertSame('Mandating Cursive Handwriting', $bill->description);
    }

    public function test_bill_from_history_includes_derived_referrals_in_the_raw_meta(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $this->assertArrayHasKey('referrals', $bill->meta['raw']);
        $this->assertCount(1, $bill->meta['raw']['referrals']);
        $this->assertSame('H:EDUCATION', $bill->meta['raw']['referrals'][0]['committee_id']);
    }

    public function test_bill_summary_from_history_omits_referrals_but_still_has_status(): void
    {
        $bill = PalegisMapper::billSummaryFromHistory($this->billRecord());

        $this->assertArrayNotHasKey('raw', $bill->meta);
        $this->assertSame('reported_favourably', $bill->status);
    }

    public function test_sponsors_resolve_to_a_legislator_id_via_the_roster_index(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord(), rosterIndex: ['house|116' => '2029']);

        $sponsors = $bill->sponsors();

        $this->assertCount(1, $sponsors);
        $this->assertSame('2029', $sponsors->first()->id);
        $this->assertSame(SponsorType::Primary, $sponsors->first()->meta['sponsor_type']);
        $this->assertSame(1, $sponsors->first()->meta['sponsor_order']);
    }

    public function test_a_sponsor_absent_from_the_roster_index_maps_with_an_empty_id(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $this->assertSame('', $bill->sponsors()->first()->id);
    }

    public function test_a_cosponsor_is_marked_co_sponsor_not_primary(): void
    {
        $record = $this->billRecord();
        $record['sponsors'][] = ['name' => 'NEILSON', 'party' => 'D', 'body' => 'H', 'district' => '174', 'sequence' => '02'];

        $bill = PalegisMapper::billFromHistory($record);
        $sponsors = $bill->sponsors();

        $this->assertSame(SponsorType::Primary, $sponsors->first()->meta['sponsor_type']);
        $this->assertSame(SponsorType::CoSponsor, $sponsors->last()->meta['sponsor_type']);
    }

    public function test_roster_key_normalizes_a_chamber_enum_and_a_zero_padded_district_the_same_as_a_letter_and_a_bare_number(): void
    {
        $this->assertSame(
            PalegisMapper::rosterKey(Chamber::House, '019'),
            PalegisMapper::rosterKey('H', '19'),
        );
    }
}
