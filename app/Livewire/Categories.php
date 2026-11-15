<?php

namespace App\Livewire;

use App\Livewire\Concerns\TogglesPrivateMode;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Categorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Catégories et règles de catégorisation : création, modification,
 * suppression (avec déplacement des opérations), aperçu des opérations
 * qu'attrape un motif et recatégorisation des opérations existantes.
 */
#[Layout('components.layouts.app')]
class Categories extends Component
{
    use TogglesPrivateMode;

    public const KINDS = ['expense', 'income', 'transfer'];

    public const MAX_PRIORITY = 1000;

    /** Catégorie dépliée pour modification. */
    public ?int $openId = null;

    public string $name = '';

    public string $icon = '';

    public string $kind = 'expense';

    public bool $fixed = false;

    public string $newName = '';

    public string $newIcon = '';

    public string $newKind = 'expense';

    public bool $newFixed = false;

    public string $pattern = '';

    public int|string $priority = 100;

    public ?int $editingRuleId = null;

    public string $editPattern = '';

    public int|string $editPriority = 100;

    /** Destination des opérations de la catégorie supprimée : id, ou '' pour « non catégorisé ». */
    public string $moveTo = '';

    public bool $recategorizeAll = false;

    public ?int $recategorized = null;

    public function createCategory(): void
    {
        // Trimé avant la validation : sinon « Courses » suivi d'une espace passerait unique.
        $this->newName = trim($this->newName);

        $this->validate([
            'newName' => ['required', 'string', 'max:50', Rule::unique('categories', 'name')],
            'newIcon' => ['nullable', 'string', 'max:8'],
            'newKind' => ['required', Rule::in(self::KINDS)],
            'newFixed' => ['boolean'],
        ], attributes: ['newName' => __('name'), 'newIcon' => __('icon'), 'newKind' => __('type')]);

        Category::create([
            'name' => $this->newName,
            'icon' => $this->newIcon !== '' ? $this->newIcon : '📁',
            'kind' => $this->newKind,
            'fixed' => $this->newFixed,
            'position' => (int) Category::max('position') + 1,
        ]);

        $this->reset('newName', 'newIcon', 'newKind', 'newFixed');
    }

    public function open(Category $category): void
    {
        if ($this->openId === $category->id) {
            $this->close();

            return;
        }

        $this->close();
        $this->openId = $category->id;
        $this->name = $category->name;
        $this->icon = $category->icon;
        $this->kind = $category->kind;
        $this->fixed = $category->fixed;
    }

    public function close(): void
    {
        $this->reset('openId', 'name', 'icon', 'kind', 'fixed', 'pattern', 'priority', 'editingRuleId', 'editPattern', 'editPriority', 'moveTo');
        $this->resetValidation();
    }

