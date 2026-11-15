<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Point d'entrée des imports CSV : détecte le format à partir de la
 * première ligne et délègue au premier parseur de config('finance.parsers')
 * qui le reconnaît.
 */
class BankCsvParser
{
    /**
     * @return array<int, array{date: CarbonImmutable, value_date: ?CarbonImmutable, amount_cents: int, label: string, balance_cents: ?int, external_id?: ?string, category_hint?: ?string}>
     */
    public function parse(string $content): array
    {
        $content = self::normalizeText($content);
        $firstLine = trim(strtok($content, "\n") ?: '');

        $parser = $this->resolve($firstLine)
            ?? throw new RuntimeException(__('Unrecognized file format (accepted formats: :formats).', ['formats' => $this->acceptedFormats()]));

        return $parser->parse($content);
    }

    /**
     * Parseurs configurés, dans l'ordre où ils sont essayés.
     *
     * @return array<int, CsvParser>
     */
    public function parsers(): array
    {
        return array_map(function (string $class): CsvParser {
            $parser = app($class);

            return $parser instanceof CsvParser
                ? $parser
                : throw new RuntimeException("{$class} must implement ".CsvParser::class.' (config finance.parsers).');
        }, config('finance.parsers'));
    }

    /** Décode Windows-1252 si besoin, retire le BOM et normalise les fins de ligne. */
    public static function normalizeText(string $content): string
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return str_replace("\r\n", "\n", ltrim($content, "\u{FEFF}"));
    }

    private function resolve(string $firstLine): ?CsvParser
    {
        foreach ($this->parsers() as $parser) {
            if ($parser->supports($firstLine)) {
                return $parser;
            }
        }

        return null;
    }

    private function acceptedFormats(): string
    {
        return implode(', ', array_map(fn (CsvParser $parser): string => $parser->name(), $this->parsers()));
    }
}
