<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setoran_harian', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->unsignedInteger('loket_id');
            $table->date('tanggal_transaksi');

            $table->json('snapshot_sopp')->nullable();

            $table->enum('status', ['draft', 'submitted'])
                ->default('draft');

            // Penanda audit: row ini lahir dari
            // PelunasanController::findOrCreateRowMilikAktor() (aktor
            // belum punya row sendiri buat loket+tanggal ini, jadi
            // dibikinin langsung berstatus submitted) -- bukan dari
            // submission Card A yang wajar. default(false) cukup,
            // gak perlu backfill karena tabel ini baru.
            $table->boolean('dibuat_via_bantuan_pelunasan')->default(false);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('entries_updated_at')->nullable();

            $table->softDeletes();

            $table->timestamps();

            // Query per-loket
            $table->index([
                'loket_id',
                'tanggal_transaksi'
            ]);

            // Query per-teller
            $table->index([
                'user_id',
                'tanggal_transaksi'
            ]);

            $table->index('tanggal_transaksi', 'setoran_harian_tanggal_transaksi_index');
        });

        /*
         * Unique constraint untuk slot aktif.
         *
         * Tidak bisa langsung menggunakan:
         *
         * unique(user_id, loket_id, tanggal_transaksi)
         *
         * karena row yang sudah soft-delete tetap berada
         * di database dan tetap dianggap oleh UNIQUE constraint.
         *
         * Karena itu digunakan generated column yang:
         * - menggunakan kombinasi user/loket/tanggal untuk row aktif
         * - menggunakan ID unik untuk row yang sudah dihapus
         */

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
        ALTER TABLE setoran_harian ADD COLUMN unique_slot_guard TEXT
        GENERATED ALWAYS AS (
            CASE
                WHEN deleted_at IS NULL
                    THEN user_id || '-' || loket_id || '-' || tanggal_transaksi
                ELSE NULL
            END
        ) STORED
    SQL);
        } else {
            DB::statement(<<<'SQL'
        ALTER TABLE setoran_harian ADD COLUMN unique_slot_guard VARCHAR(191)
        GENERATED ALWAYS AS (
            CASE
                WHEN deleted_at IS NULL
                    THEN CONCAT(user_id, '-', loket_id, '-', tanggal_transaksi)
                ELSE NULL
            END
        ) STORED
    SQL);
        }

        Schema::table('setoran_harian', function (Blueprint $table) {
            $table->unique(
                'unique_slot_guard',
                'setoran_harian_unique_slot_guard'
            );
        });
    }

    public function down(): void
    {
        Schema::table('setoran_harian', function (Blueprint $table) {
            $table->dropUnique('setoran_harian_unique_slot_guard');
            $table->dropColumn('unique_slot_guard');
            $table->dropIndex('setoran_harian_tanggal_transaksi_index');
        });

        Schema::dropIfExists('setoran_harian');
    }
};
