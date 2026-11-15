<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasMonthNavigation;
use App\Livewire\Concerns\TogglesPrivateMode;
use App\Models\Budget;
use App\Models\Category;
use App\Services\StatsService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Budgets extends Component
{
    use HasMonthNavigation;
    use TogglesPrivateMode;

    /** @var array<int, string> montant saisi par catégorie (en euros) */
    public array $amounts = [];

    public function mount(): void
    {
        foreach (Budget::all() as $budget) {
            $this->amounts[$budget->category_id] = number_format($budget->amount_cents / 100, 2, Money::decimalSeparator(), '');
        }
    }

    public function saveBudget(int $categoryId): void
    {
        $raw = str_replace([',', ' '], ['.', ''], trim($this->amounts[$categoryId] ?? ''));

        // Budget à 0 € = interdiction (la jauge passe au rouge dès le 1er euro).
        // Champ vide ou invalide = pas de budget du tout.
        if ($raw === '' || ! is_numeric($raw) || (float) $raw < 0) {
            Budget::where('category_id', $categoryId)->delete();
            unset($this->amounts[$categoryId]);

            return;
        }

        Budget::updateOrCreate(
            ['category_id' => $categoryId],
            ['amount_cents' => (int) round(((float) $raw) * 100)],
        );
    }

    public function render(StatsService $stats)
    {
        $month = $this->monthDate();
        $spentByCategory = $stats->monthByCategory($month)
            ->filter(fn ($row) => $row->category)
            ->keyBy(fn ($row) => $row->category->id);

        // Moyenne des 3 mois précédant le mois affiché, par catégorie, pour suggérer un budget
        $averages = [];
        for ($i = 1; $i <= 3; $i++) {
            foreach ($stats->monthByCategory($month->subMonths($i)) as $row) {
                if ($row->category) {
                    $averages[$row->category->id] = ($averages[$row->category->id] ?? 0) + $row->spent / 3;
                }
            }
        }

        $categories = Category::where('kind', 'expense')->orderBy('position')->get();
        $budgets = Budget::pluck('amount_cents', 'category_id');

        return view('livewire.budgets', [
            'monthDate' => $month,
            'months' => $this->recentMonths(),
            'canGoNext' => $this->canGoNext(),
            'categories' => $categories,
            'budgets' => $budgets,
            'spentByCategory' => $spentByCategory,
            'averages' => $averages,
            'totalBudget' => $budgets->sum(),
            // dépenses des seules catégories budgétées, comparées à la somme de leurs budgets
            'totalSpent' => $spentByCategory->only($budgets->keys()->all())->sum('spent'),
        ])->title(__('Budgets'));
    }
}
