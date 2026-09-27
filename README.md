# Laravel Lobbyist — Pennsylvania Driver

Pennsylvania state driver for [`wiserwebsolutions/laravel-lobbyist`](https://github.com/wiserwebsolutions/laravel-lobbyist),
backed by the data published by the Pennsylvania General Assembly at
[palegis.us](https://www.palegis.us) — the per-chamber RSS feeds plus the Bill
History Data bulk export. It registers itself with the Lobbyist manager under
the `pa` abbreviation, so `Lobbyist::state('PA')` resolves to it.

> Requires the PHP `zip` and `simplexml` extensions (used for the Bill History
> Data download).

## Installation

```bash
composer require wiserwebsolutions/laravel-lobbyist-palegis
```

The package auto-registers. Publish its config to customize feed URLs, request,
or caching settings:

```bash
php artisan vendor:publish --tag=palegis-config
```

```dotenv
# optional
PALEGIS_TIMEOUT=30
PALEGIS_RETRY_TIMES=2
PALEGIS_CACHE_ENABLED=true
PALEGIS_CACHE_STORE=
PALEGIS_CACHE_TTL=3600
PALEGIS_BILL_HISTORY_TTL=3600   # cache lifetime for the (large) bill-history download
```

Feed and download URLs are fixed and generated automatically, and the bill
history defaults to the current regular session — there is nothing to configure
for either. (To read a past session, pass its id to the low-level
`getBillHistory('2023_0')`.) If a generated URL ever fails, the driver throws a
`PalegisException` naming the attempted URL, the HTTP status, and how to report
the problem.

## Usage

```php
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

$pa = Lobbyist::state('PA'); // PalegisDriver

$pa->bills();            // BillCollection (all bills/resolutions from Bill History Data)
$pa->bill('HB17');       // Bill (lookup by number or full id)
$pa->votes();            // VoteCollection (House + Senate roll-call feeds)
$pa->legislators();      // LegislatorCollection (House + Senate members feeds)
$pa->representatives();  // LegislatorCollection (House only)
$pa->senators();          // LegislatorCollection (Senate only)
$pa->sessions();         // SessionCollection (current General Assembly)
```

### Capabilities & limitations

**Bills** come from the Bill History Data export — a bulk download of every bill
and resolution in the session (with sponsors, printer numbers, and full action
history preserved on `->meta['raw']`). This backs both `bills()` and lookups via
`bill($numberOrId)`. Note it is a large download (thousands of bills, tens of MB
uncompressed) fetched and cached as a unit, so the first call in a cache window
is expensive; `bill()` filters that same cached dataset.

Each bill also carries its **Co-Sponsorship Memo** — the notice the prime
sponsor circulates asking colleagues to join the bill. Its subject reads as a
plain-language name for the bill, and is often more informative than the short
title, which on a newly introduced bill is frequently still boilerplate
("An Act amending Title 75"):

```php
$bill = $pa->bill('HB17');

$bill->title;     // "Cursive handwriting"       — the export's short title
$bill->memo;      // "Mandating Cursive Handwriting" — memo subject, null if none filed
$bill->memoUrl;   // https://www.palegis.us/house/co-sponsorship/memo?memoID=…
```

`description` keeps its existing behavior — the memo where there is one, the
short title otherwise — so nothing reading it needs to change.

**Votes and members** come from the RSS feeds, which are **browse-only** — they
publish what is current but cannot resolve an arbitrary id. So the driver
implements `VoteProvider`/`LegislatorProvider` but not their `*Lookup`
interfaces; calling `vote()` or `representative()` throws
`UnsupportedOperationException`. Fields the feeds do not carry (e.g. vote
tallies) are left `null`.

## Low-level access

The underlying `LaravelPalegis` client is bound as a singleton and exposes every
palegis.us feed directly as parsed arrays.

**Bill History Data:** `getBillHistory(?string $session = null)` returns
`['export_date', 'total', 'session', 'bills' => [...]]`, each bill with its
`sponsors`, `printers_numbers`, and `actions`.

**RSS feeds** — House: `getHouseCalendar()`,
`getHouseTabledBillCalendar()`, `getHouseJournals()`, `getHouseReports()`,
`getHouseVotes()`, `getHouseAmendments()`, `getHouseMembers()`,
`getHouseCommitteeSchedule()`, `getHouseCommitteeAssignments()`,
`getHouseCosponsorshipMemos()`. Senate feeds: `getSenateCalendar()`,
`getSenateJournals()`, `getSenateNotes()`, `getSenateVotes()`,
`getSenateAmendments()`, `getSenateMembers()`, `getSenateExecutiveNominations()`,
`getSenateCommitteeSchedule()`, `getSenateCommitteeAssignments()`,
`getSenateCosponsorshipMemos()`.

## Full co-sponsorship memos

The low-level client can read memos before a bill exists, as well as historical
and special-session memos:

```php
$client = app(\WiserWebSolutions\LaravelPalegis\LaravelPalegis::class);
$sessions = $client->getCosponsorshipMemoSessions('house');
$index = $client->getCosponsorshipMemoIndex('house', '2025_0', '2026-09-01', '2026-09-07');
$memo = $client->getCosponsorshipMemo('house', $index['items'][0]['id'], indexRecord: $index['items'][0]);
```

Use `house` or `senate`. Session IDs include the special-session indicator, e.g.
`2023_1`. Search dates are inclusive circulation dates; an optional `memberId`
filters circulating members. The index returns `total`, `truncated`, `member_ids`,
and `items`. PA limits a search to 250 results: subdivide date ranges, and if
necessary individual days by member, deduplicate by memo ID, and reconcile totals.
Do not treat a truncated index as a complete archive.

Memo records include subject, circulation/update timestamps (UTC), full `body`
text, `body_html`, circulating `members`, zero or more `legislation` references,
`documents`, `attachments`, and the canonical `url`. HTML is untrusted source
material: sanitize it before rendering. Memo references are separate from bill
sponsorship, and one memo may introduce multiple bills.

Some early memos exist only as PDFs. Supply their index record as shown above;
these return `content_format: pdf`, index metadata, empty text/HTML, and the PDF
link. PDF streams are closed without reading or retaining their content. HTML
memos return `content_format: html`. Attachment files are never fetched.

Pass `ttl: 0` to bypass existing cached data, including on the RSS methods.
Unrecognized pages, mismatched identities/sessions, and HTTP failures throw
`PalegisException`; they are not interpreted as empty results. Requires PHP DOM.

## Running tests

Tests use `Http::fake()` with RSS fixtures and never hit the network:

```bash
composer install
vendor/bin/phpunit
```

## License

MIT © Daniel Wiser
