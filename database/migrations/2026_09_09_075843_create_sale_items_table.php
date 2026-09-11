<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();

            // Header transaksi penjualan.
            $table->foreignId('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();

            // Jenis sampah yang dijual.
            $table->foreignId('waste_type_id')
                ->constrained('waste_types')
                ->restrictOnDelete();

            // Berat yang dijual dalam satuan jenis sampah tersebut.
            $table->decimal('weight', 12, 3);

            // Harga jual per satuan berat kepada pengepul.
            $table->decimal('price', 15, 2);

            // weight × price.
            $table->decimal('subtotal', 15, 2);

            /*
             * Snapshot biaya persediaan pada saat transaksi diposting.
             *
             * Nilai ini tidak diinput manual oleh operator.
             * Nantinya dihitung oleh service berdasarkan persediaan Fase 5.
             */
            $table->decimal('cost_price', 15, 2)->default(0);

            // weight × cost_price.
            $table->decimal('cost_total', 15, 2)->default(0);

            // subtotal - cost_total.
            $table->decimal('gross_profit', 15, 2)->default(0);

            $table->timestamps();

            /*
             * Dalam satu transaksi, satu jenis sampah cukup satu baris.
             * Ini juga mencegah operator memasukkan jenis yang sama dua kali.
             */
            $table->unique(
                ['sale_id', 'waste_type_id'],
                'sale_items_sale_waste_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
