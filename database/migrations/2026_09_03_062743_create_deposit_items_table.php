<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('deposit_id')
                ->constrained('deposits')
                ->cascadeOnDelete();

            $table->foreignId('waste_type_id')
                ->constrained('waste_types')
                ->restrictOnDelete();

            $table->decimal('weight', 12, 3);

            $table->decimal('price', 15, 2);

            $table->decimal('subtotal', 15, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_items');
    }
};
