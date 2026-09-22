<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan kunci idempotensi untuk mencegah
     * pencatatan mutasi Kas/Bank secara ganda.
     */
    public function up(): void
    {
        Schema::table('cash_mutations', function (Blueprint $table) {
            $table->uuid('idempotency_key')
                ->nullable()
                ->unique()
                ->after('reference_number');
        });
    }

    /**
     * Membatalkan perubahan.
     */
    public function down(): void
    {
        Schema::table('cash_mutations', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
