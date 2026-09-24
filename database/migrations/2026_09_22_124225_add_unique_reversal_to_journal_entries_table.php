<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu jurnal asal hanya boleh memiliki satu jurnal reversal.
     *
     * Nilai NULL tetap boleh digunakan oleh banyak jurnal normal.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique(
                'reversal_of_id',
                'journal_entries_reversal_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(
                'journal_entries_reversal_unique'
            );
        });
    }
};
