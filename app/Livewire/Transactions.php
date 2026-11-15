<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasMonthNavigation;
use App\Livewire\Concerns\TogglesPrivateMode;
use App\Models\Account;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\SubscriptionDetector;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Transactions extends Component
{
    use HasMonthNavigation;
    use TogglesPrivateMode;
    use WithPagination;

    /** 0 = « non catégorisé », null = toutes */
    #[Url]
    public ?int $category = null;

    #[Url]
    public ?int $account = null;

    #[Url]
    public string $search = '';

    public bool $showForm = false;

    // Fiche d'une opération (feuille en bas d'écran)
    public ?int $selectedId = null;

    public string $subscriptionLabel = '';

    public string $subscriptionPeriod = 'monthly';

    // Formulaire d'ajout manuel
    public string $formDate = '';

    public string $formLabel = '';

    public string $formAmount = '';

    public string $formType = 'expense';

    public ?int $formCategory = null;

    public ?int $formAccount = null;

    public function mount(): void
    {
        $this->formDate = now()->format('Y-m-d');
        $this->formAccount = $this->account ?: Account::first()?->id;
    }

    public function updatedAccount(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    protected function monthChanged(): void
    {
        $this->resetPage();
    }

    public function setCategory(Transaction $transaction, ?int $categoryId): void
    {
        $transaction->update(['category_id' => $categoryId ?: null]);
    }

    public function open(Transaction $transaction): void
    {
        $this->selectedId = $transaction->id;
        $this->subscriptionLabel = Subscription::prettyLabel($transaction->merchant ?: $transaction->label);
        $this->subscriptionPeriod = 'monthly';
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->selectedId = null;
    }

    /**
     * Marque l'opération comme abonnement : toutes les opérations du même
     * marchand au même palier de montant (passées et futures) y sont rattachées.
     */
    public function markAsSubscription(Transaction $transaction): void
    {
        $this->validate([
            'subscriptionLabel' => 'required|string|max:100',
            'subscriptionPeriod' => 'in:monthly,annual',
        ]);

        if ($transaction->amount_cents >= 0 || ! $transaction->merchant) {
            return;
        }

        $subscription = $this->subscriptionFor($transaction);
        if ($subscription) {
            $subscription->update(['label' => trim($this->subscriptionLabel), 'source' => 'manual']);
            $subscription->changeStatus('active');
        } else {
            Subscription::create([
                'merchant' => $transaction->merchant,
                'bucket_cents' => abs($transaction->amount_cents),
                'label' => trim($this->subscriptionLabel),
                'category_id' => $transaction->category_id,
                'period' => $this->subscriptionPeriod,
                'avg_amount_cents' => abs($transaction->amount_cents),
                'last_date' => $transaction->date,
                'occurrences' => 1,
                'status' => 'active',
                'source' => 'manual',
            ]);
        }

        // Rafraîchit les statistiques (occurrences, moyenne) sur l'historique
        app(SubscriptionDetector::class)->detect();
    }

    /** L'opération n'est pas un abonnement : l'entrée passe en « ignoré » (archivée). */
    public function unmarkSubscription(Transaction $transaction): void
    {
        $this->subscriptionFor($transaction)?->changeStatus('ignored');
    }

    private function subscriptionFor(Transaction $transaction): ?Subscription
    {
        return Subscription::where('merchant', $transaction->merchant)->get()
            ->first(fn (Subscription $s) => $s->matches($transaction));
    }

    public function deleteManual(Transaction $transaction): void
    {
        if ($transaction->source === 'manual') {
            $transaction->delete();
        }
    }

    public function save(): void
    {
        $this->validate([
            'formDate' => 'required|date',
            'formLabel' => 'required|string|max:255',
            'formAmount' => 'required|numeric|min:0.01',
            'formAccount' => 'nullable|exists:accounts,id',
            'formCategory' => 'nullable|exists:categories,id',
        ]);

        $cents = (int) round(((float) str_replace(',', '.', $this->formAmount)) * 100);

        Transaction::create([
            'account_id' => $this->formAccount ?? Account::first()?->id ?? Account::create(['name' => __('Current account')])->id,
            'category_id' => $this->formCategory,
            'date' => $this->formDate,
            'amount_cents' => $this->formType === 'expense' ? -$cents : $cents,
            'label' => $this->formLabel,
            'merchant' => mb_strtoupper($this->formLabel),
            'source' => 'manual',
        ]);

        $this->reset('showForm', 'formLabel', 'formAmount', 'formCategory');
        $this->formDate = now()->format('Y-m-d');
    }

    public function render()
    {
        $monthDate = $this->monthDate();

        $query = Transaction::query()
            ->whereDate('date', '>=', $monthDate->startOfMonth())
            ->whereDate('date', '<=', $monthDate->endOfMonth())
            ->when($this->account, fn ($q) => $q->where('account_id', $this->account))
            ->when($this->category === 0, fn ($q) => $q->whereNull('category_id'))
            ->when($this->category > 0, fn ($q) => $q->where('category_id', $this->category))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('label', 'like', '%'.trim($this->search).'%'));

        $sums = (clone $query)
            ->selectRaw('SUM(CASE WHEN amount_cents > 0 THEN amount_cents ELSE 0 END) as income, SUM(CASE WHEN amount_cents < 0 THEN amount_cents ELSE 0 END) as expense, COUNT(*) as count')
            ->first();

        $transactions = $query->with('category', 'account')
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(50);

        // Abonnements rattachés aux opérations affichées (même marchand, même palier) ;
        // une entrée « pas un abo » ne s'affiche plus et peut être re-marquée.
        $subscriptions = Subscription::whereIn('merchant', $transactions->pluck('merchant')->filter()->unique())
            ->where('status', '!=', 'ignored')->get();
        $subscriptionByTransaction = $transactions->getCollection()
            ->mapWithKeys(fn (Transaction $t) => [$t->id => $subscriptions->first(fn (Subscription $s) => $s->matches($t))]);

        $selected = $this->selectedId ? $transactions->getCollection()->firstWhere('id', $this->selectedId) : null;
        if ($this->selectedId && ! $selected) {
            $this->selectedId = null;
        }

        return view('livewire.transactions', [
            'monthDate' => $monthDate,
            'months' => $this->recentMonths(),
            'transactions' => $transactions,
            'subscriptionByTransaction' => $subscriptionByTransaction,
            'selected' => $selected,
            'selectedSubscription' => $selected ? $subscriptionByTransaction[$selected->id] : null,
            'income' => (int) $sums->income,
            'expense' => (int) abs((int) $sums->expense),
            'count' => (int) $sums->count,
            'categories' => Category::orderBy('position')->get(),
            'accounts' => Account::all(),
            'canGoNext' => $this->canGoNext(),
        ])->title(__('Operations'));
    }
}
