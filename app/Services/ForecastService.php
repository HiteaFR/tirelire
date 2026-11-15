<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Prévision de trésorerie à partir des soldes réels.
 *
 * On part du solde connu à la date de la dernière opération importée, puis on
 * simule jour par jour :
 * - les échéances datées : abonnements actifs (prochaine date = dernière
 *   occurrence + période, au montant du dernier prélèvement) et revenus
 *   réguliers (catégories de revenus marquées « fixed » : salaire, freelance) ;
 * - les dépenses courantes : moyenne journalière des 3 derniers mois complets,
 *   hors virements internes et hors marchands d'abonnements (déjà comptés
 *   comme échéances, ou terminés).
 *
 * Le découvert de départ est donc pris en compte : ce qui est « épargnable »
 * est ce qui reste au point le plus bas des semaines à venir, pas la
 * différence revenus − dépenses du mois.
 */
class ForecastService
{
    /** Nombre de mois simulés après le mois en cours. */
    private const HORIZON_MONTHS = 12;

    /**
     * Une échéance dont la date est passée depuis moins de N jours sans avoir
     * été vue est supposée imminente ; au-delà, elle est considérée sautée.
     */
    private const LATE_GRACE_DAYS = 7;

    /** Mois complets servant à la moyenne des dépenses courantes. */
    private const EVERYDAY_WINDOW_MONTHS = 3;

    public function __construct(private StatsService $stats) {}

    /**
     * @return array{
     *     anchor: CarbonImmutable,
     *     balance: int,
     *     month: CarbonImmutable,
     *     start_balance: int,
     *     realized: array{income: int, expense: int, transfers: int},
     *     remaining: array{income: int, charges: int, charges_count: int, everyday: int, days: int},
     *     month_end_balance: int,
     *     month_result: int,
     *     everyday_daily: int,
     *     everyday_window: array{from: CarbonImmutable, to: CarbonImmutable},
     *     pace: array{days: int, spent: int, expected: int, ahead: int},
     *     low_point: array{date: CarbonImmutable, balance: int},
     *     safe_until: CarbonImmutable,
     *     safe_to_save: int,
     *     monthly: array{income: int, charges: int, everyday: int, margin: int},
     *     month_ends: array<int, array{month: CarbonImmutable, balance: int}>,
     *     back_to_positive: ?CarbonImmutable,
     *     upcoming: array<int, array{date: CarbonImmutable, label: string, amount: int, late: bool, to_cancel: bool, balance: int}>,
     * }
     */
    public function forecast(?int $accountId = null): array
    {
        $today = CarbonImmutable::today();
        $month = $today->startOfMonth();
        $accounts = Account::query()->when($accountId, fn ($q) => $q->whereKey($accountId))->get();

        $lastDate = Transaction::query()->when($accountId, fn ($q) => $q->where('account_id', $accountId))->max('date');
        $anchor = $lastDate ? CarbonImmutable::parse($lastDate)->startOfDay() : $today;
        $anchor = $anchor->gt($today) ? $today : $anchor;

        // Solde arrêté à la date d'ancrage : une opération datée après aujourd'hui
        // (prélèvement programmé) ne doit pas être comptée deux fois.
        $balance = $accounts->sum(fn (Account $a) => $a->balanceAtCents($anchor));
        $startBalance = $accounts->sum(fn (Account $a) => $a->balanceAtCents($month->subDay()));
        $realized = $this->stats->monthTotals($month, $accountId);

        $horizonEnd = $month->addMonths(self::HORIZON_MONTHS)->endOfMonth()->startOfDay();
        $lowPointUntil = $month->addMonth()->endOfMonth()->startOfDay();

        $events = collect([
            ...$this->scheduledCharges($anchor, $horizonEnd, $accountId),
            ...$this->expectedIncomes($anchor, $horizonEnd, $accountId),
        ])->sortBy(fn (array $e) => $e['date']->format('Y-m-d').($e['amount'] > 0 ? '1' : '0'))->values();

        [$daily, $window] = $this->everydayDailySpending($month, $accountId);

        // Simulation jour par jour : dépenses courantes étalées, échéances à leur date.
        $byDay = $events->groupBy(fn (array $e) => $e['date']->format('Y-m-d'));
        $running = $balance;
        $low = ['date' => $anchor, 'balance' => $balance];
        $monthEnds = [];
        $dayBalances = [];
        for ($day = $anchor->addDay(); $day->lte($horizonEnd); $day = $day->addDay()) {
            $running -= $daily;
            $running += (int) $byDay->get($day->format('Y-m-d'), collect())->sum('amount');
            $dayBalances[$day->format('Y-m-d')] = $running;
            if ($day->lte($lowPointUntil) && $running < $low['balance']) {
                $low = ['date' => $day, 'balance' => $running];
            }
            if ($day->isLastOfMonth() && $day->gte($month)) {
                $monthEnds[] = ['month' => $day->startOfMonth(), 'balance' => $running];
            }
        }
        if ($anchor->isLastOfMonth() && $anchor->isSameMonth($month)) {
            array_unshift($monthEnds, ['month' => $month, 'balance' => $balance]);
        }

        $inMonth = $events->filter(fn (array $e) => $e['date']->isSameMonth($month));
        $remainingDays = $anchor->lt($month->endOfMonth()) ? (int) $anchor->diffInDays($month->endOfMonth()->startOfDay()) : 0;
        $remaining = [
            'income' => (int) $inMonth->where('amount', '>', 0)->sum('amount'),
            'charges' => (int) abs($inMonth->where('amount', '<', 0)->sum('amount')),
            'charges_count' => $inMonth->where('amount', '<', 0)->count(),
            'everyday' => $daily * $remainingDays,
            'days' => $remainingDays,
        ];

        // Rythme du mois : dépenses courantes réelles depuis le 1er, face à ce
        // que la moyenne prévoyait pour le même nombre de jours.
        $elapsedDays = $anchor->gte($month) ? $anchor->day : 0;
        $spentToDate = $elapsedDays > 0 ? $this->everydaySpending($month, $anchor->addDay(), $accountId) : 0;
        $pace = [
            'days' => $elapsedDays,
            'spent' => $spentToDate,
            'expected' => $daily * $elapsedDays,
            'ahead' => $daily * $elapsedDays - $spentToDate,
        ];

        $monthEndBalance = $monthEnds[0]['balance'] ?? $balance;
        $monthly = $this->monthlyRegime($events, $month, $daily);

        return [
            'anchor' => $anchor,
            'balance' => $balance,
            'month' => $month,
            'start_balance' => $startBalance,
            'realized' => [
                'income' => $realized['income'],
                'expense' => $realized['expense'],
                // ce qui fait bouger le solde sans être un revenu ni une dépense
                'transfers' => $balance - $startBalance - $realized['net'],
            ],
            'remaining' => $remaining,
            'month_end_balance' => $monthEndBalance,
            'month_result' => $realized['net'] + $remaining['income'] - $remaining['charges'] - $remaining['everyday'],
            'everyday_daily' => $daily,
            'everyday_window' => $window,
            'pace' => $pace,
            'low_point' => $low,
            'safe_until' => $lowPointUntil,
            'safe_to_save' => max(0, $low['balance']),
            'monthly' => $monthly,
            'month_ends' => $monthEnds,
            'back_to_positive' => $monthEndBalance >= 0
                ? null
                : collect($monthEnds)->first(fn (array $m) => $m['balance'] >= 0)['month'] ?? null,
            'upcoming' => $events
                ->filter(fn (array $e) => $e['date']->lte($lowPointUntil))
                ->map(fn (array $e) => $e + ['balance' => $dayBalances[$e['date']->format('Y-m-d')] ?? $balance])
                ->values()
                ->all(),
        ];
    }

