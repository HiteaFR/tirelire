<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Date à laquelle l'utilisateur a marqué l'abonnement résilié : un
            // prélèvement postérieur le réactive.
            $table->date('cancelled_at')->nullable()->after('status');
            // Date du premier prélèvement vu après résiliation, tant que
            // l'utilisateur n'a pas dit s'il s'agit d'une reprise ou d'une erreur.
            $table->date('resumed_on')->nullable()->after('cancelled_at');
        });

        // Résiliations déjà enregistrées : la date réelle est inconnue, on
        // retient le dernier prélèvement connu.
        DB::table('subscriptions')->where('status', 'cancelled')->update(['cancelled_at' => DB::raw('last_date')]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'resumed_on']);
        });
    }
};
