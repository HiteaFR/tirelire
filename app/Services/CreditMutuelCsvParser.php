<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Parse un export CSV Crédit Mutuel au format :
 *   Date;Date de valeur;Débit;Crédit;Libellé;Solde
 * Dates jj/mm/aaaa, montants français (virgule décimale), encodage
 * Windows-1252 ou UTF-8 (détecté), séparateur ';'.
 */
class CreditMutuelCsvParser implements CsvParser
{
    public function name(): string
    {
        return 'Crédit Mutuel';
    }

    public function exportHint(): string
    {
        return __('CSV export « :header »', ['header' => 'Date;Date de valeur;Débit;Crédit;Libellé;Solde']);
    }

    public function supports(string $firstLine): bool
    {
        // En-tête « Date;Date de valeur;… » ou fichier sans en-tête commençant par une date jj/mm/aaaa
        return str_starts_with($firstLine, 'Date;') || preg_match('#^\d{2}/\d{2}/\d{4};#', $firstLine) === 1;
    }

    public function parse(string $content): array
    {
        $content = BankCsvParser::normalizeText($content);

        $lines = array_values(array_filter(explode("\n", $content), fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return [];
        }

        // En-tête optionnel : on le saute s'il ne commence pas par une date
        if (! preg_match('#^\d{2}/\d{2}/\d{4}#', $lines[0])) {
            array_shift($lines);
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $cols = str_getcsv($line, ';', '"', '');
            if (count($cols) < 5) {
                throw new RuntimeException(__('Line :line unreadable: ":content"', ['line' => $i + 2, 'content' => mb_substr($line, 0, 80)]));
            }
            [$date, $valueDate, $debit, $credit] = $cols;
            $label = trim($cols[4]);
            $balance = $cols[5] ?? null;

            $amount = $this->toCents($debit !== '' ? $debit : $credit);
            if ($amount === null) {
                throw new RuntimeException(__('Line :line: missing amount', ['line' => $i + 2]));
            }

            $rows[] = [
                'date' => CarbonImmutable::createFromFormat('d/m/Y', trim($date))->startOfDay(),
                'value_date' => trim((string) $valueDate) !== ''
                    ? CarbonImmutable::createFromFormat('d/m/Y', trim($valueDate))->startOfDay()
                    : null,
                'amount_cents' => $amount,
                'label' => $label,
                'balance_cents' => $this->toCents($balance),
            ];
        }

        return $rows;
    }

    /** "1 234,56" → 123456 ; "-7,50" → -750 ; "" → null */
    private function toCents(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $clean = str_replace([' ', "\u{A0}", "\u{202F}"], '', $value);
        $clean = str_replace(',', '.', $clean);
        if (! is_numeric($clean)) {
            return null;
        }

        return (int) round(((float) $clean) * 100);
    }
}
