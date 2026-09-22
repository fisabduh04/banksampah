<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat ledger mutasi Kas/Bank.
     *
     * Semua perubahan saldo dicatat melalui tabel ini.
     * Saldo tidak disimpan langsung, tetapi dihitung
     * dari seluruh histori mutasi masuk dan keluar.
     */
    public function up(): void
    {
        Schema::create('cash_mutations', function (Blueprint $table) {
            $table->id();

            /**
             * Akun Kas/Bank yang mengalami mutasi.
             */
            $table->foreignId('cash_account_id')
                ->constrained('cash_accounts')
                ->restrictOnDelete();

            /**
             * Tanggal ekonomi transaksi.
             */
            $table->date('transaction_date');

            /**
             * Jenis mutasi:
             * in  = uang masuk
             * out = uang keluar
             */
            $table->string('mutation_type', 10);

            /**
             * Nominal selalu disimpan positif.
             * Arah transaksi ditentukan oleh mutation_type.
             */
            $table->decimal('amount', 15, 2);

            /**
             * Jenis sumber transaksi.
             *
             * Contoh:
             * sale_payment
             * sale_payment_cancellation
             * manual_receipt
             * manual_expense
             * opening_balance
             * transfer
             */
            $table->string('reference_type', 50);

            /**
             * ID transaksi sumber.
             */
            $table->unsignedBigInteger('reference_id')->nullable();

            /**
             * Nomor referensi yang mudah dibaca pengguna.
             */
            $table->string('reference_number', 100)->nullable();

            /**
             * Keterangan transaksi.
             */
            $table->text('description')->nullable();

            /**
             * Pengguna yang mencatat / memicu transaksi.
             */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            /**
             * Index untuk mempercepat laporan dan pencarian.
             */
            $table->index(
                ['cash_account_id', 'transaction_date'],
                'cash_mutations_account_date_index'
            );

            $table->index(
                ['reference_type', 'reference_id'],
                'cash_mutations_reference_index'
            );

            /**
             * Mencegah satu transaksi sumber masuk dua kali
             * ke akun Kas/Bank yang sama.
             */
            $table->unique(
                ['cash_account_id', 'reference_type', 'reference_id'],
                'cash_mutations_source_unique'
            );
        });
    }

    /**
     * Menghapus tabel apabila migration di-rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_mutations');
    }
};
