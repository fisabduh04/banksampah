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
        Schema::create('waste_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('waste_type_id')
                ->constrained('waste_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->decimal('price', 15, 2);

            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waste_prices');
    }
};
