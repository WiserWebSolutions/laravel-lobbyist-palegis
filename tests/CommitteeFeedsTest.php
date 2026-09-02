<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Contracts\Capability;

class CommitteeFeedsTest extends TestCase
{
    /**
     * The assignments feed, in the shape palegis actually publishes it.
     */
    private function assignmentsFeed(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:parss="https://www.legis.state.pa.us/RSS">
          <channel>
            <title>PA House Committee Assignments</title>
            <item>
              <title>Abney, Aerion (D) District 19</title>
              <link>https://www.palegis.us/house/members/house-member-bio?memberId=1933</link>
              <description>Serves on Appropriations, Education</description>
              <parss:Committee position="">Appropriations</parss:Committee>
              <parss:Committee position="Chair">Education</parss:Committee>
              <parss:Subcommittee position="Chair" committee="Education">Career and Technical Education</parss:Subcommittee>
              <parss:District>19</parss:District>
            </item>
          </channel>
        </rss>
        XML;
    }

    private function scheduleFeed(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:parss="https://www.legis.state.pa.us/RSS">
          <channel>
            <title>PA House Committee Meeting Schedule</title>
            <item>
              <title>09/16/2026 10:30 AM EDUCATION (H)</title>
              <link>https://www.palegis.us/house/committees/meeting-schedule</link>
              <description>Public hearing on HB 1925 and SB 403. - Room 140 Main Capitol</description>
              <guid isPermaLink="false">09/16/2026 10:30 AM EDUCATION</guid>
              <parss:MeetingDate>09/16/2026</parss:MeetingDate>
              <parss:MeetingTime>10:30 AM</parss:MeetingTime>
              <parss:Location>Room 140 Main Capitol</parss:Location>
              <parss:Committee>HOUSE EDUCATION</parss:Committee>
              <parss:Bills>HB 1925, SB403</parss:Bills>
            </item>
          </channel>
        </rss>
        XML;
    }

    private function membersFeed(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:parss="https://www.legis.state.pa.us/RSS">
          <channel>
            <title>PA House Members</title>
            <item>
              <title>PATRICK J. HARKINS</title>
              <link>https://www.palegis.us/house/members/house-member-bio?memberId=1081</link>
              <description>District 1; Last Updated: Friday, July 25, 2025</description>
              <parss:District>001</parss:District>
              <parss:County>Erie County (Part)</parss:County>
              <parss:Party>D</parss:Party>
              <parss:ImageSrc>https://www.palegis.us/resources/images/members/200/1081.jpg</parss:ImageSrc>
              <parss:District_1_Phone>(814) 459-1949</parss:District_1_Phone>
              <parss:CapitolAddress_Phone>(717) 787-7406</parss:CapitolAddress_Phone>
              <parss:Vacant>false</parss:Vacant>
            </item>
          </channel>
        </rss>
        XML;
    }

    /**
     * Fake one chamber's feed and leave the other empty.
     *
     * Every driver call reads both chambers, so a pattern matching both URLs
     * returns the same fixture twice and silently doubles every count. Tests
     * that only assert on ->first() would never notice.
     */
    private function fakeHouseOnly(string $feed, string $path): void
    {
        Http::fake([
            '*house/rss/'.$path => Http::response($feed),
            '*senate/rss/'.$path => Http::response($this->emptyFeed()),
        ]);
    }

    private function emptyFeed(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Empty</title></channel></rss>';
    }

    private function driver(): PalegisDriver
    {
        return app(PalegisDriver::class);
    }

    public function test_the_driver_advertises_the_new_capabilities(): void
    {
        $this->assertTrue($this->driver()->supports(Capability::ListCommitteeAssignments));
        $this->assertTrue($this->driver()->supports(Capability::ListCommitteeMeetings));
    }

    public function test_it_reads_committee_leadership_out_of_the_position_attribute(): void
    {
        $this->fakeHouseOnly($this->assignmentsFeed(), 'committee/assignments');

        $assignments = $this->driver()->committeeAssignments();

        // The whole point of the feed lives in an attribute, which the RSS
        // parser used to discard along with every other namespaced element.
        $education = $assignments->committeesOnly()->forCommittee('Education')->first();

        $this->assertNotNull($education);
        $this->assertSame('Chair', $education->position);
        $this->assertTrue($education->isChair());
        $this->assertFalse($education->isViceChair());
    }

