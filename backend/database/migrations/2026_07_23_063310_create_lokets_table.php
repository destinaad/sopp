<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sumber nama_loket (§6.1/§8.6) -- loket_id dipakai LANGSUNG sebagai
// primary key (bukan id auto-increment terpisah), karena dia sendiri
// sudah unique identifier alami dari sistem lama. synced_at dicatat
// terpisah dari updated_at bawaan Eloquent, supaya kelihatan jelas
// kapan terakhir disinkron dari bridge (mode master_loket) -- bukan
// kapan row-nya terakhir "disentuh" secara umum.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lokets', function (Blueprint $table) {
            $table->unsignedInteger('loket_id')->primary();
            $table->string('nama_loket');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lokets');
    }
};