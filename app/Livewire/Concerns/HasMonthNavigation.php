<?php

namespace App\Livewire\Concerns;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * Sélection de mois partagée entre les pages : le mois choisi est mémorisé en
 * session pour rester le même quand on passe d'une page à l'autre, et ne peut
 * jamais dépasser le mois en cours.
 */
trait HasMonthNavigation
{
    #[Url]
    public string $month = '';

    public function mountHasMonthNavigation(): void
    {
        // Hook de trait appelé après mount() : la valeur de l'URL, si présente, est déjà hydratée.
        $this->month = $this->normalizeMonth($this->month ?: session('finance.month', ''));
        session()->put('finance.month', $this->month);
    }

    public function monthDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->month.'-01');
    }

    /** On ne navigue pas au-delà du mois en cours : il n'y a rien à y voir. */
    public function canGoNext(): bool
    {
        return $this->month < now()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->selectMonth($this->monthDate()->subMonth()->format('Y-m'));
    }

    public function nextMonth(): void
    {
        if (! $this->canGoNext()) {
            return;
        }
        $this->selectMonth($this->monthDate()->addMonth()->format('Y-m'));
    }

    public function selectMonth(string $month): void
    {
        $this->month = $this->normalizeMonth($month);
        session()->put('finance.month', $this->month);
        $this->monthChanged();
    }

    /**
     * Les 12 derniers mois (mois courant inclus), du plus récent au plus ancien,
     * pour le menu de sélection rapide.
     *
     * @return array<int, CarbonImmutable>
     */
    public function recentMonths(int $count = 12): array
    {
        $current = CarbonImmutable::now()->startOfMonth();

        return array_map(fn (int $i) => $current->subMonths($i), range(0, $count - 1));
    }

    /** Point d'extension : réinitialiser une pagination, etc. */
    protected function monthChanged(): void {}

    private function normalizeMonth(string $month): string
    {
        $current = now()->format('Y-m');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > $current) {
            return $current;
        }

        return $month;
    }
}
