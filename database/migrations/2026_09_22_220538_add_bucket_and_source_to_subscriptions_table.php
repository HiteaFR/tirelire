<?php

use App\Models\Transaction;
use App\Services\MerchantNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Un même marchand (PayPal…) peut porter plusieurs abonnements :
            // l'identité devient (marchand, palier de montant).
            $table->bigInteger('bucket_cents')->default(0)->after('merchant');
            // auto = détecté ; manual = marqué à la main depuis une opération (jamais purgé)
            $table->string('source')->default('auto')->after('status');
            $table->dropUnique(['merchant']);
            $table->unique(['merchant', 'bucket_cents']);
        });

        DB::table('subscriptions')->update(['bucket_cents' => DB::raw('avg_amount_cents')]);

        // Les clés marchand ont changé (PayPal regroupé) : on les recalcule.
        $normalizer = new MerchantNormalizer;
        foreach (Transaction::query()->cursor() as $transaction) {
            $merchant = $normalizer->normalize($transaction->label);
            if ($merchant !== $transaction->merchant) {
                DB::table('transactions')->where('id', $transaction->id)->update(['merchant' => $merchant]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['merchant', 'bucket_cents']);
            $table->dropColumn(['bucket_cents', 'source']);
            $table->unique('merchant');
        });
    }
};
