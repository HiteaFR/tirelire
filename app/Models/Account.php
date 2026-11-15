<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $guarded = [];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Date de la dernière opération connue (null si le compte est vide). */
    public function lastActivityDate(): ?CarbonImmutable
    {
        $date = $this->transactions()->max('date');

        return $date ? CarbonImmutable::parse($date) : null;
    }

    /** Solde courant = solde bancaire le plus récent connu, sinon somme des opérations. */
    public function currentBalanceCents(): int
    {
        $last = $this->transactions()
            ->whereNotNull('balance_cents')
            ->orderByDesc('date')->orderByDesc('id')
            ->first();

        if ($last) {
            // opérations manuelles postérieures au dernier solde bancaire connu
            $delta = (int) $this->transactions()
                ->where('source', 'manual')
                ->where('date', '>=', $last->date)
                ->sum('amount_cents');

            return $last->balance_cents + $delta;
        }

        return (int) $this->transactions()->sum('amount_cents');
    }

    /**
     * Solde en fin de journée $date : solde courant moins les opérations
     * postérieures. Repartir du solde courant garde le même ancrage (solde
     * bancaire) que le patrimoine du mois en cours.
     */
    public function balanceAtCents(CarbonImmutable $date): int
    {
        $after = (int) $this->transactions()
            ->whereDate('date', '>', $date)
            ->sum('amount_cents');

        return $this->currentBalanceCents() - $after;
    }
}
