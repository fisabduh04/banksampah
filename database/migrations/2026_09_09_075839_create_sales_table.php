<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            // Nomor transaksi penjualan yang unik.
            $table->string('sale_number', 50)->unique();

            // Pengepul yang membeli sampah.
            $table->foreignId('collector_id')
                ->constrained('collectors')
                ->restrictOnDelete();

            $table->date('transaction_date');

            /*
             * Status:
             * draft     = masih dapat diedit
             * posted    = sudah memengaruhi persediaan
             * cancelled = sudah dibatalkan dengan transaksi pembalik
             */
            $table->string('status', 20)->default('draft');

            // Total berat seluruh sampah dalam transaksi.
            $table->decimal('total_weight', 12, 3)->default(0);

            // Nilai penjualan kepada pengepul.
            $table->decimal('total_amount', 15, 2)->default(0);

            /*
             * Total biaya persediaan/HPP.
             * Nilai ini nantinya diperoleh dari metode penilaian
             * persediaan Fase 5 ketika transaksi diposting.
             */
            $table->decimal('total_cost', 15, 2)->default(0);

            // Laba kotor = penjualan - HPP.
            $table->decimal('gross_profit', 15, 2)->default(0);

            $table->string('payment_status', 20)->default('unpaid');
            $table->date('due_date')->nullable();

            $table->text('notes')->nullable();

            // Audit siapa dan kapan transaksi diposting.
            $table->timestamp('posted_at')->nullable();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // Informasi pembatalan transaksi.
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            // Membantu pencarian laporan penjualan berdasarkan periode/status.
            $table->index(['transaction_date', 'status']);
            $table->index(['collector_id', 'transaction_date']);
            $table->index(['payment_status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
