<?php

use App\Services\CreditMutuelCsvParser;
use App\Services\N26CsvParser;
use App\Services\TradeRepublicCsvParser;

return [

    /*
    |--------------------------------------------------------------------------
    | Règles de catégorisation privées
    |--------------------------------------------------------------------------
    |
    | Fichier JSON optionnel de règles propres à l'instance (employeur,
    | commerces locaux, noms…), chargé par CategorySeeder en plus des règles
    | génériques. Chemin absolu ou relatif à la racine du projet ; vide = aucun.
    | Format décrit dans Database\Seeders\CategorySeeder.
    |
    */

    'category_rules' => env('FINANCE_CATEGORY_RULES'),

    /*
    |--------------------------------------------------------------------------
    | Affichage des montants
    |--------------------------------------------------------------------------
    |
    | Symbole de la devise et format des nombres (un des formats ci-dessous).
    | Sans FINANCE_MONEY_FORMAT, le format suit la locale de l'app (fr, de ou
    | en ; en pour toute autre locale).
    | L'app ne gère qu'une devise : ce réglage ne change que l'affichage.
    | Dans pattern, :amount est le montant sans signe et :symbol le symbole.
    |
    */

    'currency_symbol' => env('FINANCE_CURRENCY_SYMBOL', '€'),

    'money_format' => env('FINANCE_MONEY_FORMAT'),

    'money_formats' => [
        'fr' => ['decimal' => ',', 'thousands' => ' ', 'pattern' => ':amount :symbol'],   // 1 234,56 €
        'de' => ['decimal' => ',', 'thousands' => '.', 'pattern' => ':amount :symbol'],   // 1.234,56 €
        'en' => ['decimal' => '.', 'thousands' => ',', 'pattern' => ':symbol:amount'],    // €1,234.56
    ],

    /*
    |--------------------------------------------------------------------------
    | Formats d'import CSV
    |--------------------------------------------------------------------------
    |
    | Parseurs essayés dans l'ordre sur la première ligne du fichier ; le
    | premier qui reconnaît le format l'emporte. Chacun implémente
    | App\Services\CsvParser.
    |
    */

    'parsers' => [
        CreditMutuelCsvParser::class,
        TradeRepublicCsvParser::class,
        N26CsvParser::class,
    ],

];
