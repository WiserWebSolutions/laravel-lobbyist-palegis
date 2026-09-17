# Changelog

All notable changes to `laravel-palegis` will be documented in this file.

## v1.6.0

- `PalegisMapper::billDto()` now populates `Bill::history()`/`Bill::referrals()`
  (core's declared, typed relations — see `wiserwebsolutions/laravel-lobbyist`
  v1.5.0) instead of stashing the same data under `meta['raw']['history']` /
  `meta['raw']['referrals']`. **If you were reading either key directly off
  `meta['raw']`, switch to `$bill->history()` / `$bill->referrals()`.**
- `billSummaryFromHistory()` (used for change-detection listing) now also
  exposes `history()`/`referrals()` — previously only `billFromHistory()`
  (full-detail, single-bill lookups) did.
