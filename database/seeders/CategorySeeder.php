<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

class CategorySeeder extends Seeder
{
    /**
     * Catégories et règles génériques (enseignes et organismes nationaux),
     * puis les règles privées de l'instance si config('finance.category_rules')
     * désigne un fichier JSON.
     *
     * Une règle est une sous-chaîne cherchée dans le libellé brut, sans
     * tenir compte de la casse ; priority : plus petit = testé en premier.
     * fixed : charge fixe pour une dépense, revenu régulier (attendu chaque
     * mois par la prévision) pour un revenu.
     *
     * Idempotent : une catégorie ou une règle déjà présente n'est ni
     * dupliquée ni modifiée, ce qui préserve les réglages faits depuis l'app.
     */
    public function run(): void
    {
        $definitions = [
            // ── Revenus ────────────────────────────────────────────────
            ['salary', 'Salary', '💼', '#16a34a', 'income', true, [
                ['SALAIRE', 20],
            ]],
            ['freelance_income', 'Freelance income', '💻', '#2dd4bf', 'income', true, []],
            ['health_refunds', 'Health refunds', '⚕️', '#22c55e', 'income', false, [
                ['CPAM', 20], ['C P A M', 20],
            ]],
            ['other_income', 'Other income', '💰', '#4ade80', 'income', false, []],

            // ── Charges fixes ──────────────────────────────────────────
            ['housing', 'Housing', '🏠', '#0ea5e9', 'expense', true, [
                ['LOYER', 10], ['EDF ', 10], ['ENGIE', 10], ['VEOLIA', 10],
                ['ASSURANCE HABITATION', 10],
            ]],
            ['loans', 'Loans & instalments', '🏦', '#6366f1', 'expense', true, [
                ['ECHEANCE PRET', 10], ['COFIDIS', 10], ['CETELEM', 10],
                ['3X 4X ONEY', 10], ['FLOA ', 15],
            ]],
            ['child_support', 'Child support & benefits', '👨‍👧', '#8b5cf6', 'expense', true, []],
            ['taxes', 'Taxes', '🏛️', '#a855f7', 'expense', true, [
                ['DGFIP', 10], ['IMPOT', 10],
            ]],
            ['school_children', 'School & children', '🎒', '#d946ef', 'expense', true, [
                ['CANTINE', 20], ['CRECHE', 20],
            ]],
            ['subscriptions', 'Subscriptions & telecom', '📱', '#f43f5e', 'expense', true, [
                ['ORANGE SA', 10], ['SOSH', 10], ['FREE TELECOM', 10], ['FREE MOBILE', 10],
                ['BOUYGUES TELECOM', 10], ['SFR', 15], ['NETFLIX', 10], ['SPOTIFY', 10],
                ['DEEZER', 10], ['DISNEY', 10], ['CANAL+', 10], ['AMAZON PRIME', 10],
            ]],

            // ── Dépenses courantes ─────────────────────────────────────
            ['groceries', 'Groceries', '🛒', '#f59e0b', 'expense', false, [
                ['LIDL', 20], ['ALDI', 20], ['INTERMARCHE', 20], ['LECLERC', 20],
                ['CARREFOUR', 20], ['AUCHAN', 20], ['CASINO', 20], ['MONOPRIX', 20],
                ['FRANPRIX', 20], ['SUPER U', 20], ['HYPER U', 20], ['MAGASIN U', 20],
                ['NETTO ', 20], ['GRAND FRAIS', 20], ['PICARD', 20], ['BIOCOOP', 20],
                ['BOULANGERIE', 20],
            ]],
            ['restaurants', 'Restaurants & delivery', '🍔', '#f97316', 'expense', false, [
                ['MCDONALD', 20], ['MC DONALD', 20], ['MAC DONALD', 20], ['KFC', 20],
                ['BURGER KING', 20], ['DOMINOS', 20], ['UBER EATS', 15], ['DELIVEROO', 20],
                ['RESTAURANT', 25], ['RESTO ', 25], ['BRASSERIE', 25], ['PIZZA', 25],
                ['SUSHI', 25],
            ]],
            ['bars', 'Bars & tobacco', '🚬', '#ef4444', 'expense', false, [
                ['TABAC', 15],
            ]],
            ['transport', 'Transport & car', '⛽', '#eab308', 'expense', false, [
                ['SNCF', 20], ['RATP', 20], ['BLABLACAR', 20], ['UBER', 25],
                ['ESSO ', 20], ['SHELL', 20], ['AVIA ', 20], ['TOTAL', 30],
                ['AUTOROUTE', 20], ['PEAGE', 20], ['PARKING', 20], ['INDIGO', 25],
                ['NORAUTO', 20], ['FEU VERT', 20], ['SPEEDY', 20], ['GARAGE', 20],
            ]],
            ['health', 'Health', '💊', '#14b8a6', 'expense', false, [
                ['PHARM', 20], ['CLINIQUE', 20], ['OPTIC', 20], ['DOCTOLIB', 20],
                ['LABORATOIRE', 20],
            ]],
            ['shopping', 'Shopping', '🛍️', '#ec4899', 'expense', false, [
                ['AMAZON', 25], ['CDISCOUNT', 20], ['FNAC', 20], ['DARTY', 20],
                ['BOULANGER', 25], ['DECATHLON', 20], ['IKEA', 20], ['LEROY MERLIN', 20],
                ['CASTORAMA', 20], ['KIABI', 20], ['GEMO', 20], ['ZEEMAN', 20],
                ['GIFI', 20], ['FOIR FOUILLE', 20], ['PRIMARK', 20],
            ]],
            ['leisure', 'Leisure & culture', '🎬', '#06b6d4', 'expense', false, [
                ['CINEMA', 20], ['PATHE', 20], ['GAUMONT', 20], ['UGC', 20],
                ['CGR', 20], ['CULTURA', 20], ['STEAM', 20], ['NINTENDO', 20],
                ['PLAYSTATION', 20],
            ]],
            ['bank_fees', 'Bank fees', '🧾', '#78716c', 'expense', false, [
                ['AGIOS', 10], ['F COTIS', 10], ['F FRAIS', 10], ['F INT/FRAIS', 10],
                ['F COMM INTERVENTION', 10], ['FRAIS N26', 10],
            ]],
            ['cash_withdrawals', 'Cash withdrawals', '💶', '#a3a3a3', 'expense', false, [
                ['RETRAIT DAB', 10],
            ]],
            ['personal_transfers', 'Personal transfers', '💸', '#94a3b8', 'expense', false, [
                ['WERO', 20], ['LYDIA', 20], ['PAYLIB', 20],
            ]],

            // ── Mouvements internes (exclus des stats) ─────────────────
            ['savings', 'Savings & crypto', '₿', '#f7931a', 'transfer', false, [
                ['LIVRET', 20], ['COINBASE', 20], ['BINANCE', 20],
            ]],
            ['internal_transfers', 'Internal transfers', '🔁', '#cbd5e1', 'transfer', false, []],
        ];

        foreach ($definitions as $position => [$key, $name, $icon, $color, $kind, $fixed, $rules]) {
            $category = $this->category($key, __($name), [
                'icon' => $icon, 'color' => $color, 'kind' => $kind, 'fixed' => $fixed, 'position' => $position,
            ]);

            foreach ($rules as [$pattern, $priority]) {
                $category->rules()->firstOrCreate(['pattern' => $pattern], ['priority' => $priority]);
            }
        }

        $this->seedPrivateRules();
    }

