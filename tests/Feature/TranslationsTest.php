<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Le français doit rester complet : chaque clé passée à __() ou
 * trans_choice() dans app/, resources/views et database/seeders a sa traduction, et
 * lang/fr.json ne garde pas de clé que plus rien n'utilise.
 */
class TranslationsTest extends TestCase
{
    /** Clés utilisées hors de app/ et resources/views : pagination Livewire. */
    private const VENDOR_KEYS = ['Showing', 'to', 'of', 'results', 'Go to page :page'];

    /** @return array<string, string> */
    private function frenchJson(): array
    {
        return json_decode(file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Premier argument littéral de chaque appel __('…') ou trans_choice('…').
     *
     * @return array<int, string>
     */
    private function usedKeys(): array
    {
        $files = Finder::create()->files()->in([app_path(), resource_path('views'), database_path('seeders')])->name('*.php');
        $keys = [];

        foreach ($files as $file) {
            preg_match_all("/(?:\\b__|\\btrans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $matches);
            foreach ($matches[1] as $key) {
                $keys[] = stripslashes($key);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Noms des catégories livrées, traduits par __($name) dans CategorySeeder.
     *
     * @return array<int, string>
     */
    private function seededCategoryNames(): array
    {
        preg_match_all("/\\['[a-z_]+', '([^']+)', '/", file_get_contents(database_path('seeders/CategorySeeder.php')), $matches);

        return $matches[1];
    }

    private function isGroupKey(string $key): bool
    {
        return preg_match('/^[a-z_]+\.[a-z_.]+$/', $key) === 1;
    }

    public function test_every_translation_key_used_in_the_code_has_a_french_translation(): void
    {
        $json = $this->frenchJson();
        $missing = [];

        foreach ([...$this->usedKeys(), ...$this->seededCategoryNames()] as $key) {
            $translated = $this->isGroupKey($key)
                ? trans()->hasForLocale($key, 'fr')
                : array_key_exists($key, $json);

            if (! $translated) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'Clés sans traduction française');
        $this->assertGreaterThan(100, count($this->usedKeys()));
    }

    public function test_french_json_has_no_unused_keys(): void
    {
        $used = [...$this->usedKeys(), ...$this->seededCategoryNames(), ...self::VENDOR_KEYS];

        $this->assertSame([], array_values(array_diff(array_keys($this->frenchJson()), $used)), 'Clés orphelines dans lang/fr.json');
    }

    public function test_french_translations_keep_the_placeholders_of_their_key(): void
    {
        $mismatches = [];

        foreach ($this->frenchJson() as $key => $translation) {
            preg_match_all('/:[a-z_]+/', $key, $expected);
            preg_match_all('/:[a-z_]+/', $translation, $actual);
            $expected = array_unique($expected[0]);
            $actual = array_unique($actual[0]);
            sort($expected);
            sort($actual);

            if ($expected !== $actual) {
                $mismatches[] = $key;
            }
        }

        $this->assertSame([], $mismatches, 'Paramètres différents entre la clé et sa traduction');
    }
}
