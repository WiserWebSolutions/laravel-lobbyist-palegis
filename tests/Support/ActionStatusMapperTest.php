<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use WiserWebSolutions\LaravelPalegis\Support\ActionStatusMapper;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class ActionStatusMapperTest extends TestCase
{
    public function test_a_freshly_introduced_bill_with_no_recognizable_action_is_introduced(): void
    {
        $result = ActionStatusMapper::status([
            ['verb' => '(Remarks see House Journal Page', 'date' => '05/13/26'],
        ]);

        $this->assertSame('introduced', $result['status']);
    }

    public function test_referred_to_committee_sets_referred_status(): void
    {
        $result = ActionStatusMapper::status([
            ['verb' => 'Referred to', 'committee' => 'EDUCATION', 'date' => '01/08/25'],
        ]);

        $this->assertSame('referred', $result['status']);
        $this->assertSame('01/08/25', $result['status_date']);
    }

    public function test_later_actions_supersede_earlier_ones(): void
    {
        $result = ActionStatusMapper::status([
            ['verb' => 'Referred to', 'committee' => 'EDUCATION', 'date' => '01/08/25'],
            ['verb' => 'Reported as committed,', 'committee' => 'EDUCATION', 'date' => '05/07/25'],
            ['verb' => 'Third consideration and final passage,', 'date' => '06/24/25'],
        ]);

        $this->assertSame('passed', $result['status']);
        $this->assertSame('06/24/25', $result['status_date']);
    }

    public function test_becoming_law_is_chaptered(): void
    {
        $result = ActionStatusMapper::status([
            ['verb' => 'Third consideration and final passage,', 'date' => '02/03/26'],
            ['verb' => 'Act No. 2 of 2026,', 'date' => '02/11/26'],
        ]);

        $this->assertSame('chaptered', $result['status']);
        $this->assertSame('02/11/26', $result['status_date']);
    }

    public function test_a_veto_is_recognized_even_after_passage(): void
    {
        $result = ActionStatusMapper::status([
            ['verb' => 'Third consideration and final passage,', 'date' => '02/03/26'],
            ['verb' => 'Vetoed by the Governor,', 'date' => '02/20/26'],
        ]);

        $this->assertSame('vetoed', $result['status']);
    }

    public function test_an_action_with_no_verb_is_skipped(): void
    {
        $result = ActionStatusMapper::status([
            ['committee' => 'EDUCATION', 'date' => '01/08/25'],
        ]);

        $this->assertSame('introduced', $result['status']);
        $this->assertNull($result['status_date']);
    }

    public function test_referrals_are_extracted_from_referred_actions_only(): void
    {
        $referrals = ActionStatusMapper::referrals([
            ['verb' => 'Referred to', 'committee' => 'EDUCATION', 'date' => '01/08/25', 'chamber' => 'H'],
            ['verb' => 'Reported as committed,', 'committee' => 'EDUCATION', 'date' => '05/07/25', 'chamber' => 'H'],
            ['verb' => 'Re-committed to', 'committee' => 'APPROPRIATIONS', 'date' => '06/23/25', 'chamber' => 'H'],
        ]);

        $this->assertCount(2, $referrals);
        $this->assertSame('H:EDUCATION', $referrals[0]['committee_id']);
        $this->assertSame('Education', $referrals[0]['name']);
        $this->assertSame('H', $referrals[0]['chamber']);
        $this->assertSame('01/08/25', $referrals[0]['date']);
        $this->assertSame('H:APPROPRIATIONS', $referrals[1]['committee_id']);
        $this->assertSame('Appropriations', $referrals[1]['name']);
    }

    public function test_an_action_with_no_committee_produces_no_referral(): void
    {
        $referrals = ActionStatusMapper::referrals([
            ['verb' => 'First consideration,', 'committee' => '', 'date' => '05/07/25', 'chamber' => 'H'],
        ]);

        $this->assertSame([], $referrals);
    }

    public function test_referrals_are_empty_for_no_actions(): void
    {
        $this->assertSame([], ActionStatusMapper::referrals([]));
        $this->assertSame('introduced', ActionStatusMapper::status([])['status']);
    }
}
