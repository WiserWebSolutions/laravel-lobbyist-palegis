<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\MembersPageParser;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class MembersPageParserTest extends TestCase
{
    /**
     * A trimmed excerpt of a real `/house/members?SessYr=2007` page: two
     * member cards, one plain and one with a leadership title and social
     * links inside its caption, mirroring the real markup's whitespace,
     * attribute order and nested tags.
     */
    private function page(): string
    {
        return <<<'HTML'
        <div class="col-6 col-sm-6 col-lg-3 member mb-4" data-name="Adolph William F." data-county="DELAWARE" data-party="R" data-district="165" data-leadership="">
            <span class="thumb-info shadow-lg bg-white rounded-bottom h-100" style="max-width:200px;">
                <a href=" /house/members/bio/209/rep-adolph" class="thumb-info-wrapper">
                        <img src="https://www.palegis.us/resources/images/members/200/209.jpg?20260915050558" class="card-img-top" alt="Photo of Representative William F. Adolph" style="height:280px; object-fit: cover;">
                        <span class="thumb-info-title">
                            <span class="thumb-info-inner">William F. Adolph</span>
                            <span class="thumb-info-type bg-party-R"> Republican<br>District 165</span>
                        </span>
                </a>

                <div class="thumb-info-caption px-1">

                </div>
            </span>
        </div>
        <div class="col-6 col-sm-6 col-lg-3 member mb-4" data-name="Argall David G." data-county="BERKS,SCHUYLKILL" data-party="R" data-district="124" data-leadership="Minority Whip">
            <span class="thumb-info shadow-lg bg-white rounded-bottom h-100" style="max-width:200px;">
                <a href=" /house/members/bio/69/rep-argall" class="thumb-info-wrapper">
                        <img src="https://www.palegis.us/resources/images/members/200/69.jpg?20260915050558" class="card-img-top" alt="Photo of Representative David G. Argall" style="height:280px; object-fit: cover;">
                        <span class="thumb-info-title">
                            <span class="thumb-info-inner">David G. Argall</span>
                            <span class="thumb-info-type bg-party-R"> Republican<br>District 124</span>
                        </span>
                </a>

                <div class="thumb-info-caption px-1">
                        <span class="thumb-info-social-icons feature-box-icon">
                            <a class="btn btn-icon btn-outline-secondary btn-sm btn-facebook" href="https://www.facebook.com/example" target="_blank"><i class="fa-brands fa-facebook-f" aria-hidden="true"></i></a>
                        </span>
                        <span class="thumb-info-caption-text mt-2">
                            <i class="fa-duotone fa-map-location" aria-hidden="true"></i> Berks&nbsp;(part) County
                        </span>
                </div>
            </span>
        </div>
        HTML;
    }

    public function test_it_extracts_every_member_card(): void
    {
        $rows = MembersPageParser::parse($this->page());

        $this->assertCount(2, $rows);
        $this->assertSame(['209', '69'], array_column($rows, 'id'));
    }

    public function test_it_reads_the_display_name_from_the_thumbnail_title(): void
    {
        $rows = MembersPageParser::parse($this->page());

        $this->assertSame('William F. Adolph', $rows[0]['name']);
        $this->assertSame('David G. Argall', $rows[1]['name']);
    }

    public function test_it_reads_party_district_and_county_from_the_data_attributes(): void
    {
        $rows = MembersPageParser::parse($this->page());

        $this->assertSame('R', $rows[0]['party']);
        $this->assertSame('165', $rows[0]['district']);
        $this->assertSame('DELAWARE', $rows[0]['county']);
    }

    public function test_a_leadership_title_is_captured_when_present(): void
    {
        $rows = MembersPageParser::parse($this->page());

        $this->assertSame('', $rows[0]['leadership']);
        $this->assertSame('Minority Whip', $rows[1]['leadership']);
    }

    public function test_a_caption_with_nested_social_link_tags_does_not_break_card_boundaries(): void
    {
        // The second card's caption has nested <span>/<a>/<i> tags (social
        // icons); an earlier, naive version of this parser could mistake one
        // of those for the next card's boundary.
        $rows = MembersPageParser::parse($this->page());

        $this->assertCount(2, $rows);
        $this->assertSame('69', $rows[1]['id']);
    }

    public function test_the_image_url_is_captured_verbatim(): void
    {
        $rows = MembersPageParser::parse($this->page());

        $this->assertSame(
            'https://www.palegis.us/resources/images/members/200/209.jpg?20260915050558',
            $rows[0]['image_url'],
        );
    }

    public function test_the_bio_url_is_resolved_against_the_site_origin(): void
    {
        $rows = MembersPageParser::parse($this->page());

        $this->assertSame('https://www.palegis.us/house/members/bio/209/rep-adolph', $rows[0]['url']);
    }

    public function test_it_throws_when_the_page_has_no_recognizable_member_cards(): void
    {
        $this->expectException(PalegisException::class);

        MembersPageParser::parse('<html><body>palegis.us redesigned this page</body></html>');
    }
}
