<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('waste_types', function (Blueprint $table) {
            $table->foreignId('waste_category_id')
                ->nullable()
                ->after('id')
                ->constrained('waste_categories')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waste_types', function (Blueprint $table) {
            $table->dropForeign(['waste_category_id']);
            $table->dropColumn('waste_category_id');
        });
    }
};
