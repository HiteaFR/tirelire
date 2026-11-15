<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\BankCsvParser;
use App\Services\TradeRepublicCsvParser;
use App\Services\TransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Seeders\TestCategorySeeder;
use Tests\TestCase;

class TradeRepublicImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = '"datetime","date","account_type","category","type","asset_class","name","symbol","shares","price","amount","fee","tax","currency","original_amount","original_currency","fx_rate","description","transaction_id","counterparty_name","counterparty_iban","payment_reference","mcc_code"';

    private const CSV = self::HEADER."\n".<<<'CSV'
"2026-03-03T10:00:00.000000Z","2026-03-03","DEFAULT","CASH","TRANSFER_INSTANT_INBOUND","","","","","","200.000000","","","EUR","","","","Incoming transfer from M JEAN DUPONT (FR7600001000000000000000000)","id-transfer-in","","","",""
"2026-03-10T10:00:00.000000Z","2026-03-10","DEFAULT","CASH","CARD_TRANSACTION","","LIDL 1234","","","","-50.000000","","","EUR","","","","LIDL 1234       0000001","id-lidl","","","","5411"
"2026-03-10T10:00:00.000000Z","2026-03-10","DEFAULT","CASH","CARD_TRANSACTION","","SP BOUTIQUE EXEMPLE","","","","-30.000000","","","EUR","","","","SP BOUTIQUE EXEMPLE","id-unknown-mcc","","","","7230"
"2026-03-18T10:00:00.000000Z","2026-03-18","PEA","CASH","PEA_MARKETING","","","","","","1.000000","","","EUR","","","","PEA activation","id-pea","","","",""
"2026-03-18T12:00:00.000Z","2026-03-18","DEFAULT","TRADING","BUY","STOCK","Exemple SA","FR0000000001","1.0000000000","12.5000000000","-12.50","-1.00","-0.05","EUR","","","","Buy trade FR0000000001 EXEMPLE SA, quantity: 1.0","id-buy","","","",""
"2026-05-29T10:00:00.000000Z","2026-05-29","DEFAULT","CASH","DIVIDEND","STOCK","Exemple SA","FR0000000001","1.0000000000","","0.600000","","-0.18","EUR","","","","Cash Dividend for ISIN FR0000000001","id-dividend","","","",""
"2026-07-27T10:00:00.000000Z","2026-07-27","DEFAULT","CASH","TRANSFER_INBOUND","","SASU EXEMPLE","","","","500.000000","","","EUR","","","","Incoming transfer from SASU EXEMPLE (FR7600002000000000000000000)","id-salary","SASU EXEMPLE","FR7600002000000000000000000","",""
"2026-07-31T10:00:00.000000Z","2026-07-31","DEFAULT","CASH","TRANSFER_INSTANT_OUTBOUND","","Dupont Jean","","","","-200.000000","","","EUR","","","","Outgoing transfer for Dupont Jean (FR7600003000000000000000000)","id-transfer-out","Dupont Jean","FR7600003000000000000000000","",""
"2026-08-01T10:00:00.000000Z","2026-08-01","DEFAULT","CASH","INTEREST_PAYMENT","","","","","","0.140000","","-0.04","EUR","","","","Interest payment for payout collection abc","id-interest","","","",""
CSV;

    public function test_dispatcher_recognizes_trade_republic_and_rejects_unknown_formats(): void
    {
        $this->assertTrue((new TradeRepublicCsvParser)->supports(self::HEADER));
        $this->assertCount(8, (new BankCsvParser)->parse(self::CSV));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unrecognized file format');
        (new BankCsvParser)->parse("foo,bar\n1,2");
    }

    public function test_parser_builds_net_cash_amounts_and_readable_labels(): void
    {
        $rows = collect((new TradeRepublicCsvParser)->parse(self::CSV))->keyBy('external_id');

        $buy = $rows['tr:id-buy'];
        $this->assertSame(-1355, $buy['amount_cents']); // 12,50 + 1,00 de frais + 0,05 de taxe
        $this->assertSame('Achat Exemple SA ×1', $buy['label']);
        $this->assertSame('savings', $buy['category_hint']);
        $this->assertSame('2026-03-18', $buy['date']->format('Y-m-d'));
        $this->assertNull($buy['balance_cents']);

        $this->assertSame(42, $rows['tr:id-dividend']['amount_cents']); // 0,60 − 0,18 de taxe
        $this->assertSame('Dividende Exemple SA', $rows['tr:id-dividend']['label']);
        $this->assertSame(10, $rows['tr:id-interest']['amount_cents']);
        $this->assertArrayNotHasKey('tr:id-pea', $rows); // sous-compte PEA : hors compte espèces
        $this->assertSame('LIDL 1234', $rows['tr:id-lidl']['label']);
        $this->assertSame('groceries', $rows['tr:id-lidl']['category_hint']);
        $this->assertSame('Virement reçu de SASU EXEMPLE', $rows['tr:id-salary']['label']);
        $this->assertSame('Virement émis vers Dupont Jean', $rows['tr:id-transfer-out']['label']);
        $this->assertSame('Virement reçu de M JEAN DUPONT', $rows['tr:id-transfer-in']['label']);
    }

    public function test_import_categorizes_by_label_rule_then_mcc_hint_and_is_idempotent_by_transaction_id(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Trade Republic']);
        $importer = app(TransactionImporter::class);

        $this->assertSame(8, $importer->import($account, self::CSV)['imported']);

        $byLabel = $account->transactions()->get()->keyBy('label');
        $this->assertSame('Courses', $byLabel['LIDL 1234']->category->name);           // règle de libellé
        $this->assertSame('Shopping', $byLabel['SP BOUTIQUE EXEMPLE']->category->name);       // indice MCC 7230
        $this->assertSame('Épargne & crypto', $byLabel['Achat Exemple SA ×1']->category->name);
        $this->assertSame('Autres revenus', $byLabel['Virement reçu de SASU EXEMPLE']->category->name);
        $this->assertSame('Virements internes', $byLabel['Virement émis vers Dupont Jean']->category->name);
        $this->assertSame('Virements internes', $byLabel['Virement reçu de M JEAN DUPONT']->category->name);

        // Réimport avec un libellé différent pour la même transaction_id : toujours ignoré
        $again = str_replace('"LIDL 1234"', '"LIDL 1234 BIS"', self::CSV);
        $result = $importer->import($account, $again);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(8, $result['skipped']);
    }

    public function test_import_replaces_matching_manual_entries_instead_of_doubling_them(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Trade Republic']);
        $other = Account::create(['name' => 'Compte courant']);
        $manual = fn (Account $a, string $date, int $cents, ?string $category = null) => Transaction::create([
            'account_id' => $a->id, 'date' => $date, 'amount_cents' => $cents, 'label' => 'Saisie', 'merchant' => 'SAISIE',
            'category_id' => $category ? Category::where('name', $category)->value('id') : null, 'source' => 'manual',
        ]);
        $lidl = $manual($account, '2026-03-11', -5000, 'Restos & livraison'); // banque : 10/03, même montant
        $tooFar = $manual($account, '2026-03-20', -3000);                     // BOUTIQUE le 10/03 : 10 jours d'écart
        $otherAccount = $manual($other, '2026-03-10', -5000);

        $result = app(TransactionImporter::class)->import($account, self::CSV);

        $this->assertSame(['imported' => 7, 'skipped' => 0, 'reconciled' => 1, 'total' => 8], $result);
        $lidl->refresh();
        $this->assertSame(['csv', 'LIDL 1234', '2026-03-10', 'Restos & livraison'], [$lidl->source, $lidl->label, $lidl->date->format('Y-m-d'), $lidl->category->name]);
        $this->assertSame('manual', $tooFar->fresh()->source);
        $this->assertSame('manual', $otherAccount->fresh()->source);
        $this->assertSame(1, $account->transactions()->where('amount_cents', -5000)->count());

        $again = app(TransactionImporter::class)->import($account, self::CSV);
        $this->assertSame(['imported' => 0, 'skipped' => 8, 'reconciled' => 0, 'total' => 8], $again);
    }

    public function test_credit_mutuel_format_still_goes_through_the_dispatcher(): void
    {
        $rows = (new BankCsvParser)->parse("Date;Date de valeur;Débit;Crédit;Libellé;Solde\n15/09/2025;15/09/2025;-8,00;;CAFE EXEMPLE;100,00");

        $this->assertSame(-800, $rows[0]['amount_cents']);
        $this->assertArrayNotHasKey('external_id', $rows[0]);
    }
}
