<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('opening_balance_amount', 15, 3)->default(0)->after('notes');
            $table->foreignId('opening_balance_currency_id')->nullable()->constrained('currencies')->nullOnDelete()->after('opening_balance_amount');
            $table->string('opening_balance_type', 20)->nullable()->after('opening_balance_currency_id'); // 'receivable' or 'payable'
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->decimal('opening_balance_amount', 15, 3)->default(0)->after('address');
            $table->foreignId('opening_balance_currency_id')->nullable()->constrained('currencies')->nullOnDelete()->after('opening_balance_amount');
            $table->string('opening_balance_type', 20)->nullable()->after('opening_balance_currency_id'); // 'payable' or 'receivable'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['opening_balance_currency_id']);
            $table->dropColumn(['opening_balance_amount', 'opening_balance_currency_id', 'opening_balance_type']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropForeign(['opening_balance_currency_id']);
            $table->dropColumn(['opening_balance_amount', 'opening_balance_currency_id', 'opening_balance_type']);
        });
    }
};
