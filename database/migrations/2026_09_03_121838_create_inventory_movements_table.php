<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('waste_type_id')
                ->constrained('waste_types')
                ->restrictOnDelete();

            $table->string('movement_type');

            $table->decimal('quantity', 12, 3);

            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->date('transaction_date');

            $table->text('description')->nullable();

            $table->timestamps();

            $table->index([
                'reference_type',
                'reference_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
