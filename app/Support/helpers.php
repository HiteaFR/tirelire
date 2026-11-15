<?php

use App\Support\Money;
use App\Support\PrivateMode;

if (! function_exists('money')) {
    /**
     * Formate un montant en centimes pour l'affichage, selon la devise et le
     * format de config/finance.php : 123456 → "1 234,56 €" en fr.
     * En mode privé, le montant est remplacé par des astérisques : ne pas
     * utiliser cette fonction pour un export ou un calcul.
     */
    function money(int $cents, bool $sign = false, int $decimals = 2, bool $symbol = true): string
    {
        if (app(PrivateMode::class)->isEnabled()) {
            return Money::masked($symbol);
        }

        return Money::format($cents, $sign, $decimals, $symbol);
    }
}
