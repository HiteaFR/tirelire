<?php

namespace App\Support;

/**
 * Mise en forme des montants (centimes) selon config/finance.php : symbole
 * de la devise et format des nombres. Affichage seulement.
 */
class Money
{
    /**
     * "1 234,56 €" pour 123456 avec le format fr ; signe « + » en option pour un
     * montant positif ; sans symbole, "1 234,56".
     */
    public static function format(int $cents, bool $sign = false, int $decimals = 2, bool $symbol = true): string
    {
        $prefix = match (true) {
            $cents < 0 => '-',
            $sign && $cents > 0 => '+',
            default => '',
        };

        $number = self::number($cents, $decimals);

        return $prefix.($symbol ? self::withSymbol($number) : $number);
    }

    /** Nombre seul, sans symbole ni signe, avec les séparateurs du format : 123456 → "1 234,56" en fr. */
    public static function number(int $cents, int $decimals = 2): string
    {
        $format = self::numberFormat();

        return number_format(abs($cents) / 100, $decimals, $format['decimal'], $format['thousands']);
    }

    /** Montant masqué du mode privé, au même format : "**** €", ou "****" sans symbole. */
    public static function masked(bool $symbol = true): string
    {
        return $symbol ? self::withSymbol('****') : '****';
    }

    /** Séparateur décimal du format configuré, pour préremplir un champ de saisie. */
    public static function decimalSeparator(): string
    {
        return self::numberFormat()['decimal'];
    }

    private static function withSymbol(string $amount): string
    {
        return strtr(self::numberFormat()['pattern'], [':amount' => $amount, ':symbol' => config('finance.currency_symbol')]);
    }

    /** @return array{decimal: string, thousands: string, pattern: string} */
    private static function numberFormat(): array
    {
        $formats = config('finance.money_formats');

        return $formats[config('finance.money_format') ?: app()->getLocale()] ?? $formats['en'];
    }
}
