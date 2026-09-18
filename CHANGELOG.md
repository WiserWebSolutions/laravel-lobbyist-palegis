# Changelog

All notable changes to `laravel-palegis` will be documented in this file.

## v1.8.0

- Bills now expose their Co-Sponsorship Memo as `$bill->memo` (the memo's
  subject line) and `$bill->memoUrl`, mapped from the Bill History export’s
  `cosponsorshipMemo` element. The subject reads as a plain-language name for
  the bill and is frequently more informative than the short title, which on a
  newly introduced bill is often still boilerplate. Available from
  `billSummaryFromHistory()` as well as `billFromHistory()`.
- `$bill->description` is unchanged — still the memo where there is one and the
  short title otherwise — so existing consumers need no change.
- Requires `wiserwebsolutions/laravel-lobbyist` ^1.7 for the new `Bill::$memo` /
  `Bill::$memoUrl` fields.

## v1.6.0

- `PalegisMapper::billDto()` now populates `Bill::history()`/`Bill::referrals()`
  (core's declared, typed relations — see `wiserwebsolutions/laravel-lobbyist`
  v1.5.0) instead of stashing the same data under `meta['raw']['history']` /
  `meta['raw']['referrals']`. **If you were reading either key directly off
  `meta['raw']`, switch to `$bill->history()` / `$bill->referrals()`.**
- `billSummaryFromHistory()` (used for change-detection listing) now also
  exposes `history()`/`referrals()` — previously only `billFromHistory()`
  (full-detail, single-bill lookups) did.
