<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Categorizer;
use App\Services\MerchantNormalizer;
use App\Services\SubscriptionDetector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Données de démonstration, toutes fictives : deux comptes, cinq mois
 * d'historique plus le mois en cours, des abonnements détectables et des
 * budgets. À lancer à part sur une base sans opération (sinon, erreur) :
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Les montants sont tirés d'un générateur à graine fixe : deux exécutions
 * le même jour donnent les mêmes données (utile pour les captures).
 */
class DemoSeeder extends Seeder
{
    private const OPENING_BALANCE_CENTS = 165000;

    /** @var array<int, array{account: Account, date: CarbonImmutable, cents: int, label: string, key: ?string}> */
    private array $operations = [];

    public function run(Categorizer $categorizer, MerchantNormalizer $normalizer, SubscriptionDetector $detector): void
    {
        // Exception, pas un simple message : db:seed sort en erreur et un script
        // d'installation qui enchaîne les commandes s'arrête.
        if (Transaction::query()->exists()) {
            throw new RuntimeException('The database already contains operations: the demo data is only seeded into an empty database.');
        }

        $this->call(CategorySeeder::class);
        mt_srand(2026);

        $main = Account::create(['name' => __('Main account'), 'bank' => 'Demo Bank']);
        $savings = Account::create(['name' => __('Savings account'), 'bank' => 'Neo Bank']);

        $today = CarbonImmutable::today();
        for ($day = $today->startOfMonth()->subMonths(5); $day->lte($today); $day = $day->addDay()) {
            $this->scheduleDay($day, $main, $savings, $day->isSameDay($today));
        }

        $this->store($categorizer, $normalizer, $main);

        $detector->detect();
        Subscription::where('merchant', 'like', 'SPOTIFY%')->get()->each->changeStatus('to_cancel');

        foreach (['groceries' => 45000, 'restaurants' => 12000, 'shopping' => 10000, 'leisure' => 5000] as $key => $cents) {
            Budget::create(['category_id' => Category::where('key', $key)->value('id'), 'amount_cents' => $cents]);
        }
    }

    /** Opérations d'une journée : échéances fixes à date, dépenses courantes au hasard. */
    private function scheduleDay(CarbonImmutable $day, Account $main, Account $savings, bool $isToday): void
    {
        $card = fn (string $merchant): string => 'PAIEMENT CB '.$day->format('dm').' '.$merchant;

        match ($day->day) {
            1 => $this->add($main, $day, -82000, 'PRLV SEPA LOYER RESIDENCE DES LILAS'),
            5 => $this->add($main, $day, -$this->between(5800, 7900), 'PRLV SEPA EDF CLIENTS PARTICULIERS'),
            8 => $this->add($main, $day, -1999, 'PRLV SEPA FREE MOBILE'),
            12 => $this->add($main, $day, -1349, $card('NETFLIX.COM')),
            15 => $this->add($main, $day, -1199, $card('SPOTIFY')),
            27 => $this->add($main, $day, 245000, 'VIR SEPA ACME CORP SALAIRE'),
            default => null,
        };

        if ($day->day === 20) {
            $this->add($main, $day, -20000, 'VIR SEPA VERS LIVRET A', 'savings');
            $this->add($savings, $day, 20000, 'VIR SEPA DEPUIS COMPTE PRINCIPAL', 'internal_transfers');
        }

        if ($isToday || $this->chance(30)) {
            $this->add($main, $day, -$this->between(120, 950), $card('BOULANGERIE DU MARCHE'));
        }
        if ($this->chance(25)) {
            $this->add($main, $day, -$this->between(1200, 9500), $card(['LIDL', 'CARREFOUR MARKET', 'MONOPRIX', 'GRAND FRAIS'][mt_rand(0, 3)]));
        }
        if ($this->chance(8)) {
            $this->add($main, $day, -$this->between(1500, 3800), $card($this->chance(50) ? 'PIZZA NAPOLI' : 'UBER EATS'));
        }
        if ($this->chance(6)) {
            $this->add($main, $day, -$this->between(4500, 7000), $card('TOTAL ENERGIES STATION'));
        }
        if ($this->chance(4)) {
            $this->add($main, $day, -$this->between(1500, 8000), $card($this->chance(70) ? 'AMAZON PAYMENTS' : 'DECATHLON'));
        }
        if ($this->chance(3)) {
            $this->add($main, $day, -$this->between(800, 2500), $card('PHARMACIE DU CENTRE'));
        }
        if ($this->chance(3)) {
            $this->add($main, $day, -2200, $card('CINEMA PATHE'));
        }
        if ($day->day === 10 && $this->chance(50)) {
            $this->add($main, $day, $this->between(900, 2500), 'VIR CPAM REMBOURSEMENT SOINS');
        }
        if ($this->chance(4)) {
            // lieu variable : des retraits ne doivent pas passer pour un abonnement
            $this->add($main, $day, -[2000, 4000, 6000][mt_rand(0, 2)], 'RETRAIT DAB '.$day->format('dm').' '.['GARE CENTRALE', 'CENTRE VILLE', 'MARCHE COUVERT'][mt_rand(0, 2)]);
        }
    }

    private function add(Account $account, CarbonImmutable $date, int $cents, string $label, ?string $categoryKey = null): void
    {
        $this->operations[] = ['account' => $account, 'date' => $date, 'cents' => $cents, 'label' => $label, 'key' => $categoryKey];
    }

    /**
     * Enregistre les opérations, catégorisées par les règles livrées (ou par
     * clé pour les virements internes). Le compte principal porte un solde
     * bancaire, comme un relevé importé.
     */
    private function store(Categorizer $categorizer, MerchantNormalizer $normalizer, Account $main): void
    {
        $balance = self::OPENING_BALANCE_CENTS;
        $categoryIds = Category::whereNotNull('key')->pluck('id', 'key');

        foreach ($this->operations as $operation) {
            $isMain = $operation['account']->is($main);
            if ($isMain) {
                $balance += $operation['cents'];
            }

            Transaction::create([
                'account_id' => $operation['account']->id,
                'category_id' => $operation['key'] !== null ? $categoryIds->get($operation['key']) : $categorizer->categoryIdFor($operation['label']),
                'date' => $operation['date'],
                'amount_cents' => $operation['cents'],
                'label' => $operation['label'],
                'merchant' => $normalizer->normalize($operation['label']),
                'balance_cents' => $isMain ? $balance : null,
                'source' => 'csv',
            ]);
        }
    }

    private function between(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }
}
