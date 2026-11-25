# Changelog

All notable changes to Tirelire are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-11-25

First release. Tirelire was published as a source snapshot on 2026-11-15; this release adds a
published Docker image and the fix listed below.

### Highlights

- **Docker image** `ghcr.io/hiteafr/tirelire` for `linux/amd64` and `linux/arm64`, with a signed
  build provenance. `compose.yaml` pins the image of its release: `docker compose pull`, then
  `docker compose up -d` (building from source stays possible, see the
  [README](README.md#docker-recommended)). The published port can be changed with `TIRELIRE_PORT`.

### Fixed

- **CSV import**: re-importing a statement no longer duplicates operations when the bank has
  rewritten its labels (new label format) or recomputed its running balances (an operation booked
  later with an earlier date) between two exports. Without a bank transaction id, a line also
  counts as already imported when the account already holds as many imported operations with the
  same date and amount; a genuinely new operation (a third coffee on the same day, a back-dated
  operation) is still imported.

### Features

- **Accounts**: several accounts, each with its balance taken from the bank statement, and a
  month-by-month view of income, spending and net worth.
- **CSV import**: Crédit Mutuel, Trade Republic and N26 exports, format detected from the first
  line. Overlapping imports create no duplicates, and a bank line replaces the matching manual
  entry. New formats plug in through the `CsvParser` contract and `config/finance.php`.
- **Manual entries** for income and spending.
- **Categories and rules**: generic categories and rules seeded on install, a page to create,
  rename and delete categories and to manage their rules (case-insensitive substring of the bank
  label, with priorities and a live preview), and recategorization of existing operations.
  Optional private rules from a JSON file (`FINANCE_CATEGORY_RULES`).
- **Recurring payments**: monthly and annual subscriptions detected from the history, robust to
  one-off months and price changes; mark one "to cancel" to see the monthly saving, with an alert
  if it is charged again after cancellation.
- **Budgets**: monthly budget per category, with the 3-month average as a reference.
- **Forecast**: end-of-month balance from real balances and dated recurring payments, spending pace
  of the month, savings capacity.
- **Private mode**: one tap hides every amount on the device (display only, not a security
  feature).
- **Security**: no default user or password (`php artisan finance:user`), login throttling, open
  sessions signed out when the password changes, CSV or text uploads only, trusted proxies and
  hosts from the environment.
- **Command line**: `finance:user`, `finance:import` and `finance:recategorize`.
- **Demo data**: `php artisan db:seed --class=DemoSeeder` fills an empty database with fictitious
  accounts and operations.
- English and French interface, English by default (`APP_LOCALE=fr`); currency symbol and number
  format configurable.

[Unreleased]: https://github.com/HiteaFR/tirelire/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/HiteaFR/tirelire/releases/tag/v1.0.0
