<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Seeders\TestCategorySeeder;
use Tests\TestCase;

class RecategorizeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_rules_to_uncategorized_rows_and_optionally_to_matching_labels(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Compte Pro']);
        $other = Category::where('name', 'Autres revenus')->value('id');
        $manual = Category::where('name', 'Shopping')->value('id');

        $freelance = Transaction::create(['account_id' => $account->id, 'date' => '2026-07-27', 'amount_cents' => 50000, 'label' => 'Virement reçu de CLIENT EXEMPLE', 'category_id' => $other, 'source' => 'csv']);
        $lidl = Transaction::create(['account_id' => $account->id, 'date' => '2026-07-28', 'amount_cents' => -1000, 'label' => 'LIDL 1234', 'category_id' => null, 'source' => 'csv']);
        $handSet = Transaction::create(['account_id' => $account->id, 'date' => '2026-07-29', 'amount_cents' => -1000, 'label' => 'INTERMARCHE', 'category_id' => $manual, 'source' => 'csv']);

        $this->artisan('finance:recategorize')->expectsOutputToContain('1 operation recategorized.');
        $this->assertSame('Courses', $lidl->fresh()->category->name);
        $this->assertSame($other, $freelance->fresh()->category_id);   // déjà catégorisé : intouché
        $this->assertSame($manual, $handSet->fresh()->category_id);

        $this->artisan('finance:recategorize', ['--pattern' => 'CLIENT EXEMPLE', '--all' => true])->expectsOutputToContain('1 operation recategorized.');
        $this->assertSame('Revenus freelance', $freelance->fresh()->category->name);
        $this->assertSame($manual, $handSet->fresh()->category_id);    // hors pattern : intouché
    }
}
