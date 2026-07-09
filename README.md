# Laravel Lobbyist — Pennsylvania Driver

Pennsylvania state driver for [`wiserwebsolutions/laravel-lobbyist`](https://github.com/wiserwebsolutions/laravel-lobbyist),
backed by the public RSS feeds published by the Pennsylvania General Assembly at
[palegis.us](https://www.palegis.us). It registers itself with the Lobbyist
manager under the `pa` abbreviation, so `Lobbyist::state('PA')` resolves to it.

## Installation

```bash
composer require wiserwebsolutions/laravel-palegis
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
```

## Usage

```php
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

$pa = Lobbyist::state('PA'); // PalegisDriver

$pa->listBills();            // BillCollection (House bills feed)
$pa->listVotes();            // VoteCollection (House + Senate vote feeds)
$pa->listRepresentatives();  // LegislatorCollection (House members feed)
$pa->listSessions();         // SessionCollection (current General Assembly)
```

### Capabilities & limitations

The RSS feeds are **browse-only**: they publish what is current but provide no
way to look up an arbitrary bill, vote, or member by id. This driver therefore
implements the list/browse providers but **none** of the `*Lookup` interfaces —
calling `getBill()`, `getVote()`, or `getRepresentative()` throws
`UnsupportedOperationException`. Fields the feeds do not carry (e.g. vote tallies)
are left `null` on the returned data objects; the raw feed item is preserved on
`->meta`.

Feed coverage is asymmetric: the Senate publishes no `bills` or `members` feed,
so `listBills()`/`listRepresentatives()` currently reflect House data only.

## Low-level RSS access

The underlying `LaravelPalegis` client is also available directly (bound as a
singleton) for raw feed access — `getHouseBills()`, `getSenateVotes()`, etc. —
returning parsed arrays.

## Testing

Tests use `Http::fake()` with RSS fixtures and never hit the network:

```bash
composer install
vendor/bin/phpunit
```

## License

MIT © Daniel Wiser
