<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TransactionImporter
{
    public function __construct(
        private BankCsvParser $parser,
        private MerchantNormalizer $normalizer,
        private Categorizer $categorizer,
    ) {}

    /**
     * Importe un contenu CSV (format détecté automatiquement) dans un compte.
     * Idempotent : les lignes déjà présentes sont ignorées (voir
     * alreadyImportedUnmatched), on peut donc réimporter des exports qui se
     * chevauchent, même si la banque a changé ses libellés ou recalculé ses
     * soldes entre deux exports. Une ligne qui
     * correspond à une opération saisie à la main la remplace au lieu de la
     * doubler (voir manualEntryFor).
     *
     * @return array{imported: int, skipped: int, reconciled: int, total: int}
     */
    public function import(Account $account, string $csvContent): array
    {
        $rows = $this->parser->parse($csvContent);

        $imported = 0;
        $skipped = 0;
        $reconciled = 0;

        DB::transaction(function () use ($account, $rows, &$imported, &$skipped, &$reconciled) {
            // Deux opérations identiques le même jour sont légitimes (2 cafés
            // au même bar) mais ont le même hash de base : on suffixe par un
            // compteur d'occurrence stable au sein du fichier.
            $seen = [];
            $lines = [];
            foreach ($rows as $row) {
                $hash = $this->hashFor($row, $seen);
                $lines[] = [
                    'row' => $row,
                    'hash' => $hash,
                    'known' => $account->transactions()->where('hash', $hash)->exists(),
                ];
            }
            $unmatchedToSkip = $this->alreadyImportedUnmatched($account, $lines);
            // Indice de catégorie d'un parseur : clé stable d'une catégorie livrée (« groceries »).
            $categoryIdsByKey = Category::whereNotNull('key')->pluck('id', 'key');

            foreach ($lines as ['row' => $row, 'hash' => $hash, 'known' => $known]) {
                $key = $this->dayAmountKey($row);
                if (! $known && empty($row['external_id']) && ($unmatchedToSkip[$key] ?? 0) > 0) {
                    $unmatchedToSkip[$key]--;
                    $known = true;
                }

                if ($known) {
                    $skipped++;

                    continue;
                }

                $attributes = [
                    'category_id' => $this->categorizer->categoryIdFor($row['label'])
                        ?? $categoryIdsByKey->get($row['category_hint'] ?? ''),
                    'date' => $row['date'],
                    'value_date' => $row['value_date'],
                    'amount_cents' => $row['amount_cents'],
                    'label' => $row['label'],
                    'merchant' => $this->normalizer->normalize($row['label']),
                    'balance_cents' => $row['balance_cents'],
                    'source' => 'csv',
                    'hash' => $hash,
                ];

                // Déjà saisie à la main : la ligne de la banque la remplace, en
                // gardant la catégorie choisie par l'utilisateur.
                $manual = $this->manualEntryFor($account, $row);
                if ($manual) {
                    $manual->update(['category_id' => $manual->category_id ?? $attributes['category_id']] + $attributes);
                    $reconciled++;

                    continue;
                }

                Transaction::create(['account_id' => $account->id] + $attributes);
                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped, 'reconciled' => $reconciled, 'total' => count($rows)];
    }

    /**
     * Par date et montant, nombre de lignes du fichier sans identifiant
     * bancaire et sans hash connu qui sont pourtant déjà en base. Le hash
     * inclut le libellé et le solde, que la banque peut réécrire d'un export
     * à l'autre (nouveau format de libellé, opération antidatée qui décale les
     * soldes suivants). Le compte contient N opérations importées de cette
     * date et de ce montant, dont H retrouvées par leur hash dans le fichier :
     * les N - H autres sont des lignes du fichier au hash changé, à ignorer.
     * Au-delà, ce sont de vraies opérations en plus (2ᵉ café le même jour,
     * opération antidatée). Calculé avant toute écriture, et indépendant de
     * l'ordre des lignes dans le fichier.
     *
     * @param  list<array{row: array{date: CarbonImmutable, amount_cents: int, external_id?: ?string}, hash: string, known: bool}>  $lines
     * @return array<string, int>
     */
    private function alreadyImportedUnmatched(Account $account, array $lines): array
    {
        $knownByKey = [];
        foreach ($lines as ['row' => $row, 'known' => $known]) {
            if (empty($row['external_id'])) {
                $key = $this->dayAmountKey($row);
                $knownByKey[$key] = ($knownByKey[$key] ?? 0) + (int) $known;
            }
        }

        $unmatched = [];
        foreach ($knownByKey as $key => $knownCount) {
            [$date, $amount] = explode('|', $key);
            $imported = $account->transactions()
                ->where('source', '!=', 'manual')
                ->whereDate('date', $date)
                ->where('amount_cents', (int) $amount)
                ->count();
            $unmatched[$key] = max(0, $imported - $knownCount);
        }

        return $unmatched;
    }

    /** @param  array{date: CarbonImmutable, amount_cents: int}  $row */
    private function dayAmountKey(array $row): string
    {
        return $row['date']->format('Y-m-d').'|'.$row['amount_cents'];
    }

    /**
     * Opération saisie à la main que la ligne bancaire vient confirmer : même
     * compte, même montant, date à 3 jours près (la date bancaire est souvent
     * décalée de la date d'achat). Le libellé n'est pas comparé : celui de la
     * banque est rarement celui qu'on a tapé. La plus proche en date l'emporte.
     *
     * @param  array{date: CarbonImmutable, amount_cents: int}  $row
     */
    private function manualEntryFor(Account $account, array $row): ?Transaction
    {
        return $account->transactions()
            ->where('source', 'manual')
            ->where('amount_cents', $row['amount_cents'])
            ->whereDate('date', '>=', $row['date']->subDays(3))
            ->whereDate('date', '<=', $row['date']->addDays(3))
            ->get()
            ->sortBy(fn (Transaction $t) => sprintf('%03d-%010d', (int) abs($t->date->diffInDays($row['date'])), $t->id))
            ->first();
    }

    /**
     * Identifiant stable d'une ligne : l'id de la banque quand l'export en
     * fournit un, sinon date|montant|libellé|solde suffixé d'un compteur
     * d'occurrence dans le fichier.
     *
     * @param  array{date: CarbonImmutable, amount_cents: int, label: string, balance_cents: ?int, external_id?: ?string}  $row
     * @param  array<string, int>  $seen
     */
    private function hashFor(array $row, array &$seen): string
    {
        if (! empty($row['external_id'])) {
            return hash('sha256', 'id:'.$row['external_id']);
        }

        $base = implode('|', [
            $row['date']->format('Y-m-d'),
            $row['amount_cents'],
            mb_substr($row['label'], 0, 120),
            $row['balance_cents'] ?? '',
        ]);
        $seen[$base] = ($seen[$base] ?? 0) + 1;

        return hash('sha256', $base.'#'.$seen[$base]);
    }
}
