<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Détecte les prélèvements récurrents (abonnements, crédits, assurances…)
 * en regroupant les débits par marchand normalisé puis par palier de montant
 * (un agrégateur comme PayPal porte plusieurs abonnements), et en cherchant
 * une périodicité mensuelle ou annuelle sur chaque palier. Un changement de
 * tarif ouvre un nouveau palier qui prend le relais de l'ancien : les deux
 * sont enchaînés en une seule série, au tarif le plus récent.
 *
 * Les abonnements marqués à la main (source = manual) et les statuts posés
 * par l'utilisateur ne sont jamais écrasés : la détection ne fait que
 * rafraîchir leurs statistiques. Seule exception : un abonnement résilié
 * prélevé après sa date de résiliation redevient actif (avec alerte).
 */
class SubscriptionDetector
{
    /**
     * Trois prélèvements réguliers avant de qualifier automatiquement : deux
     * passages en caisse à un mois d'écart ne font pas un abonnement. Un
     * abonnement tout neuf se marque à la main depuis ses opérations.
     */
    private const MIN_OCCURRENCES = 3;

    public function detect(): int
    {
        $transferIds = Category::where('kind', 'transfer')->pluck('id');

        $byMerchant = Transaction::query()
            ->where('amount_cents', '<', 0)
            ->when($transferIds->isNotEmpty(), fn ($q) => $q->where(fn ($qq) => $qq
                ->whereNull('category_id')
                ->orWhereNotIn('category_id', $transferIds)))
            ->whereNotNull('merchant')
            ->where('merchant', '!=', '')
            ->orderBy('date')
            ->get(['merchant', 'date', 'amount_cents', 'label', 'category_id'])
            ->groupBy('merchant');

        $existing = Subscription::all();
        $kept = [];
        $found = 0;

        foreach ($byMerchant as $merchant => $txs) {
            foreach ($this->chainPriceChanges($this->clusterByAmount($txs)) as $segments) {
                $cluster = collect($segments)->flatten(1)->sortBy('date')->values();
                $current = end($segments);

                // L'entrée existante est cherchée du tarif le plus récent au plus ancien
                $subscription = null;
                foreach (array_reverse($segments) as $segment) {
                    $subscription = $existing->first(fn (Subscription $s) => $s->merchant === $merchant
                        && Subscription::sameBucket($s->bucket_cents, $segment->first()->amount_cents));
                    if ($subscription) {
                        break;
                    }
                }

                $period = $this->detectPeriod($cluster->pluck('date')->all());
                $last = $cluster->last();
                $staleAfterDays = $period === 'annual' ? 480 : 75;
                $recurring = $period !== null && $last->date->diffInDays(now()) <= $staleAfterDays;

                // Résilié puis prélevé à nouveau : reprise (ou résiliation ratée),
                // l'abonnement redevient actif et l'utilisateur est alerté.
                $resumedOn = $subscription?->status === 'cancelled' && $subscription->cancelled_at
                    ? $cluster->first(fn (Transaction $t) => $t->date->gt($subscription->cancelled_at))?->date
                    : null;

                // Pas (ou plus) récurrent : on ne crée rien, et on laisse tomber
                // l'entrée auto — sauf si l'utilisateur l'a marquée lui-même.
                if (! $recurring && ! $subscription?->isManual() && ! $resumedOn) {
                    continue;
                }

                // Montants au tarif en vigueur ; occurrences et périodicité sur toute la série
                $bucket = (int) abs($this->median($current->pluck('amount_cents')->all()));
                $bucketTaken = $existing->contains(fn (Subscription $s) => $s->merchant === $merchant
                    && $s->bucket_cents === $bucket && $s->id !== $subscription?->id);
                $stats = [
                    ...($bucketTaken ? [] : ['bucket_cents' => $bucket]),
                    'category_id' => $last->category_id,
                    'avg_amount_cents' => (int) abs(round($current->avg('amount_cents'))),
                    'last_date' => $last->date,
                    'occurrences' => $cluster->count(),
                ];

                if ($subscription) {
                    $subscription->update($stats
                        + ($subscription->isManual() || $period === null ? [] : ['period' => $period])
                        + ($resumedOn ? ['status' => 'active', 'cancelled_at' => null, 'resumed_on' => $resumedOn] : []));
                } else {
                    $subscription = Subscription::create($stats + [
                        'merchant' => $merchant,
                        'label' => Subscription::prettyLabel($merchant),
                        'period' => $period,
                    ]);
                    $existing->push($subscription);
                }

                $kept[] = $subscription->id;
                $found++;
            }
        }

        // Entrées auto dont la récurrence a disparu (résiliation, changement de
        // libellé…) : on les retire, en respectant les statuts posés à la main.
        Subscription::where('source', 'auto')
            ->where('status', 'active')
            ->whereNotIn('id', $kept)
            ->delete();

        return $found;
    }

