<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat header jurnal akuntansi.
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();

            /**
             * Nomor jurnal yang mudah dibaca.
             *
             * Contoh:
             * JU-20260922-000001
             */
            $table->string('entry_number', 50)->unique();

            /**
             * Tanggal ekonomi transaksi.
             */
            $table->date('transaction_date');

            /**
             * Sumber jurnal.
             *
             * Contoh:
             * deposit
             * withdrawal
             * sale
             * sale_payment
             * cash_mutation
             */
            $table->string('reference_type', 50);

            /**
             * ID transaksi sumber.
             */
            $table->unsignedBigInteger('reference_id')->nullable();

            /**
             * Nomor transaksi sumber yang mudah dibaca.
             */
            $table->string('reference_number', 100)->nullable();

            /**
             * Penjelasan jurnal.
             */
            $table->text('description')->nullable();

            /**
             * Status jurnal.
             *
             * posted   = jurnal aktif
             * reversed = sudah dibalik
             */
            $table->string('status', 20)
                ->default('posted');

            /**
             * Jika jurnal ini merupakan reversal,
             * simpan jurnal asalnya.
             */
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->constrained('journal_entries')
                ->restrictOnDelete();

            /**
             * Informasi posting.
             */
            $table->timestamp('posted_at')->nullable();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            /**
             * Satu transaksi sumber hanya boleh
             * menghasilkan satu jurnal utama.
             *
             * reference_id nullable sehingga jurnal manual
             * masih dapat digunakan di masa depan.
             */
            $table->unique(
                ['reference_type', 'reference_id'],
                'journal_entries_source_unique'
            );

            $table->index('transaction_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