    /**
     * Prélèvements à venir des abonnements actifs (ou à résilier : ils tombent
     * tant qu'ils ne sont pas résiliés), datés d'après leur dernière occurrence.
     *
     * @return array<int, array{date: CarbonImmutable, label: string, amount: int, late: bool, to_cancel: bool}>
     */
    private function scheduledCharges(CarbonImmutable $anchor, CarbonImmutable $until, ?int $accountId): array
    {
        $events = [];
        $all = Subscription::all();
        foreach ($all->whereIn('status', ['active', 'to_cancel']) as $subscription) {
            // Seul abonnement de ce marchand : son dernier débit fait foi même
            // hors palier (changement de tarif). Un agrégateur comme PayPal
            // porte plusieurs abonnements : il faut alors le bon palier.
            $shared = $all->where('merchant', $subscription->merchant)->count() > 1;
            $last = Transaction::query()
                ->where('merchant', $subscription->merchant)
                ->where('amount_cents', '<', 0)
                ->orderByDesc('date')->orderByDesc('id')
                ->get()
                ->first(fn (Transaction $t) => ! $shared || $subscription->matches($t));

            if (! $last || ($accountId && $last->account_id !== $accountId)) {
                continue;
            }

            // Plus prélevé depuis longtemps (résilié sans le dire à l'app) : on ne le prévoit plus
            $step = $subscription->period === 'annual' ? 12 : 1;
            if ($last->date->diffInDays($anchor) > ($step === 12 ? 480 : 75)) {
                continue;
            }

            foreach ($this->occurrences($last->date->toImmutable(), $step, $anchor, $until) as [$date, $late]) {
                $events[] = [
                    'date' => $date,
                    'label' => $subscription->label,
                    'amount' => $last->amount_cents,
                    'late' => $late,
                    'to_cancel' => $subscription->status === 'to_cancel',
                ];
            }
        }

        return $events;
    }

