<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bank')->nullable();
            $table->string('number')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon', 8)->default('📁');
            $table->string('color', 7)->default('#64748b');
            $table->string('kind')->default('expense'); // expense | income | transfer
            $table->boolean('fixed')->default(false);   // charge fixe (loyer, crédit…)
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('category_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('pattern');                  // sous-chaîne cherchée dans le libellé (insensible à la casse)
            $table->unsignedInteger('priority')->default(100); // plus petit = testé en premier
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->date('value_date')->nullable();
            $table->bigInteger('amount_cents');         // signé : négatif = débit
            $table->text('label');
            $table->string('merchant')->nullable();     // libellé normalisé pour regroupements
            $table->bigInteger('balance_cents')->nullable();
            $table->string('source')->default('csv');   // csv | manual
            $table->string('hash', 64)->nullable();     // dédoublonnage import
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'hash']);
            $table->index(['account_id', 'date']);
            $table->index('merchant');
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');         // budget mensuel
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('merchant')->unique();
            $table->string('label');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period')->default('monthly'); // monthly | annual
            $table->bigInteger('avg_amount_cents');
            $table->date('last_date');
            $table->unsignedInteger('occurrences')->default(0);
            $table->string('status')->default('active');  // active | to_cancel | cancelled | ignored
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('category_rules');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('accounts');
    }
};
