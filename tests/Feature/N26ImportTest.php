<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Services\BankCsvParser;
use App\Services\N26CsvParser;
use App\Services\TransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Seeders\TestCategorySeeder;
use Tests\TestCase;

class N26ImportTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = <<<'CSV'
"Booking Date","Value Date","Partner Name","Partner Iban",Type,"Payment Reference","Account Name","Amount (EUR)","Original Amount","Original Currency","Exchange Rate"
"2023-08-28","","","","Credit Transfer","Vous avez bien alimenté votre compte en utilisant votre carte terminant par *0000.","Compte courant","20.00","","",""
"2023-09-07","2023-09-07","LIDL 1234","","Presentment","-","Compte courant","-20","20","EUR","1"
"2023-09-07","2023-09-07","LIDL 1234","","Presentment","-","Compte courant","-20","20","EUR","1"
"2023-10-29","2023-10-29","N26","","Fee","Adhésion N26 Smart","Compte courant","-4.9","","",""
"2025-12-06","2025-12-06","Lea EXEMPLE","FR7600004000000000000000000","Debit Transfer","Cadeau","Compte courant","-15.00","","",""
"2025-12-20","2025-12-20","M JEAN DUPONT","FR7600001000000000000000000","Credit Transfer","VIR DE M JEAN DUPONT","Compte courant","200.00","","",""
"2026-01-08","2026-01-08","Bouygues Telecom","FR7600005000000000000000000","Direct Debit","01xxxxx000","Compte courant","-24.99","","",""
"2026-03-14","2026-03-14","Jean Dupont","","Credit Transfer","Envoye depuis Livret Exemple","Compte courant","100.00","","",""
CSV;

    public function test_parser_recognizes_the_export_and_builds_labels(): void
    {
        $this->assertTrue((new N26CsvParser)->supports('"Booking Date","Value Date","Partner Name","Partner Iban",Type'));

        $rows = (new BankCsvParser)->parse(self::CSV);

        $this->assertCount(8, $rows);
        $this->assertSame('Alimentation du compte par carte', $rows[0]['label']);
        $this->assertNull($rows[0]['value_date']);
        $this->assertSame(2000, $rows[0]['amount_cents']);
        $this->assertSame('LIDL 1234', $rows[1]['label']);
        $this->assertSame(-2000, $rows[1]['amount_cents']);
        $this->assertSame('2023-09-07', $rows[1]['value_date']->format('Y-m-d'));
        $this->assertSame('Frais N26 : Adhésion N26 Smart', $rows[3]['label']);
        $this->assertSame('Virement émis vers Lea EXEMPLE – Cadeau', $rows[4]['label']);
        $this->assertSame('Virement reçu de M JEAN DUPONT', $rows[5]['label']);
        $this->assertSame('Prélèvement Bouygues Telecom 01xxxxx000', $rows[6]['label']);
        $this->assertSame('Virement reçu de Jean Dupont – Envoye depuis Livret Exemple', $rows[7]['label']);
        $this->assertNull($rows[1]['balance_cents']);
    }

    public function test_import_categorizes_and_keeps_same_day_duplicates_without_transaction_id(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'N26']);
        $importer = app(TransactionImporter::class);

        $this->assertSame(8, $importer->import($account, self::CSV)['imported']);

        $categories = $account->transactions()->get()->mapWithKeys(fn ($t) => [$t->label => $t->category?->name]);
        $this->assertSame('Courses', $categories['LIDL 1234']);
        $this->assertSame('Frais bancaires', $categories['Frais N26 : Adhésion N26 Smart']);
        $this->assertSame('Virements perso', $categories['Virement émis vers Lea EXEMPLE – Cadeau']);
        $this->assertSame('Virements internes', $categories['Virement reçu de M JEAN DUPONT']);
        $this->assertSame('Abonnements & télécom', $categories['Prélèvement Bouygues Telecom 01xxxxx000']);
        $this->assertSame('Épargne & crypto', $categories['Virement reçu de Jean Dupont – Envoye depuis Livret Exemple']);
        $this->assertSame('Autres revenus', $categories['Alimentation du compte par carte']);

        $second = $importer->import($account, self::CSV);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(8, $second['skipped']);
    }
}
