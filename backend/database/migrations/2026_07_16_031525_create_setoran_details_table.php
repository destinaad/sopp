<?php
// =====================================================================
// FILE: database/migrations/xxxx_xx_xx_create_setoran_detail_table.php
// (FK cascadeOnDelete ke setoran_harian TETAP dipertahankan -- ini benar:
//  hapus header setoran, wajar ikut hapus detailnya)
// =====================================================================

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setoran_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setoran_harian_id')
                ->constrained('setoran_harian')
                ->cascadeOnDelete();
            $table->enum('metode_pembayaran', ['transfer', 'qris', 'tunai', 'kpay']);
            $table->unsignedBigInteger('nominal');
            $table->string('foto_bukti_path')->nullable();
            $table->boolean('is_konfirmasi')->default(false);
            $table->text('keterangan')->nullable();
            $table->string('nama_karyawan')->nullable();
            $table->string('identitas_karyawan')->nullable(); // NIK atau NIP -- nama generik sengaja dipakai
                                                                // karena bisa diisi salah satu, lihat catatan di respons
            $table->timestamps();

            $table->index(['setoran_harian_id', 'metode_pembayaran']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setoran_detail');
    }
};