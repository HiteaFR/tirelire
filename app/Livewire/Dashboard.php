<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasMonthNavigation;
use App\Livewire\Concerns\TogglesPrivateMode;
use App\Models\Account;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\ForecastService;
use App\Services\StatsService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    use HasMonthNavigation;
    use TogglesPrivateMode;

    #[Url]
    public ?int $account = null;

    public function selectAccount(?int $accountId): void
    {
        $this->account = $accountId ?: null;
    }

    public function render(StatsService $stats, ForecastService $forecasts)
    {
        $month = $this->monthDate();
        $now = CarbonImmutable::now();
        $accounts = Account::all();
        if ($this->account && ! $accounts->contains('id', $this->account)) {
            $this->account = null;
        }
        $isCurrentMonth = $month->isSameMonth($now);
        // Mois passé : patrimoine arrêté au dernier jour du mois affiché
        $balanceDate = $isCurrentMonth ? null : $month->endOfMonth()->startOfDay();
        $scoped = $this->account ? $accounts->where('id', $this->account) : $accounts;
        $accountRows = $scoped->map(fn (Account $a) => [
            'account' => $a,
            'balance' => $balanceDate ? $a->balanceAtCents($balanceDate) : $a->currentBalanceCents(),
            'date' => $balanceDate ?? $a->lastActivityDate(),
        ])->values();

        $totals = $stats->monthTotals($month, $this->account);
        $kpis = $stats->monthKpis($month, $this->account);

        // Mois en cours : prévision depuis les soldes réels. Mois passé : bilan.
        $forecast = $isCurrentMonth ? $forecasts->forecast($this->account) : null;
        $monthStartBalance = $scoped->sum(fn (Account $a) => $a->balanceAtCents($month->subDay()));
        $savingsRate = $forecast
            ? $this->rate($forecast['month_result'], $forecast['realized']['income'] + $forecast['remaining']['income'])
            : $kpis['savings_rate'];

        $recent = Transaction::with(['account', 'category'])
            ->when($this->account, fn ($q) => $q->where('account_id', $this->account))
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(5)->get();

        return view('livewire.dashboard', [
            'monthDate' => $month,
            'months' => $this->recentMonths(),
            'canGoNext' => $this->canGoNext(),
            'isCurrentMonth' => $isCurrentMonth,
            'balanceDate' => $balanceDate,
            'monthStartBalance' => $monthStartBalance,
            'forecast' => $forecast,
            'savingsRate' => $savingsRate,
            'accounts' => $accounts,
            'accountRows' => $accountRows,
            'selectedAccount' => $this->account ? $accounts->firstWhere('id', $this->account) : null,
            'totalBalance' => $accountRows->sum('balance'),
            'updatedAt' => $accountRows->pluck('date')->filter()->max(),
            'lastTransaction' => $recent->first(),
            'recent' => $recent,
            'totals' => $totals,
            'kpis' => $kpis,
            'byCategory' => $stats->monthByCategory($month, $this->account),
            'series' => $stats->monthlySeries(6, $this->account),
            'resumedSubscriptions' => Subscription::whereNotNull('resumed_on')->orderBy('resumed_on')->get(),
        ])->title(config('app.name'));
    }

    /** Part du revenu non dépensée, en %, ou null sans revenu. */
    private function rate(int $result, int $income): ?int
    {
        return $income > 0 ? (int) round($result / $income * 100) : null;
    }
}
