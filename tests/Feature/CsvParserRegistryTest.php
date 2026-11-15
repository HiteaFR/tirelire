<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BankCsvParser;
use App\Services\N26CsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class CsvParserRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const CREDIT_MUTUEL_CSV = "Date;Date de valeur;Débit;Crédit;Libellé;Solde\n15/09/2025;15/09/2025;-8,00;;CAFE EXEMPLE;100,00";

    public function test_the_import_page_lists_the_configured_formats(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/import')
            ->assertSeeInOrder(['Crédit Mutuel', 'Trade Republic', 'N26']);

        config(['finance.parsers' => [N26CsvParser::class]]);

        $this->actingAs($user)->get('/import')
            ->assertSee('N26')
            ->assertSee('web app → Transactions → Download')
            ->assertDontSee('Crédit Mutuel');
    }

    public function test_only_configured_parsers_are_tried_and_named_in_the_error(): void
    {
        $this->assertCount(1, app(BankCsvParser::class)->parse(self::CREDIT_MUTUEL_CSV));

        config(['finance.parsers' => [N26CsvParser::class]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('accepted formats: N26)');

        app(BankCsvParser::class)->parse(self::CREDIT_MUTUEL_CSV);
    }

    public function test_a_configured_class_that_is_not_a_parser_is_rejected(): void
    {
        config(['finance.parsers' => [stdClass::class]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement');

        app(BankCsvParser::class)->parsers();
    }
}
