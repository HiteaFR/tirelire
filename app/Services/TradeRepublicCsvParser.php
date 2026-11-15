<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Parse l'export « Transactions » de Trade Republic (Profil → Relevés →
 * Export des transactions) : CSV UTF-8, séparateur virgule, en-tête
 * datetime,date,account_type,category,type,asset_class,name,symbol,shares,
 * price,amount,fee,tax,currency,…,description,transaction_id,…,mcc_code.
 *
 * Le montant importé est le mouvement de trésorerie net (amount + fee + tax).
 * Les achats/ventes de titres sont des mouvements de cash : ils sont
 * orientés vers la catégorie savings (transfert, exclu des dépenses).
 *
 * Seul le compte espèces (account_type DEFAULT) est importé : l'export mêle
 * aussi les mouvements des sous-comptes (PEA…) dont le solde est distinct
 * de celui affiché par l'app Trade Republic.
 */
class TradeRepublicCsvParser implements CsvParser
{
    /** Clé de catégorie suggérée par code MCC (utilisée si aucune règle de libellé ne matche). */
    private const MCC_CATEGORIES = [
        '5411' => 'groceries', '5499' => 'groceries', '5331' => 'groceries', '5310' => 'groceries', '5300' => 'groceries',
        '5812' => 'restaurants', '5814' => 'restaurants', '5811' => 'restaurants',
        '5813' => 'bars', '5993' => 'bars',
        '5511' => 'transport', '5541' => 'transport', '5542' => 'transport',
        '7523' => 'transport', '4111' => 'transport', '4121' => 'transport', '7538' => 'transport',
        '5912' => 'health', '8011' => 'health', '8021' => 'health', '8062' => 'health', '8099' => 'health',
        '5200' => 'shopping', '5311' => 'shopping', '5651' => 'shopping', '5661' => 'shopping', '5691' => 'shopping',
        '5941' => 'shopping', '5945' => 'shopping', '5999' => 'shopping', '5732' => 'shopping', '7230' => 'shopping',
        '7832' => 'leisure', '7841' => 'leisure', '7911' => 'leisure',
        '7991' => 'leisure', '7997' => 'leisure', '5942' => 'leisure',
        '4814' => 'subscriptions', '4899' => 'subscriptions', '5968' => 'subscriptions',
    ];

    public function name(): string
    {
        return 'Trade Republic';
    }

    public function exportHint(): string
    {
        return __('Profile → Statements → Export');
    }

    public function supports(string $firstLine): bool
    {
        $header = str_replace('"', '', $firstLine);

        return str_starts_with($header, 'datetime,date,account_type,');
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
            if (($r['account_type'] ?? 'DEFAULT') !== 'DEFAULT') {
                continue;
            }

            $amount = $this->toCents($r['amount'] ?? '') + $this->toCents($r['fee'] ?? '') + $this->toCents($r['tax'] ?? '');
            if (($r['amount'] ?? '') === '') {
                throw new RuntimeException(__('Line :line: missing amount', ['line' => $i + 2]));
            }

            $rows[] = [
                'date' => CarbonImmutable::parse($r['date'])->startOfDay(),
                'value_date' => null,
                'amount_cents' => $amount,
                'label' => $this->label($r),
                'balance_cents' => null,
                'external_id' => $r['transaction_id'] !== '' ? 'tr:'.$r['transaction_id'] : null,
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
        $name = $r['name'] !== '' ? $r['name'] : $r['description'];
        $counterparty = $r['counterparty_name'] !== '' ? $r['counterparty_name'] : $this->counterpartyFromDescription($r['description']);

        $label = match ($r['type']) {
            'CARD_TRANSACTION' => $name,
            'TRANSFER_INBOUND', 'TRANSFER_INSTANT_INBOUND' => 'Virement reçu de '.($counterparty ?: $name),
            'TRANSFER_OUTBOUND', 'TRANSFER_INSTANT_OUTBOUND' => 'Virement émis vers '.($counterparty ?: $name),
            'BUY', 'SAVINGS_PLAN' => 'Achat '.$name.$this->shares($r),
            'SELL' => 'Vente '.$name.$this->shares($r),
            'DIVIDEND' => 'Dividende '.$name,
            'INTEREST_PAYMENT' => 'Intérêts sur solde',
            default => ucfirst(mb_strtolower(str_replace('_', ' ', $r['type']))).($name !== '' ? ' '.$name : ''),
        };

        return trim(preg_replace('/\s+/', ' ', $label));
    }

    /** @param array<string, string> $r */
    private function shares(array $r): string
    {
        if ($r['shares'] === '') {
            return '';
        }
        $qty = rtrim(rtrim(number_format((float) $r['shares'], 4, ',', ' '), '0'), ',');

        return ' ×'.$qty;
    }

    /** « Incoming transfer from SASU EXEMPLE (FR76…) » → « SASU EXEMPLE » */
    private function counterpartyFromDescription(string $description): ?string
    {
        if (preg_match('/^(?:Incoming transfer from|Outgoing transfer for) (.+?) \([A-Z]{2}\d{2}[A-Z0-9 ]+\)$/u', $description, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @param array<string, string> $r */
    private function categoryHint(array $r): ?string
    {
        return match ($r['type']) {
            'BUY', 'SELL', 'SAVINGS_PLAN' => 'savings',
            'DIVIDEND', 'INTEREST_PAYMENT' => 'other_income',
            'TRANSFER_INBOUND', 'TRANSFER_INSTANT_INBOUND' => 'other_income',
            'TRANSFER_OUTBOUND', 'TRANSFER_INSTANT_OUTBOUND' => 'personal_transfers',
            'CARD_TRANSACTION' => self::MCC_CATEGORIES[$r['mcc_code']] ?? null,
            default => null,
        };
    }

    /** "-12.500000" → -1250 ; "" → 0 */
    private function toCents(string $value): int
    {
        $value = trim($value);

        return $value === '' || ! is_numeric($value) ? 0 : (int) round(((float) $value) * 100);
    }
}
