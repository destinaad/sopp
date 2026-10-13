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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('user_id_sopp')->nullable()->unique();
            $table->unsignedInteger('loket_id')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('nama');
            $table->string('password')->nullable();
            $table->boolean('is_superadmin')->default(false);
            $table->boolean('bisa_akses_laporan')->default(false); //lihat dan unduh
            $table->boolean('bisa_atur_hak_akses')->default(false);
            $table->boolean('bisa_lihat_log')->default(false);
            $table->boolean('bisa_input_setoran_semua_loket')->default(false); //Create
            $table->boolean('bisa_edit_setoran_semua_loket')->default(false); //update delete
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
