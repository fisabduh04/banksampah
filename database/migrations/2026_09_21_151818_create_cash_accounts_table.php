<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat master akun Kas dan Bank.
     *
     * Tabel ini hanya menyimpan identitas akun.
     * Saldo TIDAK disimpan di sini karena saldo nantinya
     * dihitung berdasarkan ledger cash_mutations.
     */
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();

            /**
             * Kode akun internal.
             *
             * Contoh:
             * KAS-001
             * BNK-001
             */
            $table->string('code', 30)->unique();

            /**
             * Nama akun yang ditampilkan kepada pengguna.
             *
             * Contoh:
             * Kas Utama
             * Bank BRI
             */
            $table->string('name', 150);

            /**
             * Jenis akun.
             *
             * Nilai yang digunakan saat ini:
             * - cash = Kas Tunai
             * - bank = Rekening Bank
             */
            $table->string('account_type', 20);

            /**
             * Data bank hanya diperlukan apabila
             * account_type = bank.
             */
            $table->string('bank_name', 100)->nullable();

            $table->string('account_number', 100)->nullable();

            $table->string('account_holder', 150)->nullable();

            /**
             * Akun tidak dihapus apabila sudah digunakan.
             *
             * Jika tidak digunakan lagi, cukup dinonaktifkan.
             */
            $table->boolean('is_active')->default(true);

            /**
             * Catatan tambahan akun.
             */
            $table->text('notes')->nullable();

            $table->timestamps();

            /**
             * Index membantu pencarian/filter akun.
             */
            $table->index('account_type');
            $table->index('is_active');
        });
    }

    /**
     * Menghapus tabel apabila migration di-rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_accounts');
    }
};