    /**
     * Catégorie livrée retrouvée par sa clé ; à défaut, une catégorie du même
     * nom encore sans clé est adoptée ; sinon elle est créée, nommée dans la
     * langue de l'installation. Une catégorie existante n'est jamais modifiée,
     * hormis l'ajout de sa clé.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function category(string $key, string $name, array $attributes): Category
    {
        $category = Category::where('key', $key)->first()
            ?? Category::whereNull('key')->where('name', $name)->first();

        if ($category === null) {
            return Category::create(['key' => $key, 'name' => $name] + $attributes);
        }

        if ($category->key === null) {
            $category->update(['key' => $key]);
        }

        return $category;
    }

    /**
     * Règles privées : tableau JSON d'objets
     * {"category": "Courses", "pattern": "MON MARCHÉ", "priority": 20}.
     * category est la clé d'une catégorie livrée (« groceries ») ou le nom
     * d'une catégorie existante ; priority, un entier,
     * vaut 100 si absente. Le motif est mis en majuscules, espaces comprises.
     * Un chemin configuré mais introuvable est signalé et ignoré. Une règle
     * déjà en base n'est pas modifiée : changer sa priorité dans le fichier
     * reste sans effet au seed suivant.
     */
    private function seedPrivateRules(): void
    {
        $path = config('finance.category_rules');
        if (blank($path)) {
            return;
        }

        $path = preg_match('#^([A-Za-z]:)?[/\\\\]#', $path) === 1 ? $path : base_path($path);
        if (! File::exists($path)) {
            $this->command?->warn("Règles privées introuvables : {$path}");

            return;
        }

        $rules = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($rules) || ! array_is_list($rules)) {
            throw new RuntimeException("{$path} : un tableau JSON de règles est attendu.");
        }

        $categoryIds = Category::pluck('id', 'name')->union(Category::whereNotNull('key')->pluck('id', 'key'));

        foreach ($rules as $index => $rule) {
            $categoryId = is_array($rule) ? $categoryIds->get($rule['category'] ?? '') : null;
            $pattern = is_array($rule) ? mb_strtoupper((string) ($rule['pattern'] ?? '')) : '';
            $priority = is_array($rule) ? ($rule['priority'] ?? 100) : null;

            if ($categoryId === null || trim($pattern) === '' || ! is_int($priority)) {
                throw new RuntimeException("{$path}, règle n° ".($index + 1).' : catégorie inconnue, motif vide ou priorité non entière.');
            }

            CategoryRule::firstOrCreate(
                ['category_id' => $categoryId, 'pattern' => $pattern],
                ['priority' => $priority],
            );
        }
    }
}
