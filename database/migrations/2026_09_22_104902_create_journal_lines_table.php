<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat detail debit-kredit jurnal.
     */
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();

            /**
             * Header jurnal.
             */
            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')
                ->cascadeOnDelete();

            /**
             * Akun Chart of Accounts.
             */
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            /**
             * Nomor urut baris jurnal.
             */
            $table->unsignedSmallInteger('line_number');

            /**
             * Nilai debit dan kredit selalu positif.
             *
             * Untuk satu baris jurnal:
             * salah satu harus bernilai > 0
             * dan sisi lainnya = 0.
             */
            $table->decimal('debit', 15, 2)
                ->default(0);

            $table->decimal('credit', 15, 2)
                ->default(0);

            /**
             * Keterangan khusus baris jurnal.
             */
            $table->string('description', 255)
                ->nullable();

            $table->timestamps();

            /**
             * Nomor baris tidak boleh ganda
             * dalam jurnal yang sama.
             */
            $table->unique(
                ['journal_entry_id', 'line_number'],
                'journal_lines_entry_line_unique'
            );

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
