<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Subscription;
use App\Services\BankCsvParser;
use App\Services\MerchantNormalizer;
use App\Services\SubscriptionDetector;
use App\Services\TransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Seeders\TestCategorySeeder;
use Tests\TestCase;

class ImportPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = <<<'CSV'
Date;Date de valeur;Débit;Crédit;Libellé;Solde
15/09/2025;15/09/2025;-6,50;;PAIEMENT PSC 1209 VILLE CAFE EXEMPLE CARTE 0000 UIR000012345678901;1000,00
15/09/2025;15/09/2025;-6,50;;PAIEMENT PSC 1209 VILLE CAFE EXEMPLE CARTE 0000 UIR000012345678901;993,50
02/10/2025;02/10/2025;;2000,00;VIR EMPLOYEUR EXEMPLE SALAIRE-000001-1;2993,50
06/10/2025;06/10/2025;-700,00;;VIR SEPA LOYER AB1C23456D789012;2293,50
CSV;

    public function test_parser_reads_french_formats(): void
    {
        $rows = (new BankCsvParser)->parse(self::CSV);

        $this->assertCount(4, $rows);
        $this->assertSame(-650, $rows[0]['amount_cents']);
        $this->assertSame(200000, $rows[2]['amount_cents']);
        $this->assertSame('2025-09-15', $rows[0]['date']->format('Y-m-d'));
        $this->assertSame(100000, $rows[0]['balance_cents']);
    }

    public function test_parser_handles_windows_1252(): void
    {
        $latin = mb_convert_encoding("Date;Date de valeur;Débit;Crédit;Libellé;Solde\n15/09/2025;15/09/2025;-8,00;;CAFÉ DE LA GARE;-10,00", 'Windows-1252', 'UTF-8');
        $rows = (new BankCsvParser)->parse($latin);

        $this->assertSame('CAFÉ DE LA GARE', $rows[0]['label']);
    }

    public function test_import_is_idempotent_but_keeps_same_day_duplicates(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Test']);
        $importer = app(TransactionImporter::class);

        $first = $importer->import($account, self::CSV);
        // 2 opérations identiques le même jour avec soldes différents = 2 lignes légitimes
        $this->assertSame(4, $first['imported']);

        $second = $importer->import($account, self::CSV);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(4, $second['skipped']);
    }

    public function test_reimport_skips_known_operations_when_the_bank_rewrites_labels_and_balances(): void
    {
        $account = Account::create(['name' => 'Test']);
        $importer = app(TransactionImporter::class);
        $importer->import($account, self::CSV);
        // Même période, nouveau format de libellé, et une opération antidatée
        // (-24,00 le 14/09) qui décale tous les soldes suivants.
        $rewritten = <<<'CSV'
Date;Date de valeur;Débit;Crédit;Libellé;Solde
14/09/2025;14/09/2025;-24,00;;BOULANGERIE EXEMPLE VILLE 13/09 CARTE X000;982,00
15/09/2025;15/09/2025;-6,50;;CAFE EXEMPLE VILLE 12/09 CARTE X000;975,50
15/09/2025;15/09/2025;-6,50;;CAFE EXEMPLE VILLE 12/09 CARTE X000;969,00
02/10/2025;02/10/2025;;2000,00;VIR EMPLOYEUR EXEMPLE SALAIRE-000001-1;2969,00
06/10/2025;06/10/2025;-700,00;;VIR SEPA LOYER AB1C23456D789012;2269,00
CSV;

        $result = $importer->import($account, $rewritten);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(4, $result['skipped']);
        $this->assertSame(5, $account->transactions()->count());
        $this->assertSame(1, $account->transactions()->where('amount_cents', 200000)->count());
        $this->assertSame(1, $account->transactions()->where('amount_cents', -2400)->count());
    }

    public function test_reimport_adds_a_third_identical_operation_on_a_day_that_had_two(): void
    {
        $account = Account::create(['name' => 'Test']);
        $importer = app(TransactionImporter::class);
        $importer->import($account, self::CSV);
        $withThirdCoffee = self::CSV."\n15/09/2025;15/09/2025;-6,50;;CAFE EXEMPLE VILLE 12/09 CARTE X000;987,00";

        $result = $importer->import($account, $withThirdCoffee);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(3, $account->transactions()->where('amount_cents', -650)->count());
    }

    public function test_reimport_newest_first_keeps_a_new_identical_operation_listed_before_the_known_one(): void
    {
        $account = Account::create(['name' => 'Test']);
        $importer = app(TransactionImporter::class);
        $importer->import($account, "Date;Date de valeur;Débit;Crédit;Libellé;Solde\n15/09/2025;15/09/2025;-6,50;;CAFE EXEMPLE;993,50");
        $newestFirst = <<<'CSV'
Date;Date de valeur;Débit;Crédit;Libellé;Solde
15/09/2025;15/09/2025;-6,50;;CAFE EXEMPLE;987,00
15/09/2025;15/09/2025;-6,50;;CAFE EXEMPLE;993,50
CSV;

        $result = $importer->import($account, $newestFirst);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, $account->transactions()->where('amount_cents', -650)->count());
    }

    public function test_categorization_applies_seeded_rules(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Test']);
        app(TransactionImporter::class)->import($account, self::CSV);

        $salary = $account->transactions()->where('amount_cents', '>', 0)->first();
        $this->assertSame('Salaire', $salary->category->name);

        $rent = $account->transactions()->where('amount_cents', -70000)->first();
        $this->assertSame('Logement', $rent->category->name);
    }

    public function test_merchant_normalizer_strips_references(): void
    {
        $n = new MerchantNormalizer;

        $this->assertSame('LOYER', $n->normalize('VIR SEPA LOYER AB1C23456D789012'));
        $this->assertSame('ENGIE', $n->normalize('PRLV SEPA ENGIE MANDAT 00S000000001'));
        $this->assertSame(
            'OPENAI *CHATGPT',
            $n->normalize('PAIEMENT CB 2609 20 00 USD OPENAI *CHATGPT CARTE 0000 UIR000012345678902'),
        );
        // libellés produits par les parseurs Trade Republic / N26
        $this->assertSame('BOUYGUES TELECOM 01XXXXX000', $n->normalize('Prélèvement Bouygues Telecom 01xxxxx000'));
        $this->assertSame('M P MARTIN', $n->normalize('Virement reçu de M P MARTIN – LOC APPARTEMENT'));
        $this->assertSame('ADHÉSION N26 SMART', $n->normalize('Frais N26 : Adhésion N26 Smart'));
        // le nom d'enseigne avec peu de chiffres est conservé
        $this->assertStringContainsString('LIDL1234', $n->normalize('PAIEMENT CB 2310 VILLE LIDL1234 CARTE 0000 UIR000012345678903'));
    }

    public function test_subscription_detector_finds_monthly_recurring(): void
    {
        $account = Account::create(['name' => 'Test']);
        $lines = ['Date;Date de valeur;Débit;Crédit;Libellé;Solde'];
        foreach ([3, 2, 1, 0] as $monthsAgo) {
            $d = now()->subMonths($monthsAgo)->startOfMonth()->addDays(4)->format('d/m/Y');
            $lines[] = "$d;$d;-15,00;;PRLV SEPA ORANGE SA VOTRE ABONNEMENT MOBILE: 07XXXX;-100,00";
        }
        app(TransactionImporter::class)->import($account, implode("\n", $lines));

        app(SubscriptionDetector::class)->detect();

        $sub = Subscription::first();
        $this->assertNotNull($sub);
        $this->assertSame('monthly', $sub->period);
        $this->assertSame(1500, $sub->avg_amount_cents);
        $this->assertSame(4, $sub->occurrences);
    }

    public function test_subscription_detector_survives_one_off_amount(): void
    {
        $account = Account::create(['name' => 'Test']);
        $lines = ['Date;Date de valeur;Débit;Crédit;Libellé;Solde'];
        // un mois hors-forfait ne doit pas casser la détection
        foreach ([[4, '-15,00'], [3, '-25,00'], [2, '-15,00'], [1, '-15,00'], [0, '-15,00']] as [$monthsAgo, $a]) {
            $d = now()->subMonths($monthsAgo)->startOfMonth()->addDays(4)->format('d/m/Y');
            $lines[] = "$d;$d;$a;;PRLV SEPA ORANGE SA;-100,00";
        }
        app(TransactionImporter::class)->import($account, implode("\n", $lines));

        app(SubscriptionDetector::class)->detect();

        $this->assertSame(1, Subscription::count());
        $this->assertSame(1500, Subscription::first()->avg_amount_cents); // le mois atypique n'entre pas dans la moyenne
    }

    public function test_internal_transfers_are_never_flagged_as_subscriptions(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Test']);
        $lines = ['Date;Date de valeur;Débit;Crédit;Libellé;Solde'];
        foreach ([3, 2, 1, 0] as $monthsAgo) {
            $d = now()->subMonths($monthsAgo)->startOfMonth()->addDays(2)->format('d/m/Y');
            $lines[] = "$d;$d;-200,00;;VIR INST JEAN DUPONT AB1C23456D789013;-100,00";
        }
        app(TransactionImporter::class)->import($account, implode("\n", $lines));

        app(SubscriptionDetector::class)->detect();

        $this->assertSame(0, Subscription::count());
    }

    public function test_non_recurring_merchants_are_not_flagged(): void
    {
        $account = Account::create(['name' => 'Test']);
        $lines = ['Date;Date de valeur;Débit;Crédit;Libellé;Solde'];
        foreach ([['05/01/2026', '-12,00'], ['09/01/2026', '-45,50'], ['22/01/2026', '-7,80']] as [$d, $a]) {
            $lines[] = "$d;$d;$a;;PAIEMENT PSC 0501 VILLE RESTO DU COIN CARTE 0000 UIR0123;-100,00";
        }
        app(TransactionImporter::class)->import($account, implode("\n", $lines));

        app(SubscriptionDetector::class)->detect();

        $this->assertSame(0, Subscription::count());
    }
}
