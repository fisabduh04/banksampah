<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->string('cancellation_type', 30)->nullable();
            $table->string('refund_reference', 100)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('verification_reference', 100)->nullable();
        });
        Schema::table('inventory_cost_reconciliations', function (Blueprint $table): void {
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('financial_role', 30)->default('operator');
        });
        foreach (['deposits', 'withdrawals'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if ($name === 'withdrawals') {
                    $table->string('cancellation_type', 30)->nullable();
                    $table->string('refund_reference', 100)->nullable();
                    $table->timestamp('verified_at')->nullable();
                    $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
                    $table->string('verification_reference', 100)->nullable();
                }
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('cancellation_reason')->nullable();
            });
        }
        Schema::create('financial_controls', function (Blueprint $table): void {
            $table->id();
            $table->date('closed_through')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('closing_reason')->nullable();
            $table->timestamps();
        });
        DB::table('financial_controls')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('financial_control_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 40);
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->json('before_state');
            $table->json('after_state');
            $table->text('reason');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Kontrol keuangan dan jejak audit tidak boleh dihapus melalui rollback otomatis.');
    }
};
