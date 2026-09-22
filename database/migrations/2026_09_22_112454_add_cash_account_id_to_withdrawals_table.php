<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan sumber Kas/Bank pada transaksi penarikan nasabah.
     */
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->foreignId('cash_account_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('cash_accounts')
                ->restrictOnDelete();
        });
    }

    /**
     * Membatalkan perubahan.
     */
    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropForeign(['cash_account_id']);
            $table->dropColumn('cash_account_id');
        });
    }
};