    /**
     * Revenus réguliers à venir : pour chaque catégorie de revenu marquée
     * « fixed », le versement principal (le plus gros du mois) doit être vu
     * sur au moins 2 des 3 derniers mois ; le prochain reprend le montant et
     * le jour du dernier versement principal.
     *
     * @return array<int, array{date: CarbonImmutable, label: string, amount: int, late: bool, to_cancel: bool}>
     */
    private function expectedIncomes(CarbonImmutable $anchor, CarbonImmutable $until, ?int $accountId): array
    {
        $events = [];
        $categories = Category::where('kind', 'income')->where('fixed', true)->get();

        foreach ($categories as $category) {
            $mainPayments = Transaction::query()
                ->where('category_id', $category->id)
                ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
                ->where('amount_cents', '>', 0)
                ->whereDate('date', '>=', $anchor->startOfMonth()->subMonths(2))
                ->whereDate('date', '<=', $anchor)
                ->get()
                ->groupBy(fn (Transaction $t) => $t->date->format('Y-m'))
                ->map(fn (Collection $monthTxs) => $monthTxs->sortByDesc('amount_cents')->first());

            if ($mainPayments->count() < 2) {
                continue;
            }

            $latest = $mainPayments->sortBy('date')->last();
            foreach ($this->occurrences($latest->date->toImmutable(), 1, $anchor, $until) as [$date, $late]) {
                $events[] = [
                    'date' => $date,
                    'label' => $category->name,
                    'amount' => $latest->amount_cents,
                    'late' => $late,
                    'to_cancel' => false,
                ];
            }
        }

        return $events;
    }

    /**
     * Dates des prochaines occurrences après $anchor, de $stepMonths en
     * $stepMonths depuis $last. Une occurrence en retard de quelques jours
     * est replacée au lendemain de $anchor.
     *
     * @return array<int, array{0: CarbonImmutable, 1: bool}>
     */
    private function occurrences(CarbonImmutable $last, int $stepMonths, CarbonImmutable $anchor, CarbonImmutable $until): array
    {
        $dates = [];
        for ($k = 1; ($date = $last->startOfDay()->addMonthsNoOverflow($k * $stepMonths))->lte($until); $k++) {
            if ($date->gt($anchor)) {
                $dates[] = [$date, false];
            } elseif ($date->diffInDays($anchor) <= self::LATE_GRACE_DAYS) {
                $dates[] = [$anchor->addDay(), true];
            }
        }

        return $dates;
    }

    /**
     * Dépense courante moyenne par jour (en centimes) sur les 3 derniers mois
     * complets, ou depuis la première opération si l'historique est plus court.
     *
     * @return array{0: int, 1: array{from: CarbonImmutable, to: CarbonImmutable}}
     */
    private function everydayDailySpending(CarbonImmutable $month, ?int $accountId): array
    {
        $to = $month;
        $from = $month->subMonths(self::EVERYDAY_WINDOW_MONTHS);
        $firstDate = Transaction::query()->when($accountId, fn ($q) => $q->where('account_id', $accountId))->min('date');
        if ($firstDate && CarbonImmutable::parse($firstDate)->startOfDay()->gt($from)) {
            $from = CarbonImmutable::parse($firstDate)->startOfDay();
        }

        $window = ['from' => $from, 'to' => $to->subDay()];
        $days = (int) $from->diffInDays($to);
        if ($days <= 0) {
            return [0, $window];
        }

        $total = $this->everydaySpending($from, $to, $accountId);

        return [(int) round($total / $days), $window];
    }

    /**
     * Dépenses courantes (en centimes, positif) du jour $from inclus au jour
     * $to exclu : hors virements internes et hors marchands d'abonnements.
     */
    private function everydaySpending(CarbonImmutable $from, CarbonImmutable $to, ?int $accountId): int
    {
        // Tout le marchand est exclu, pas seulement le palier de l'abonnement :
        // un ancien tarif (assurance à 30 € puis 35 €) reste une échéance.
        $recurringMerchants = Subscription::distinct()->pluck('merchant');
        $transferIds = Category::where('kind', 'transfer')->pluck('id');

        return (int) abs(Transaction::query()
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->where('amount_cents', '<', 0)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<', $to)
            ->when($transferIds->isNotEmpty(), fn ($q) => $q->where(fn ($qq) => $qq
                ->whereNull('category_id')
                ->orWhereNotIn('category_id', $transferIds)))
            ->when($recurringMerchants->isNotEmpty(), fn ($q) => $q->where(fn ($qq) => $qq
                ->whereNull('merchant')
                ->orWhereNotIn('merchant', $recurringMerchants)))
            ->sum('amount_cents'));
    }

    /**
     * Régime mensuel de croisière, d'après les échéances du mois suivant (un
     * mois complet, sans retard ni versement déjà passé) et la moyenne courante.
     *
     * @param  Collection<int, array{date: CarbonImmutable, amount: int}>  $events
     * @return array{income: int, charges: int, everyday: int, margin: int}
     */
    private function monthlyRegime(Collection $events, CarbonImmutable $month, int $daily): array
    {
        $next = $month->addMonth();
        $nextMonthEvents = $events->filter(fn (array $e) => $e['date']->isSameMonth($next) && ! $e['late']);
        $income = (int) $nextMonthEvents->where('amount', '>', 0)->sum('amount');
        $charges = (int) abs($nextMonthEvents->where('amount', '<', 0)->sum('amount'));
        $everyday = (int) round($daily * 365 / 12);

        return [
            'income' => $income,
            'charges' => $charges,
            'everyday' => $everyday,
            'margin' => $income - $charges - $everyday,
        ];
    }
}
