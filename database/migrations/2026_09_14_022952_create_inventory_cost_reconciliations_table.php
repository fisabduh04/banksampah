<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('replacement_movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $table->string('approved_by');
            $table->text('reason');
            $table->json('source_snapshot');
            $table->json('corrected_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_cost_reconciliations')->exists()) {
            throw new RuntimeException('Audit rekonsiliasi yang sudah terisi tidak boleh dihapus melalui rollback.');
        }
        Schema::dropIfExists('inventory_cost_reconciliations');
    }
};
