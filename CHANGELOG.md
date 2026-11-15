# Changelog

All notable changes to Tirelire are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions will follow
[Semantic Versioning](https://semver.org/) from the first release.

## [Unreleased]

First public snapshot. No release has been published yet.

### Added

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
