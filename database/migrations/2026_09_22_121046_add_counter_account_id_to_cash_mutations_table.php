<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan akun lawan untuk transaksi Kas/Bank manual.
     *
     * Nullable karena transaksi otomatis lama seperti pembayaran
     * pengepul, reversal, dan saldo awal tidak wajib menggunakannya.
     */
    public function up(): void
    {
        Schema::table('cash_mutations', function (Blueprint $table) {
            $table->foreignId('counter_account_id')
                ->nullable()
                ->after('cash_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_mutations', function (Blueprint $table) {
            $table->dropForeign(['counter_account_id']);
            $table->dropColumn('counter_account_id');
        });
    }
};
