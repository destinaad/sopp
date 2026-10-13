<?php
// =====================================================================
// FILE: database/migrations/xxxx_xx_xx_create_activity_logs_table.php
//
// FIX 1: FK user_id -- cascadeOnDelete() diganti restrictOnDelete().
//        Alasan sama persis dengan setoran_harian: log audit harus
//        tetap ada walau user-nya suatu saat dihapus -- kalau memang
//        user tidak pernah benar-benar di-delete, constraint ini
//        seharusnya tidak pernah jadi masalah, cuma jaring pengaman.
//
// FIX 2: target_type + target_id (manual unsignedBigInteger + string)
//        diganti nullableMorphs('target') bawaan Eloquent. Ini
//        idiom standar Laravel untuk "kolom ini menunjuk ke row di
//        salah satu dari beberapa tabel berbeda tergantung target_type"
//        (setoran_harian / setoran_detail / users) -- otomatis bikin
//        index composite yang benar, dan konsisten dipakai bareng
//        method morphTo() kalau nanti perlu relasi Eloquent penuh
//        (belum ditambahkan di model karena belum ada morphMap-nya --
//        lihat catatan di respons).
//
// FIX 3: target_loket_id menyimpan loket milik TARGET data yang dikenai
//        aksi, bukan loket milik pelaku aksi. Nilai ini dibekukan saat
//        kejadian melalui ActivityLog::catat(), sehingga tetap akurat
//        walaupun data target kemudian dihapus.
// =====================================================================

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete(); // FIX: was cascadeOnDelete()

            $table->string('jenis_aksi'); // buat_setoran, edit_setoran, submit_setoran,
                                          // ubah_kapabilitas, download_laporan,
                                          // auto_provisioning, vendor_baru_terdeteksi,
                                          // mutasi_loket_terdeteksi, dst

            $table->nullableMorphs('target');

            // Loket milik pelaku aksi
            $table->unsignedInteger('actor_loket_id')->nullable();

            // Loket milik target data yang dikenai aksi
            $table->unsignedInteger('target_loket_id')->nullable();

            $table->string('actor_role')->nullable();

            $table->json('detail_perubahan')->nullable(); // nilai sebelum/sesudah untuk audit

            $table->timestamp('created_at')->useCurrent();

            // Untuk halaman log: filter per aktor, urut waktu terbaru
            $table->index(['user_id', 'created_at']);

            // Untuk filter berdasarkan jenis aksi
            $table->index('jenis_aksi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};