<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_date' => 'date',
        'cancelled_at' => 'date',
        'resumed_on' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Coût mensuel équivalent (les annuels sont lissés sur 12 mois). */
    public function monthlyCostCents(): int
    {
        return $this->period === 'annual'
            ? intdiv($this->avg_amount_cents, 12)
            : $this->avg_amount_cents;
    }

    /**
     * Change le statut posé par l'utilisateur. « Résilié » retient la date du
     * jour : un prélèvement postérieur réactivera l'abonnement.
     */
    public function changeStatus(string $status): void
    {
        $this->update([
            'status' => $status,
            'cancelled_at' => $status === 'cancelled' ? now()->startOfDay() : null,
            'resumed_on' => null,
        ]);
    }

    public function isManual(): bool
    {
        return $this->source === 'manual';
    }

    /** Libellé lisible d'une clé marchand : "ORANGE SA" → "Orange Sa". */
    public static function prettyLabel(string $merchant): string
    {
        return mb_convert_case(mb_strtolower($merchant), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Deux montants appartiennent au même palier s'ils diffèrent d'au plus
     * 2 € ou 25 % : un abonnement dont le prix bouge un peu reste le même.
     */
    public static function sameBucket(int $a, int $b): bool
    {
        $a = abs($a);
        $b = abs($b);

        return abs($a - $b) <= 200 || (min($a, $b) > 0 && abs($a - $b) / min($a, $b) <= 0.25);
    }

    /** L'opération relève-t-elle de cet abonnement (même marchand, même palier) ? */
    public function matches(Transaction $transaction): bool
    {
        return $transaction->amount_cents < 0
            && $transaction->merchant === $this->merchant
            && self::sameBucket($transaction->amount_cents, $this->bucket_cents);
    }
}
