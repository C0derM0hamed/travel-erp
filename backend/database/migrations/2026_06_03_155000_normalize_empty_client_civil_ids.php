<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')->where('civil_id', '')->update(['civil_id' => null]);
    }

    public function down(): void
    {
        // Intentionally not reversible: null civil IDs are semantically empty values.
    }
};
