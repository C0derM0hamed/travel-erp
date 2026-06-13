<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->string('ref')->index();
            $table->foreignId('from_safe_id')->constrained('safes')->restrictOnDelete();
            $table->foreignId('to_safe_id')->constrained('safes')->restrictOnDelete();
            $table->decimal('amount', 14, 3);
            $table->string('currency', 3)->default('KWD');
            $table->date('transfer_date')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['office_id', 'ref']);
            $table->index(['office_id', 'transfer_date']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY COLUMN source_type ENUM('operation', 'voucher', 'transfer') NOT NULL");
        }

        if (Schema::hasTable('reference_sequences')) {
            $officeIds = DB::table('offices')->pluck('id');
            foreach ($officeIds as $officeId) {
                DB::table('reference_sequences')->updateOrInsert(
                    ['office_id' => $officeId, 'key' => 'safe_transfer'],
                    ['last_value' => 0]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_transfers');

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journal_entries MODIFY COLUMN source_type ENUM('operation', 'voucher') NOT NULL");
        }
    }
};
