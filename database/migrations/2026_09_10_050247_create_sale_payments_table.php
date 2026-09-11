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
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();

            $table->string('payment_number', 50)->unique();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 30);
            $table->string('reference_number', 100)->nullable();
            $table->string('status', 20)->default('posted');

            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['sale_id', 'payment_date']);
            $table->index(['status', 'payment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
