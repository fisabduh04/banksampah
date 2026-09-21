<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_payments', 'idempotency_key')) {
            Schema::table('sale_payments', function (Blueprint $table): void {
                $table->uuid('idempotency_key')->nullable();
            });
        }
        if (! Schema::hasIndex('sale_payments', ['idempotency_key'], 'unique')) {
            Schema::table('sale_payments', function (Blueprint $table): void {
                $table->unique('idempotency_key');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Pengenal pembayaran dipertahankan untuk mencegah pencatatan ganda. Gunakan migration perbaikan maju.');
    }
};
