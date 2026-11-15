<?php

namespace Tests\Feature;

use App\Livewire\Categories;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoriesPageTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name, string $kind = 'expense'): Category
    {
        return Category::create(['name' => $name, 'kind' => $kind]);
    }

    private function transaction(string $label, ?Category $category = null): Transaction
    {
        return Transaction::create([
            'account_id' => Account::firstOrCreate(['name' => 'Compte courant'])->id,
            'category_id' => $category?->id,
            'date' => '2026-09-10',
            'amount_cents' => -1000,
            'label' => $label,
            'source' => 'csv',
        ]);
    }

    private function page(): Testable
    {
        return Livewire::actingAs(User::factory()->create())->test(Categories::class);
    }

    public function test_the_page_requires_authentication_and_lists_categories_with_their_counts(): void
    {
        $this->get('/categories')->assertRedirect('/login');

        $courses = $this->category('Courses');
        $courses->rules()->create(['pattern' => 'LIDL', 'priority' => 20]);
        $this->transaction('LIDL 1234', $courses);
        $this->transaction('LIDL 1234', $courses);

        $this->actingAs(User::factory()->create())->get('/categories')
            ->assertOk()
            ->assertSee('Courses')
            ->assertSee('2 operations')
            ->assertSee('1 rule');
    }

    public function test_the_accounts_page_links_to_the_categories_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/comptes')->assertSee(route('categories'));
    }

    public function test_a_category_is_created_with_a_unique_name(): void
    {
        $this->category('Courses');

        $this->page()
            ->set('newName', 'Animaux')->set('newIcon', '🐶')->set('newKind', 'expense')->set('newFixed', true)
            ->call('createCategory')
            ->assertHasNoErrors()
            ->set('newName', 'Courses')
            ->call('createCategory')
            ->assertHasErrors(['newName' => 'unique'])
            ->set('newName', 'Autre')->set('newKind', 'loisir')
            ->call('createCategory')
            ->assertHasErrors(['newKind' => 'in']);

        $created = Category::where('name', 'Animaux')->sole();
        $this->assertSame(['🐶', 'expense', true], [$created->icon, $created->kind, $created->fixed]);
        $this->assertSame(2, Category::count());
    }

    public function test_a_name_followed_by_a_space_cannot_duplicate_an_existing_category(): void
    {
        $this->category('Courses');
        $logement = $this->category('Logement');

        $this->page()
            ->set('newName', 'Courses ')
            ->call('createCategory')
            ->assertHasErrors(['newName' => 'unique'])
            ->call('open', $logement->id)
            ->set('name', ' Courses')
            ->call('updateCategory')
            ->assertHasErrors(['name' => 'unique']);

        $this->assertSame(1, Category::where('name', 'Courses')->count());
        $this->assertSame('Logement', $logement->fresh()->name);
    }

    public function test_a_category_is_renamed_and_its_type_changed(): void
    {
        $category = $this->category('Courses');
        $this->category('Logement');

        $this->page()
            ->call('open', $category->id)
            ->assertSet('name', 'Courses')
            ->set('name', 'Logement')
            ->call('updateCategory')
            ->assertHasErrors(['name' => 'unique'])
            ->set('name', 'Alimentation')->set('kind', 'transfer')->set('fixed', true)
            ->call('updateCategory')
            ->assertHasNoErrors();

        $category->refresh();
        $this->assertSame(['Alimentation', 'transfer', true], [$category->name, $category->kind, $category->fixed]);
    }

    public function test_deleting_a_category_moves_its_operations_and_subscriptions_to_the_chosen_category(): void
    {
        $old = $this->category('Courses');
        $new = $this->category('Alimentation');
        $old->rules()->create(['pattern' => 'LIDL', 'priority' => 20]);
        $transaction = $this->transaction('LIDL 1234', $old);
        $subscription = Subscription::create([
            'merchant' => 'LIDL', 'label' => 'Lidl', 'category_id' => $old->id,
            'avg_amount_cents' => 1000, 'last_date' => '2026-09-10',
        ]);

        $this->page()
            ->call('open', $old->id)
            ->set('moveTo', (string) $new->id)
            ->call('deleteCategory')
            ->assertHasNoErrors()
            ->assertSet('openId', null);

        $this->assertModelMissing($old);
        $this->assertSame($new->id, $transaction->fresh()->category_id);
        $this->assertSame($new->id, $subscription->fresh()->category_id);
        $this->assertSame(0, CategoryRule::count());
    }

    public function test_deleting_a_category_can_leave_its_operations_uncategorized(): void
    {
        $category = $this->category('Courses');
        $transaction = $this->transaction('LIDL 1234', $category);

        $this->page()->call('open', $category->id)->call('deleteCategory')->assertHasNoErrors();

        $this->assertModelMissing($category);
        $this->assertModelExists($transaction);
        $this->assertNull($transaction->fresh()->category_id);
    }

    public function test_deleting_a_category_is_refused_while_it_has_a_budget_or_into_itself(): void
    {
        $category = $this->category('Courses');
        $transaction = $this->transaction('LIDL 1234', $category);
        Budget::create(['category_id' => $category->id, 'amount_cents' => 30000]);

        $this->page()
            ->call('open', $category->id)
            ->call('deleteCategory')
            ->assertHasErrors('moveTo')
            ->set('moveTo', (string) $category->id)
            ->call('deleteCategory')
            ->assertHasErrors(['moveTo' => 'not_in']);

        $this->assertModelExists($category);
        $this->assertSame($category->id, $transaction->fresh()->category_id);
    }

    public function test_rules_are_added_edited_and_deleted(): void
    {
        $category = $this->category('Logement');

        $page = $this->page()
            ->call('open', $category->id)
            ->set('pattern', 'edf ')->set('priority', 10)
            ->call('addRule')
            ->assertHasNoErrors();

        $rule = CategoryRule::sole();
        $this->assertSame(['EDF ', 10, $category->id], [$rule->pattern, $rule->priority, $rule->category_id]); // espace finale gardée

        $page->call('editRule', $rule->id)
            ->set('editPattern', 'engie')->set('editPriority', 15)
            ->call('updateRule')
            ->assertHasNoErrors()
            ->assertSet('editingRuleId', null);
        $this->assertSame(['ENGIE', 15], [$rule->fresh()->pattern, $rule->fresh()->priority]);

        $page->call('deleteRule', $rule->id);
        $this->assertModelMissing($rule);
    }

    /** @return array<string, array{0: string, 1: int|string, 2: string}> */
    public static function invalidRules(): array
    {
        return [
            'motif vide' => ['   ', 10, 'pattern'],
            'motif trop long' => [str_repeat('A', 101), 10, 'pattern'],
            'motif en double, casse comprise' => ['netflix', 10, 'pattern'],
            'priorité nulle' => ['SPOTIFY', 0, 'priority'],
            'priorité trop grande' => ['SPOTIFY', 1001, 'priority'],
            'priorité non entière' => ['SPOTIFY', 'haute', 'priority'],
        ];
    }

    #[DataProvider('invalidRules')]
    public function test_an_invalid_rule_is_refused(string $pattern, int|string $priority, string $field): void
    {
        $category = $this->category('Abonnements');
        $category->rules()->create(['pattern' => 'NETFLIX', 'priority' => 10]);

        $this->page()
            ->call('open', $category->id)
            ->set('pattern', $pattern)->set('priority', $priority)
            ->call('addRule')
            ->assertHasErrors($field);

        $this->assertSame(1, CategoryRule::count());
    }

    public function test_a_pattern_stored_in_lower_case_still_counts_as_a_duplicate(): void
    {
        $category = $this->category('Courses');
        $rule = $category->rules()->create(['pattern' => 'Marché', 'priority' => 20]); // casse d'un ancien JSON privé
        $other = $category->rules()->create(['pattern' => 'LIDL', 'priority' => 20]);

        $this->page()
            ->call('open', $category->id)
            ->set('pattern', 'marché')
            ->call('addRule')
            ->assertHasErrors('pattern')
            ->assertSee('The pattern has already been taken.')
            ->call('editRule', $other->id)
            ->set('editPattern', 'MARCHÉ')
            ->call('updateRule')
            ->assertHasErrors('editPattern')
            ->call('editRule', $rule->id)
            ->set('editPriority', 30)
            ->call('updateRule')
            ->assertHasNoErrors();

        $this->assertSame(2, CategoryRule::count());
        $this->assertSame(30, $rule->fresh()->priority);
    }

    public function test_the_same_pattern_is_allowed_in_another_category(): void
    {
        $this->category('Abonnements')->rules()->create(['pattern' => 'AMAZON', 'priority' => 10]);
        $shopping = $this->category('Shopping');

        $this->page()->call('open', $shopping->id)->set('pattern', 'AMAZON')->call('addRule')->assertHasNoErrors();

        $this->assertSame(2, CategoryRule::where('pattern', 'AMAZON')->count());
    }

    public function test_the_pattern_preview_counts_matching_operations_like_the_categorizer(): void
    {
        $category = $this->category('Logement');
        $this->transaction('Prélèvement EDF');
        $this->transaction('PRLV SEPA EDF CLIENTS');
        $this->transaction('PAIEMENT CB MEDFORD');

        $this->page()
            ->call('open', $category->id)
            ->set('pattern', 'edf ')
            ->assertSee('Matches 2 existing operations.')
            ->set('pattern', 'EDF')
            ->assertSee('Matches 3 existing operations.');
    }

    public function test_recategorizing_keeps_existing_categories_unless_asked_to_replace_them(): void
    {
        $courses = $this->category('Courses');
        $courses->rules()->create(['pattern' => 'LIDL', 'priority' => 20]);
        $chosenByHand = $this->category('Cadeaux');
        $uncategorized = $this->transaction('LIDL 1234');
        $handPicked = $this->transaction('LIDL 1234', $chosenByHand);

        $page = $this->page()->call('recategorize')->assertSet('recategorized', 1)->assertSee('1 operation recategorized.');

        $this->assertSame($courses->id, $uncategorized->fresh()->category_id);
        $this->assertSame($chosenByHand->id, $handPicked->fresh()->category_id);

        $page->set('recategorizeAll', true)->call('recategorize')->assertSet('recategorized', 1);

        $this->assertSame($courses->id, $handPicked->fresh()->category_id);
    }

    public function test_the_page_is_translated_in_french(): void
    {
        app()->setLocale('fr');
        $this->category('Courses');

        $this->actingAs(User::factory()->create())->get('/categories')
            ->assertSee('Catégories &amp; règles', escape: false)
            ->assertSee('Appliquer les règles aux opérations existantes')
            ->assertSee('0 opération');
    }
}
