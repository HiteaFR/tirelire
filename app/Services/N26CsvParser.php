<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Parse l'export CSV de la web app N26 (Transactions → Télécharger → CSV) :
 * UTF-8, séparateur virgule, en-tête
 * Booking Date,Value Date,Partner Name,Partner Iban,Type,Payment Reference,
 * Account Name,Amount (EUR),Original Amount,Original Currency,Exchange Rate.
 *
 * Pas d'identifiant d'opération ni de solde dans cet export : la
 * déduplication repose sur date + montant + libellé.
 */
class N26CsvParser implements CsvParser
{
    public function name(): string
    {
        return 'N26';
    }

    public function exportHint(): string
    {
        return __('web app → Transactions → Download');
    }

    public function supports(string $firstLine): bool
    {
        return str_starts_with(str_replace('"', '', $firstLine), 'Booking Date,Value Date,Partner Name,');
    }

    public function parse(string $content): array
    {
        $content = BankCsvParser::normalizeText($content);
        $lines = array_values(array_filter(explode("\n", $content), fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            return [];
        }

        $header = array_map('trim', str_getcsv(array_shift($lines), ',', '"', ''));

        $rows = [];
        foreach ($lines as $i => $line) {
            $cols = str_getcsv($line, ',', '"', '');
            if (count($cols) !== count($header)) {
                throw new RuntimeException(__('Line :line unreadable: ":content"', ['line' => $i + 2, 'content' => mb_substr($line, 0, 80)]));
            }
            $r = array_combine($header, array_map('trim', $cols));

            $amount = trim($r['Amount (EUR)'] ?? '');
            if ($amount === '' || ! is_numeric($amount)) {
                throw new RuntimeException(__('Line :line: missing amount', ['line' => $i + 2]));
            }

            $rows[] = [
                'date' => CarbonImmutable::parse($r['Booking Date'])->startOfDay(),
                'value_date' => $r['Value Date'] !== '' ? CarbonImmutable::parse($r['Value Date'])->startOfDay() : null,
                'amount_cents' => (int) round(((float) $amount) * 100),
                'label' => $this->label($r),
                'balance_cents' => null,
                'external_id' => null,
                'category_hint' => $this->categoryHint($r),
            ];
        }

        return $rows;
    }

    /** @param array<string, string> $r */
    private function label(array $r): string
    {
        // Libellés stockés en base, volontairement non traduits : ils entrent dans le
        // hash de dédoublonnage (N26 n'a pas d'identifiant) et MerchantNormalizer en
        // retire les préfixes. Les traduire créerait des doublons au réimport.
        $partner = $r['Partner Name'];
        $reference = in_array($r['Payment Reference'], ['', '-'], true) ? '' : $r['Payment Reference'];

        $label = match ($r['Type']) {
            'Presentment' => $partner !== '' ? $partner : $reference,
            'Credit Transfer' => $partner !== ''
                ? 'Virement reçu de '.$partner.($reference !== '' && ! str_starts_with(mb_strtoupper($reference), 'VIR') ? ' – '.$reference : '')
                : (str_starts_with($reference, 'Vous avez bien alimenté') ? 'Alimentation du compte par carte' : $reference),
            'Debit Transfer' => 'Virement émis vers '.$partner.($reference !== '' ? ' – '.$reference : ''),
            'Direct Debit' => 'Prélèvement '.$partner.($reference !== '' ? ' '.$reference : ''),
            'Fee' => 'Frais N26'.($reference !== '' ? ' : '.$reference : ''),
            default => trim($r['Type'].' '.$partner.' '.$reference),
        };

        return trim(preg_replace('/\s+/', ' ', $label));
    }

    /** @param array<string, string> $r */
    private function categoryHint(array $r): ?string
    {
        return match ($r['Type']) {
            'Credit Transfer' => 'other_income',
            'Debit Transfer' => 'personal_transfers',
            'Fee' => 'bank_fees',
            default => null,
        };
    }
}
