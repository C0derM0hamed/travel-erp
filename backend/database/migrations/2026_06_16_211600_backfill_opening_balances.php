<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (\Illuminate\Support\Facades\Schema::getConnection()->getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE chart_of_accounts MODIFY COLUMN type ENUM('asset', 'liability', 'revenue', 'expense', 'equity') NOT NULL");
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE journal_entries MODIFY COLUMN source_type ENUM('operation', 'voucher', 'transfer', 'opening_safe', 'opening_client', 'opening_vendor') NOT NULL");
        }

        $offices = \App\Models\Office::withoutGlobalScopes()->get();
        foreach ($offices as $office) {
            \App\Models\ChartOfAccount::withoutGlobalScopes()->firstOrCreate(
                ['office_id' => $office->id, 'code' => '3100'],
                ['name' => 'أرصدة افتتاحية', 'type' => 'equity', 'safe_id' => null]
            );
        }

        $safes = \App\Models\Safe::withoutGlobalScopes()->where('opening_balance', '>', 0)->get();
        $accounting = app(\App\Services\AccountingService::class);
        foreach ($safes as $safe) {
            $accounting->syncOpeningBalance('safe', $safe->id, (float) $safe->opening_balance, 'debit', $safe->currency, $safe->currency_id, $safe->office_id);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \App\Models\JournalEntry::where('source_type', 'opening_safe')->delete();
    }
};