    public function updateCategory(): void
    {
        $category = $this->openCategory();
        $this->name = trim($this->name);

        $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('categories', 'name')->ignore($category->id)],
            'icon' => ['nullable', 'string', 'max:8'],
            'kind' => ['required', Rule::in(self::KINDS)],
            'fixed' => ['boolean'],
        ], attributes: ['name' => __('name'), 'icon' => __('icon'), 'kind' => __('type')]);

        $category->update([
            'name' => $this->name,
            'icon' => $this->icon !== '' ? $this->icon : '📁',
            'kind' => $this->kind,
            'fixed' => $this->fixed,
        ]);
    }

    /**
     * Supprime la catégorie ouverte. Ses opérations et abonnements passent
     * dans la catégorie choisie (ou sans catégorie) : aucune opération n'est
     * perdue. Refusé tant qu'un budget y est rattaché. Ses règles disparaissent.
     */
    public function deleteCategory(): void
    {
        $category = $this->openCategory();

        $this->validate(
            ['moveTo' => ['nullable', Rule::exists('categories', 'id'), Rule::notIn([$category->id])]],
            attributes: ['moveTo' => __('destination')],
        );

        if ($category->budget()->exists()) {
            $this->addError('moveTo', __('Remove the budget of this category first.'));

            return;
        }

        $destination = $this->moveTo !== '' ? (int) $this->moveTo : null;

        DB::transaction(function () use ($category, $destination) {
            Transaction::where('category_id', $category->id)->update(['category_id' => $destination]);
            Subscription::where('category_id', $category->id)->update(['category_id' => $destination]);
            $category->delete();
        });

        $this->close();
    }

    public function addRule(): void
    {
        $category = $this->openCategory();
        $this->pattern = mb_strtoupper($this->pattern);

        $this->validate([
            'pattern' => $this->patternRules($category->id),
            'priority' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PRIORITY],
        ], attributes: ['pattern' => __('pattern'), 'priority' => __('priority')]);

        $category->rules()->create(['pattern' => $this->pattern, 'priority' => (int) $this->priority]);

        $this->reset('pattern', 'priority');
    }

    public function editRule(CategoryRule $rule): void
    {
        $this->editingRuleId = $rule->id;
        $this->editPattern = $rule->pattern;
        $this->editPriority = $rule->priority;
        $this->resetValidation();
    }

    public function updateRule(): void
    {
        $rule = CategoryRule::where('category_id', $this->openId)->findOrFail($this->editingRuleId);
        $this->editPattern = mb_strtoupper($this->editPattern);

        $this->validate([
            'editPattern' => $this->patternRules($rule->category_id, $rule->id),
            'editPriority' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PRIORITY],
        ], attributes: ['editPattern' => __('pattern'), 'editPriority' => __('priority')]);

        $rule->update(['pattern' => $this->editPattern, 'priority' => (int) $this->editPriority]);

        $this->reset('editingRuleId', 'editPattern', 'editPriority');
    }

    public function cancelRuleEdit(): void
    {
        $this->reset('editingRuleId', 'editPattern', 'editPriority');
        $this->resetValidation();
    }

    public function deleteRule(CategoryRule $rule): void
    {
        $rule->delete();

        if ($this->editingRuleId === $rule->id) {
            $this->cancelRuleEdit();
        }
    }

    public function recategorize(Categorizer $categorizer): void
    {
        $this->recategorized = $categorizer->recategorize($this->recategorizeAll);
    }

    /** Opérations existantes qu'attrape le motif en cours de saisie (nouvelle règle ou règle modifiée). */
    #[Computed]
    public function matchCount(): int
    {
        $pattern = $this->editingRuleId !== null ? $this->editPattern : $this->pattern;

        return app(Categorizer::class)->countMatches($pattern);
    }

    public function render()
    {
        return view('livewire.categories', [
            'categories' => Category::withCount(['transactions', 'rules'])->orderBy('position')->orderBy('id')->get(),
            'openCategory' => $this->openId !== null
                ? Category::with(['rules' => fn ($q) => $q->orderBy('priority')->orderBy('id')])->withCount('transactions')->find($this->openId)
                : null,
        ])->title(__('Categories & rules'));
    }

    private function openCategory(): Category
    {
        return Category::findOrFail($this->openId);
    }

    /** @return array<int, mixed> */
    private function patternRules(int $categoryId, ?int $ignoreRuleId = null): array
    {
        return [
            'required',
            'string',
            'max:100',
            // Doublon insensible à la casse : les motifs en base ne sont pas tous en majuscules.
            function (string $attribute, mixed $value, \Closure $fail) use ($categoryId, $ignoreRuleId): void {
                $taken = CategoryRule::where('category_id', $categoryId)
                    ->when($ignoreRuleId, fn ($q, $id) => $q->whereKeyNot($id))
                    ->pluck('pattern')
                    ->contains(fn (string $pattern): bool => mb_strtoupper($pattern) === mb_strtoupper((string) $value));

                if ($taken) {
                    $fail('validation.unique')->translate();
                }
            },
        ];
    }
}
