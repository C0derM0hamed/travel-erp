<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon', 16)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('category', ['airline', 'hotel', 'visa', 'transport', 'other'])->default('other')->index();
            $table->string('phone')->nullable();
            $table->string('contact')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->index();
            $table->string('alt_phone')->nullable();
            $table->string('civil_id')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('nationality')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('safes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['cash', 'bank'])->index();
            $table->string('currency', 3)->default('KWD');
            $table->decimal('opening_balance', 14, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->unique();
            $table->enum('type', ['asset', 'liability', 'revenue', 'expense']);
            $table->foreignId('safe_id')->nullable()->constrained('safes')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->string('ref')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3)->default('KWD');
            $table->decimal('client_price', 14, 3);
            $table->decimal('vendor_cost', 14, 3);
            $table->decimal('profit', 14, 3);
            $table->decimal('initial_payment', 14, 3)->default(0);
            $table->enum('payment_method', ['cash', 'bank', 'knet', 'check'])->default('cash');
            $table->text('notes')->nullable();
            $table->enum('status', ['new', 'processing', 'completed', 'cancelled'])->default('new')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('op_date')->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('ref')->unique();
            $table->enum('type', ['receipt', 'payment'])->index();
            $table->enum('party_type', ['client', 'vendor', 'general'])->default('general')->index();
            $table->unsignedBigInteger('party_id')->nullable()->index();
            $table->decimal('amount', 14, 3);
            $table->string('currency', 3)->default('KWD');
            $table->enum('method', ['cash', 'bank', 'knet', 'check'])->default('cash');
            $table->foreignId('safe_id')->constrained('safes')->restrictOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->string('reference_number')->nullable();
            $table->text('description')->nullable();
            $table->date('voucher_date')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->index();
            $table->string('ref')->index();
            $table->enum('source_type', ['operation', 'voucher'])->index();
            $table->unsignedBigInteger('source_id')->index();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->enum('party_type', ['client', 'vendor', 'general', 'none'])->default('none')->index();
            $table->unsignedBigInteger('party_id')->nullable()->index();
            $table->string('party_name')->nullable();
            $table->decimal('debit', 14, 3)->default(0);
            $table->decimal('credit', 14, 3)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index(['account_id', 'party_type', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('chart_of_accounts');
        Schema::dropIfExists('safes');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('services');
    }
};
