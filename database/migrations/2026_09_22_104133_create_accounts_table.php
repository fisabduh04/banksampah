<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat Chart of Accounts / Daftar Akun.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            /**
             * Kode akun akuntansi.
             *
             * Contoh:
             * 1101 Kas
             * 1102 Bank
             * 1201 Piutang Pengepul
             */
            $table->string('code', 30)->unique();

            /**
             * Nama akun.
             */
            $table->string('name', 150);

            /**
             * Kelompok utama akun:
             * asset
             * liability
             * equity
             * revenue
             * expense
             */
            $table->string('account_type', 20);

            /**
             * Saldo normal:
             * debit
             * credit
             */
            $table->string('normal_balance', 10);

            /**
             * Mendukung struktur akun induk-anak.
             */
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete();

            /**
             * Penanda akun yang boleh menerima jurnal langsung.
             *
             * Akun induk biasanya false.
             */
            $table->boolean('is_postable')
                ->default(true);

            /**
             * Akun lama tidak dihapus jika sudah digunakan.
             * Cukup dinonaktifkan.
             */
            $table->boolean('is_active')
                ->default(true);

            /**
             * Identifier internal untuk akun sistem.
             *
             * Contoh:
             * inventory
             * customer_savings
             * collector_receivable
             * sales_revenue
             * cogs
             *
             * Tidak semua akun harus mempunyai system_key.
             */
            $table->string('system_key', 100)
                ->nullable()
                ->unique();

            /**
             * Catatan tambahan.
             */
            $table->text('notes')
                ->nullable();

            $table->timestamps();

            $table->index('account_type');
            $table->index('parent_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
