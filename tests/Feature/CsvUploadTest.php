<?php

namespace Tests\Feature;

use App\Livewire\ImportCsv;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CsvUploadTest extends TestCase
{
    use RefreshDatabase;

    private const CREDIT_MUTUEL = "Date;Date de valeur;Débit;Crédit;Libellé;Solde\r\n15/09/2025;15/09/2025;-6,50;;PAIEMENT PSC 1209 VILLE CAFÉ EXEMPLE CARTE 0000;1000,00\r\n";

    private const TRADE_REPUBLIC = '"datetime","date","account_type","category","type","asset_class","name","symbol","shares","price","amount","fee","tax","currency","original_amount","original_currency","fx_rate","description","transaction_id","counterparty_name","counterparty_iban","payment_reference","mcc_code"'."\n"
        .'"2026-03-10T10:00:00.000000Z","2026-03-10","DEFAULT","CASH","CARD_TRANSACTION","","LIDL 1234","","","","-50.000000","","","EUR","","","","LIDL 1234","id-lidl","","","","5411"'."\n";

    private const N26 = '"Booking Date","Value Date","Partner Name","Partner Iban",Type,"Payment Reference","Account Name","Amount (EUR)","Original Amount","Original Currency","Exchange Rate"'."\n"
        .'"2023-09-07","2023-09-07","LIDL 1234","","Presentment","-","Compte courant","-20","20","EUR","1"'."\n";

    public function test_a_csv_export_is_imported(): void
    {
        $account = Account::create(['name' => 'Compte courant']);
        $file = UploadedFile::fake()->createWithContent('releve.csv', mb_convert_encoding(self::CREDIT_MUTUEL, 'Windows-1252', 'UTF-8'));

        Livewire::actingAs(User::factory()->create())->test(ImportCsv::class)
            ->set('file', $file)
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('result.imported', 1)
            ->assertSee('1 new operation')
            ->assertDontSee('1 new operations');

        $this->assertSame('PAIEMENT PSC 1209 VILLE CAFÉ EXEMPLE CARTE 0000', $account->transactions()->sole()->label);
    }

    public function test_a_file_that_is_not_a_csv_is_refused(): void
    {
        Account::create(['name' => 'Compte courant']);

        Livewire::actingAs(User::factory()->create())->test(ImportCsv::class)
            ->set('file', UploadedFile::fake()->create('releve.pdf', 10, 'application/pdf'))
            ->call('import')
            ->assertHasErrors(['file' => 'mimes'])
            ->assertSet('result', null);
    }

    public function test_the_uploaded_statement_is_deleted_whatever_the_outcome(): void
    {
        Account::create(['name' => 'Compte courant']);
        $page = Livewire::actingAs(User::factory()->create())->test(ImportCsv::class);
        $uploads = [
            'imported' => UploadedFile::fake()->createWithContent('releve.csv', self::CREDIT_MUTUEL),
            'unrecognized format' => UploadedFile::fake()->createWithContent('releve.csv', "foo;bar\n1;2"),
            'refused by validation' => UploadedFile::fake()->create('releve.pdf', 10, 'application/pdf'),
        ];

        foreach ($uploads as $case => $upload) {
            $page->set('file', $upload);
            $path = $page->get('file')->getRealPath();
            $this->assertFileExists($path, $case);

            try {
                $page->call('import');
            } finally {
                $this->assertFileDoesNotExist($path, $case);
            }
            $page->assertSet('file', null);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function filesByContent(): array
    {
        return [
            'Crédit Mutuel en Windows-1252' => ['releve.csv', mb_convert_encoding(self::CREDIT_MUTUEL, 'Windows-1252', 'UTF-8'), true],
            'Crédit Mutuel avec BOM UTF-8' => ['releve.csv', "\u{FEFF}".self::CREDIT_MUTUEL, true],
            'Trade Republic' => ['export.csv', self::TRADE_REPUBLIC, true],
            'N26' => ['n26.csv', self::N26, true],
            'PDF' => ['releve.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n", false],
            'script PHP renommé en .csv' => ['releve.csv', "<?php echo 1;\n", false],
        ];
    }

    /** Le type est déduit du contenu (finfo), comme pour un vrai téléversement, pas du nom. */
    #[DataProvider('filesByContent')]
    public function test_the_type_check_reads_the_content_of_real_exports(string $name, string $content, bool $accepted): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        try {
            $validator = Validator::make(
                ['file' => new UploadedFile($path, $name, null, null, true)],
                ['file' => 'required|file|mimes:csv,txt'],
            );

            $this->assertSame($accepted, $validator->passes());
        } finally {
            unlink($path);
        }
    }
}
