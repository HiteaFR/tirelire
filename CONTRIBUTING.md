# Contributing to Tirelire

Thanks for your interest. Tirelire is a small project maintained in spare time: issues and pull
requests are welcome, and reviewed when time allows.

## Before you start

- **Bugs**: open an issue with the bug template. Never paste real bank data.
- **Ideas**: open an issue with the idea template before writing code, so we can agree on the
  approach. Tirelire stays a single-household app: multi-tenancy, multi-currency accounting and
  bank API connections are out of scope.
- **A new bank format**: see [Adding a bank](#adding-a-bank) below, or open an issue with the
  "New bank format" template if you cannot write the parser yourself.
- **Security issues**: never in a public issue. See [SECURITY.md](SECURITY.md).

## Development

Requirements: PHP 8.4 with `pdo_sqlite`, Composer, Node 22.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan finance:user you@example.com
composer run dev          # app and Vite dev server
```

`php artisan db:seed --class=DemoSeeder` fills an empty database with made-up data.

AI coding agents: `AGENTS.md` and the Laravel Boost configuration (`boost.json`, `.mcp.json`) are
committed, but the skills they refer to are not. Run `php artisan boost:update` once to generate
them locally. Agent-specific files such as `.claude/` and `CLAUDE.md` are never committed.

Before opening a pull request:

```bash
php artisan test --compact
vendor/bin/pint --test
```

- The stack is Laravel 13, Livewire 4 (class-based components in `app/Livewire`) and Tailwind 4.
  Amounts are stored in cents, as integers.
- The interface is translated: every new text goes through `__()` with an English source string,
  and its French translation goes in `lang/fr.json`. `TranslationsTest` fails if a translation is
  missing or unused.
- Tests are PHPUnit feature tests in `tests/Feature`. Test data is made up: no real bank labels,
  amounts or names.
- Dependencies: add one only when it is really needed, and say why in the pull request.

## Adding a bank

A bank format is one class implementing `App\Services\CsvParser`:

1. **The parser.** Create `app/Services/MyBankCsvParser.php`:
   - `name()`: the bank name shown on the import page;
   - `exportHint()`: where to find the export in the bank's app, through `__()`;
   - `supports($firstLine)`: recognize the format from the first line of the file (usually the
     header), without matching the other formats;
   - `parse($content)`: return one array per operation with `date`, `value_date`, `amount_cents`
     (negative for a debit), `label`, `balance_cents`, and optionally `external_id` (the bank's own
     operation identifier, used to skip duplicates) and `category_hint` (the key of a shipped
     category, such as `groceries`, used when no rule matches).
   Start from `CreditMutuelCsvParser` (semicolons, French amounts, Windows-1252) or
   `N26CsvParser` (commas, English header). Call `BankCsvParser::normalizeText()` first to handle
   the encoding, BOM and line endings.
2. **Register it** in the `parsers` list of `config/finance.php`. The import page and the "format
   not recognized" message list it automatically.
3. **An anonymized sample.** Keep the exact header and structure (separator, quotes, date and
   number formats) but replace every label, amount, date, reference and account number with made-up
   values. Put it inline in the test, like the existing ones.
4. **A test** in `tests/Feature/MyBankImportTest.php`, modelled on `N26ImportTest`: the format is
   recognized, amounts and dates are read correctly, and importing the same file twice creates no
   duplicate.

Generated labels (for example "Virement reçu de …" in the N26 parser) are stored in the database
and take part in duplicate detection: do not translate or change them once a format is released.

## Pull requests

- One topic per pull request, with tests for the behaviour you change.
- Keep the existing style: Pint formatting, PHPDoc rather than inline comments, explicit types.
- Fill in the pull request template.

## Developer Certificate of Origin

Contributions are accepted under the [Developer Certificate of Origin 1.1](https://developercertificate.org/)
(DCO). By signing off a commit, you certify that you wrote it, or otherwise have the right to submit
it under the project's license (Apache-2.0).

Add a `Signed-off-by` line to every commit, with your real name and an email address you can be
reached at:

```
Signed-off-by: Jane Doe <jane@example.net>
```

`git commit -s` adds it for you. A pull request whose commits are not all signed off cannot be
merged.

## Code of conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md).