    public function test_a_rank_and_file_seat_has_no_position(): void
    {
        $this->fakeHouseOnly($this->assignmentsFeed(), 'committee/assignments');

        $seat = $this->driver()->committeeAssignments()
            ->committeesOnly()
            ->forCommittee('Appropriations')
            ->first();

        // The feed publishes position="" rather than omitting it.
        $this->assertNull($seat->position);
        $this->assertFalse($seat->isChair());
    }

    public function test_it_reads_the_member_out_of_the_title_and_link(): void
    {
        $this->fakeHouseOnly($this->assignmentsFeed(), 'committee/assignments');

        $seat = $this->driver()->committeeAssignments()->first();

        // Name, party and district all arrive crammed into the title, and the
        // party appears nowhere else in this feed.
        $this->assertSame('Abney, Aerion', $seat->legislatorName);
        $this->assertSame('D', $seat->party->value);
        $this->assertSame('19', $seat->district);
        $this->assertSame('1933', $seat->legislatorId);
    }

    public function test_subcommittee_seats_are_distinguishable_from_committee_seats(): void
    {
        $this->fakeHouseOnly($this->assignmentsFeed(), 'committee/assignments');

        $assignments = $this->driver()->committeeAssignments();

        $subcommittee = $assignments->first(fn ($seat) => $seat->isSubcommittee());

        $this->assertSame('Career and Technical Education', $subcommittee->committee);
        $this->assertSame('Education', $subcommittee->parentCommittee);

        // Chairing a subcommittee is not chairing the committee, so a caller
        // building a roster needs them separable.
        $this->assertCount(2, $assignments->committeesOnly());
    }

    public function test_it_reads_scheduled_meetings(): void
    {
        $this->fakeHouseOnly($this->scheduleFeed(), 'committee/schedule');

        $meeting = $this->driver()->committeeMeetings()->first();

        $this->assertSame('2026-09-16', $meeting->date?->toDateString());
        $this->assertSame('10:30 AM', $meeting->time);
        $this->assertSame('Room 140 Main Capitol', $meeting->location);
        $this->assertNotSame('', $meeting->identifier);
    }

    public function test_the_committee_name_loses_the_chamber_prefix_and_the_shouting(): void
    {
        $this->fakeHouseOnly($this->scheduleFeed(), 'committee/schedule');

        $meeting = $this->driver()->committeeMeetings()->first();

        // The feed writes "HOUSE EDUCATION". A caller matching this against its
        // own committee list should not have to undo the formatting.
        $this->assertSame('Education', $meeting->committee);
        $this->assertSame('house', $meeting->chamber?->value);
    }

    public function test_bill_numbers_on_the_agenda_are_normalised(): void
    {
        $this->fakeHouseOnly($this->scheduleFeed(), 'committee/schedule');

        $meeting = $this->driver()->committeeMeetings()->first();

        // "HB 1925, SB403" — the feed is inconsistent about the space.
        $this->assertSame(['HB1925', 'SB403'], $meeting->bills);
    }

    public function test_the_time_is_kept_as_published(): void
    {
        $this->fakeHouseOnly(str_replace(
            '<parss:MeetingTime>10:30 AM</parss:MeetingTime>',
            '<parss:MeetingTime>At the Call of the Chair</parss:MeetingTime>',
            $this->scheduleFeed()
        ), 'committee/schedule');

        // Not every published time is a clock time, and parsing would either
        // fail or invent a precision the schedule does not have.
        $this->assertSame('At the Call of the Chair', $this->driver()->committeeMeetings()->first()->time);
    }

    public function test_members_carry_their_photograph_and_county(): void
    {
        $this->fakeHouseOnly($this->membersFeed(), 'session/members');

        $legislator = $this->driver()->representatives()->first();

        $this->assertSame('https://www.palegis.us/resources/images/members/200/1081.jpg', $legislator->imageUrl);
        $this->assertSame('Erie County (Part)', $legislator->county);
        $this->assertSame('D', $legislator->party->value);
        $this->assertSame('001', $legislator->district);
        $this->assertSame('(717) 787-7406', $legislator->capitolPhone);
    }

    public function test_a_member_is_identified_by_their_member_id(): void
    {
        $this->fakeHouseOnly($this->membersFeed(), 'session/members');

        // The guid embeds a timestamp and changes whenever the member updates
        // their page, so it cannot be the identity.
        $this->assertSame('1081', $this->driver()->representatives()->first()->id);
    }
}
