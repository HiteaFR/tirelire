<?php

namespace App\Services;

use App\Models\CategoryRule;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class Categorizer
{
    /** @var Collection<int, CategoryRule>|null */
    private ?Collection $rules = null;

    public function categoryIdFor(string $label): ?int
    {
        $this->rules ??= CategoryRule::orderBy('priority')->orderBy('id')->get();

        foreach ($this->rules as $rule) {
            if (self::matches($label, $rule->pattern)) {
                return $rule->category_id;
            }
        }

        return null;
    }

    /**
     * Le motif est cherché dans le libellé sans tenir compte de la casse.
     * Une espace est ajoutée en fin de libellé : un motif « EDF » suivi d'une
     * espace (pour ne pas attraper un mot plus long) trouve aussi un libellé
     * qui finit par EDF.
     */
    public static function matches(string $label, string $pattern): bool
    {
        return mb_strpos(mb_strtoupper($label).' ', mb_strtoupper($pattern)) !== false;
    }

    /** Nombre d'opérations existantes dont le libellé contient le motif. */
    public function countMatches(string $pattern): int
    {
        if (trim($pattern) === '') {
            return 0;
        }

        return Transaction::query()->toBase()->pluck('label')
            ->filter(fn (string $label): bool => self::matches($label, $pattern))
            ->count();
    }

    /**
     * Réapplique les règles aux opérations existantes. Par défaut, seules les
     * opérations sans catégorie sont traitées : une catégorie déjà posée, à la
     * main ou par une règle, est gardée. Avec $all, toutes les opérations dont
     * une règle donne une autre catégorie sont modifiées, choix manuels compris.
     *
     * @return int Nombre d'opérations modifiées.
     */
    public function recategorize(bool $all = false, ?string $labelContains = null): int
    {
        $this->refresh();

        $query = Transaction::query()
            ->when(! $all, fn ($q) => $q->whereNull('category_id'))
            ->when($labelContains, fn ($q, $text) => $q->where('label', 'like', '%'.$text.'%'));

        $updated = 0;
        foreach ($query->cursor() as $transaction) {
            $categoryId = $this->categoryIdFor($transaction->label);
            if ($categoryId !== null && $categoryId !== $transaction->category_id) {
                $transaction->update(['category_id' => $categoryId]);
                $updated++;
            }
        }

        return $updated;
    }

    public function refresh(): void
    {
        $this->rules = null;
    }
}