    /**
     * Découpe les opérations d'un marchand en paliers de montant (triés par
     * montant croissant, un palier absorbe tout ce qui est proche de son
     * premier élément). Chaque palier est rendu trié par date ; les paliers
     * trop petits pour la détection auto sont conservés pour rafraîchir les
     * abonnements marqués à la main.
     *
     * @param  Collection<int, Transaction>  $txs
     * @return array<int, Collection<int, Transaction>>
     */
    private function clusterByAmount(Collection $txs): array
    {
        $clusters = [];
        $current = null;
        $anchor = 0;

        foreach ($txs->sortBy(fn (Transaction $t) => abs($t->amount_cents))->values() as $tx) {
            if ($current !== null && Subscription::sameBucket($anchor, $tx->amount_cents)) {
                $current->push($tx);

                continue;
            }
            $current = collect([$tx]);
            $anchor = $tx->amount_cents;
            $clusters[] = $current;
        }

        return array_map(fn (Collection $c) => $c->sortBy('date')->values(), $clusters);
    }

    /**
     * Enchaîne les paliers successifs d'un même marchand : un palier qui
     * démarre 20 à 75 jours (un mois sauté toléré) après la fin d'une série
     * déjà mensuelle, sans aucune opération pendant celle-ci, en est la suite
     * à un nouveau tarif (abonnement 10 € → 12 € → 15 €). Un mois hors forfait
     * isolé ou des achats épars ne forment pas une série et n'enchaînent rien. Deux paliers qui se chevauchent
     * restent deux abonnements distincts (PayPal : deux services à des prix différents). Les
     * opérations d'un palier antérieures à la série (ex. une facture de
     * rentrée plus élevée un an plus tôt) en sont écartées.
     *
     * @param  array<int, Collection<int, Transaction>>  $clusters  chacun trié par date
     * @return array<int, array<int, Collection<int, Transaction>>> séries de paliers, du plus ancien au plus récent
     */
    private function chainPriceChanges(array $clusters): array
    {
        $chains = array_map(fn (Collection $c) => [$c], $clusters);

        do {
            $merged = false;
            // La série qui s'arrête le plus tôt cherche sa suite en premier
            uasort($chains, fn (array $a, array $b) => end($a)->last()->date <=> end($b)->last()->date);
            foreach ($chains as $i => $chain) {
                $dates = collect($chain)->flatten(1)->pluck('date')->sort()->values()->all();
                if ($this->detectPeriod($dates) !== 'monthly') {
                    continue;
                }
                $first = $chain[0]->first()->date;
                $last = end($chain)->last()->date;
                foreach ($chains as $j => $candidate) {
                    if ($i === $j || count($candidate) > 1) {
                        continue;
                    }
                    $next = $candidate[0];
                    $overlaps = $next->contains(fn (Transaction $t) => $t->date->between($first, $last));
                    $after = $next->filter(fn (Transaction $t) => $t->date->gt($last))->values();
                    $gap = $after->isEmpty() ? null : (int) $last->diffInDays($after->first()->date);
                    if (! $overlaps && $gap !== null && $gap >= 20 && $gap <= 75) {
                        $chains[$i][] = $after;
                        unset($chains[$j]);
                        $merged = true;

                        continue 3;
                    }
                }
            }
        } while ($merged);

        return array_values($chains);
    }

    /**
     * Périodicité d'après la médiane des écarts entre dates : un écart isolé
     * (prélèvement sauté, mois hors-forfait sorti du palier) ne casse pas la
     * détection tant que la majorité des écarts reste mensuelle.
     *
     * @param  array<int, CarbonImmutable|Carbon>  $dates  triées croissant
     */
    private function detectPeriod(array $dates): ?string
    {
        if (count($dates) < self::MIN_OCCURRENCES) {
            return null;
        }

        $gaps = [];
        for ($i = 1; $i < count($dates); $i++) {
            $gaps[] = (int) $dates[$i - 1]->diffInDays($dates[$i]);
        }
        $medianGap = $this->median($gaps);
        $monthlyGaps = count(array_filter($gaps, fn (int $g) => $g >= 20 && $g <= 45));

        return match (true) {
            $medianGap >= 25 && $medianGap <= 36 && $monthlyGaps / count($gaps) >= 0.6 => 'monthly',
            $medianGap >= 330 && $medianGap <= 400 => 'annual',
            default => null,
        };
    }

    /** @param  array<int, int>  $values */
    private function median(array $values): int
    {
        $values = array_map('abs', $values);
        sort($values);

        return $values[intdiv(count($values), 2)];
    }
}
