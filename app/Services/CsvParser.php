<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Parseur d'un format d'export bancaire. Chaque parseur reconnaît son
 * format à partir de la première ligne du fichier et produit des lignes
 * normalisées pour TransactionImporter. Pour qu'un parseur soit essayé,
 * ajoutez sa classe à config('finance.parsers'). category_hint est la clé
 * d'une catégorie livrée par CategorySeeder (« groceries »), utilisée si aucune
 * règle de libellé ne s'applique.
 *
 * @phpstan-type ParsedRow array{
 *   date: CarbonImmutable,
 *   value_date: ?CarbonImmutable,
 *   amount_cents: int,
 *   label: string,
 *   balance_cents: ?int,
 *   external_id?: ?string,
 *   category_hint?: ?string
 * }
 */
interface CsvParser
{
    /** Nom de la banque ou du format, affiché à l'utilisateur : « N26 ». */
    public function name(): string;

    /** Où trouver l'export dans l'app de la banque, ou à quoi il ressemble. */
    public function exportHint(): string;

    public function supports(string $firstLine): bool;

    /**
     * @return array<int, array{date: CarbonImmutable, value_date: ?CarbonImmutable, amount_cents: int, label: string, balance_cents: ?int, external_id?: ?string, category_hint?: ?string}>
     */
    public function parse(string $content): array;
}
