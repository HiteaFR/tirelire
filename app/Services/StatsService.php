<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class StatsService
{
    /**
     * Totaux d'un mois : revenus, dépenses, épargne (revenus - dépenses).
     * Les virements internes (kind = transfer) sont exclus des deux côtés.
     *
     * @return array{income: int, expense: int, net: int}
     */
    public function monthTotals(CarbonImmutable $month, ?int $accountId = null): array
    {
        $rows = $this->baseQuery($month, $accountId)
            ->selectRaw('CASE WHEN amount_cents > 0 THEN 1 ELSE 0 END as is_income, SUM(amount_cents) as total')
            ->groupBy('is_income')
            ->pluck('total', 'is_income');

        $income = (int) ($rows[1] ?? 0);
        $expense = (int) abs($rows[0] ?? 0);

        return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
    }

    /**
     * Dépenses du mois par catégorie, avec budget éventuel.
     *
     * @return Collection<int, object{category: ?Category, spent: int, budget: ?int}>
     */
    public function monthByCategory(CarbonImmutable $month, ?int $accountId = null): Collection
    {
        $spent = $this->baseQuery($month, $accountId)
            ->where('amount_cents', '<', 0)
            ->selectRaw('category_id, SUM(ABS(amount_cents)) as spent')
            ->groupBy('category_id')
            ->pluck('spent', 'category_id');

        $categories = Category::whereIn('id', $spent->keys()->filter())->get()->keyBy('id');
        $budgets = Budget::pluck('amount_cents', 'category_id');

        return $spent
            ->map(fn ($amount, $catId) => (object) [
                'category' => $catId ? $categories->get($catId) : null,
                'spent' => (int) $amount,
                'budget' => $budgets->get($catId),
            ])
            ->sortByDesc('spent')
            ->values();
    }

    /**
     * Série des N derniers mois (mois courant inclus) pour le graphique
     * revenus / dépenses / net.
     *
     * @return array<int, array{month: CarbonImmutable, income: int, expense: int, net: int}>
     */
    public function monthlySeries(int $months = 6, ?int $accountId = null): array
    {
        $out = [];
        $current = CarbonImmutable::now()->startOfMonth();
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = $current->subMonths($i);
            $out[] = ['month' => $m, ...$this->monthTotals($m, $accountId)];
        }

        return $out;
    }

    /** Dernière opération enregistrée (la plus récente par date puis par id). */
    public function lastTransaction(?int $accountId = null): ?Transaction
    {
        return Transaction::query()
            ->with(['account', 'category'])
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->orderByDesc('date')->orderByDesc('id')
            ->first();
    }

    /**
     * Indicateurs clés du mois (virements internes exclus) :
     * - savings_rate : part du revenu non dépensée (null si aucun revenu)
     * - avg_daily_variable : dépenses variables / jours écoulés du mois
     * - fixed_expense / variable_expense : dépenses des catégories fixes vs variables
     * - biggest_expense : plus grosse dépense du mois
     *
     * @return array{count: int, savings_rate: ?int, avg_daily_variable: int, fixed_expense: int, variable_expense: int, biggest_expense: ?Transaction}
     */
    public function monthKpis(CarbonImmutable $month, ?int $accountId = null): array
    {
        $totals = $this->monthTotals($month, $accountId);
        $fixedIds = Category::where('fixed', true)->pluck('id');

        $fixed = (int) abs($this->baseQuery($month, $accountId)
            ->where('amount_cents', '<', 0)
            ->whereIn('category_id', $fixedIds)
            ->sum('amount_cents'));

        $biggest = $this->baseQuery($month, $accountId)
            ->with('category')
            ->where('amount_cents', '<', 0)
            ->orderBy('amount_cents')
            ->first();

        $now = CarbonImmutable::now();
        $daysElapsed = match (true) {
            $month->isSameMonth($now) => $now->day,
            $month->lt($now) => $month->daysInMonth,
            default => 0,
        };

        return [
            'count' => $this->baseQuery($month, $accountId)->count(),
            'savings_rate' => $totals['income'] > 0
                ? (int) round($totals['net'] / $totals['income'] * 100)
                : null,
            'avg_daily_variable' => $daysElapsed > 0 ? (int) round(($totals['expense'] - $fixed) / $daysElapsed) : 0,
            'fixed_expense' => $fixed,
            'variable_expense' => $totals['expense'] - $fixed,
            'biggest_expense' => $biggest,
        ];
    }

    private function baseQuery(CarbonImmutable $month, ?int $accountId)
    {
        $transferIds = Category::where('kind', 'transfer')->pluck('id');

        return Transaction::query()
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->whereDate('date', '>=', $month->startOfMonth())
            ->whereDate('date', '<=', $month->endOfMonth())
            ->when($transferIds->isNotEmpty(), fn ($q) => $q->where(fn ($qq) => $qq
                ->whereNull('category_id')
                ->orWhereNotIn('category_id', $transferIds)));
    }
}
