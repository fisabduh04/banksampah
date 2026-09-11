<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collectors', function (Blueprint $table) {
            $table->id();

            // Kode unik pengepul untuk memudahkan identifikasi.
            $table->string('code', 30)->unique();

            // Nama usaha atau nama pengepul.
            $table->string('name');

            // Nama orang yang dapat dihubungi.
            $table->string('contact_person')->nullable();

            $table->string('phone', 30)->nullable();

            // Text dipakai agar alamat tidak dibatasi terlalu pendek.
            $table->text('address')->nullable();

            // Master tidak langsung dihapus jika sudah tidak digunakan.
            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collectors');
    }
};
