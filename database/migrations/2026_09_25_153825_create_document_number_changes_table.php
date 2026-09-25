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
        Schema::create('document_number_changes', function (Blueprint $table) {
            $table->id();
            $table->string('reference_type', 30);
            $table->unsignedBigInteger('reference_id');
            $table->string('old_number', 100)->unique();
            $table->string('new_number', 100)->unique();
            $table->timestamp('changed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_number_changes');
    }
};
