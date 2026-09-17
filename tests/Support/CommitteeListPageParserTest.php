<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\CommitteeListPageParser;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class CommitteeListPageParserTest extends TestCase
{
    /**
     * A trimmed excerpt of a real `/house/committees/committee-list` page:
     * each committee renders twice (a desktop and a mobile link), mirroring
     * the real markup's single-quoted attributes.
     */
    private function page(): string
    {
        return <<<'HTML'
        <div class="row rounded py-3">
            <div class="d-none d-sm-block">
                <a href='/house/committees/64/housing-and-community-development' class='committee h4'>Housing & Community Development</a>
            </div>
            <div class="d-block d-sm-none">
                <a href='/house/committees/64/housing-and-community-development' class='committee h6'>Housing & Community Development</a>
            </div>
        </div>
        <div class="row rounded py-3">
            <div class="d-none d-sm-block">
                <a href='/house/committees/62/appropriations' class='committee h4'>Appropriations</a>
            </div>
            <div class="d-block d-sm-none">
                <a href='/house/committees/62/appropriations' class='committee h6'>Appropriations</a>
            </div>
        </div>
        HTML;
    }

    public function test_parses_every_committee_deduplicated_by_code(): void
    {
        $committees = CommitteeListPageParser::parse($this->page());

        $this->assertCount(2, $committees);
        $this->assertSame(['code' => '64', 'slug' => 'housing-and-community-development', 'name' => 'Housing & Community Development'], $committees[0]);
        $this->assertSame('62', $committees[1]['code']);
    }

    public function test_throws_when_no_committees_are_found(): void
    {
        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/No committees found/');

        CommitteeListPageParser::parse('<html><body>redesigned page</body></html>');
    }
}
