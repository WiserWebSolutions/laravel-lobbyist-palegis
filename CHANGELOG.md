# Changelog

All notable changes to `laravel-palegis` will be documented in this file.

## v1.9.2

- Read historical authors from visible member cards when the print layout links
  to legacy archives, preserving their member IDs for PDF-only memos.

## v1.9.1

- Detect capped searches from their displayed and total counts even when PA omits
  the maximum-limit banner. Validate every displayed record before continuing.

## v1.9.0

- Add memo session discovery, searchable indexes with explicit truncation counts,
  and full co-sponsorship memo retrieval for both chambers, including proposals
  without bills, multiple introduced bills, authors, and attachment links.
- Preserve PDF-only historical memos as index metadata and a source link without
  reading the PDF stream. Source HTML and plain text are kept separately.
- Reject malformed or mismatched source pages instead of silently losing records.
- Passing `ttl: 0` now bypasses existing cache entries. Requires PHP DOM.

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
