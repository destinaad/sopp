<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\SetoranHarian;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Versi scripted dari uji coba manual (2 sesi tinker + sleep) yang
 * sudah dijalankan user -- biar bisa di-rerun kapan aja tanpa perlu
 * buka 2 tinker window dan ngitung waktu manual tiap kali.
 *
 * Nguji PERSIS urutan kode di PelunasanController::store() (baris
 * 286-315): lockForUpdate() ke seluruh row loket+tanggal, DULU baru
 * ActivityLog::exists() (non-locking read) buat cek "sudah pernah
 * dilunasi". Pertanyaannya: begitu transaksi A commit & lepas lock,
 * apakah exists() di transaksi B (yang baru aja lolos nunggu lock)
 * KELIHATAN row yang barusan di-commit A, atau baca snapshot basi?
 *
 * CARA PAKAI -- buka 2 terminal:
 *   Terminal 1 (jalanin DULUAN):
 *     php artisan test:race-pelunasan --role=a --setoran-harian-id=X --user-id=Y
 *   Terminal 2 (jalanin PAS terminal 1 udah nampilin
 *   "SEKARANG jalankan role=b..." -- sebelum sleep-nya habis):
 *     php artisan test:race-pelunasan --role=b --setoran-harian-id=X --user-id=Z
 *
 *   X = id row setoran_harian yang eligible (status submitted).
 *   Y = user_id buat simulasi transaksi A (teller yang "lagi submit").
 *   Z = user_id buat simulasi transaksi B (boleh sama atau beda dari Y
 *       -- kalau mau reproduksi kasus "user SAMA submit 2x nyaris
 *       bersamaan", pakai Y yang sama; kalau mau reproduksi "user
 *       BEDA di loket sama", pakai user_id lain).
 *
 * Test data (ActivityLog dummy) OTOMATIS DIHAPUS setelah role=b
 * selesai baca -- lihat bersihkan() di bawah.
 */
class TestRacePelunasan extends Command
{
    protected $signature = 'test:race-pelunasan
        {--role= : a atau b}
        {--setoran-harian-id= : id row setoran_harian yang eligible}
        {--user-id= : user_id buat simulasi actor}
        {--sleep=15 : detik tidur di role=a, kasih waktu buat mulai role=b}';

    protected $description = 'Reproduksi manual race condition ActivityLog::exists() vs lockForUpdate() di Pelunasan';

    private const RACE_TEST_MARKER = 'race_test_dari_command';

    public function handle(): int
    {
        $role = $this->option('role');
        $setoranHarianId = (int) $this->option('setoran-harian-id');
        $userId = (int) $this->option('user-id');
        $sleepDetik = (int) $this->option('sleep');

        if (! in_array($role, ['a', 'b'], true)) {
            $this->error('Pakai --role=a atau --role=b');
            return self::FAILURE;
        }
        if ($setoranHarianId === 0) {
            $this->error('Wajib isi --setoran-harian-id=<id row setoran_harian yang eligible>');
            return self::FAILURE;
        }
        if ($userId === 0) {
            $this->error('Wajib isi --user-id=<user_id yang valid di tabel users>');
            return self::FAILURE;
        }

        $user = User::find($userId);
        if ($user === null) {
            $this->error("User id={$userId} gak ketemu.");
            return self::FAILURE;
        }

        return $role === 'a'
            ? $this->jalankanA($setoranHarianId, $user, $sleepDetik)
            : $this->jalankanB($setoranHarianId, $user);
    }

    private function jalankanA(int $setoranHarianId, User $user, int $sleepDetik): int
    {
        $this->info('[A] Mulai transaksi, ambil lock via lockForUpdate() (persis baris 286-294 controller)...');

        DB::transaction(function () use ($setoranHarianId, $user, $sleepDetik) {
            $info = SetoranHarian::where('id', $setoranHarianId)
                ->select('loket_id', 'tanggal_transaksi')
                ->firstOrFail();

            $rows = SetoranHarian::where('loket_id', $info->loket_id)
                ->where('tanggal_transaksi', $info->tanggal_transaksi->toDateString())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->info("[A] Lock didapat ({$rows->count()} row terkunci).");
            $this->warn("[A] SEKARANG jalankan role=b di terminal lain -- kamu punya {$sleepDetik} detik.");
            $this->info('[A] Tidur...');
            sleep($sleepDetik);

            // Insert ActivityLog PERSIS lewat jalur yang sama dengan
            // PelunasanController::store() baris 352-362 -- pakai
            // ActivityLog::catat(), bukan create() mentah, biar
            // actor_loket_id/actor_role ikut ke-derive sama persis.
            $log = ActivityLog::catat(
                actor: $user,
                jenisAksi: 'buat_pelunasan',
                objectType: 'setoran_detail',
                objectId: 999999999, // dummy, gak perlu row entry beneran buat tes lock ini
                detailPerubahan: [
                    'setoran_harian_id' => $setoranHarianId,
                    self::RACE_TEST_MARKER => true,
                ],
            );

            $this->info("[A] ActivityLog id={$log->id} ke-insert dalam transaksi. COMMIT sekarang (keluar dari DB::transaction)...");
        }, 3);

        $this->info('[A] Selesai, lock sudah lepas.');
        return self::SUCCESS;
    }

    private function jalankanB(int $setoranHarianId, User $user): int
    {
        $this->info('[B] Nyoba ambil lock yang sama (bakal ke-block kalau A masih pegang)...');
        $mulai = microtime(true);

        $ketemu = DB::transaction(function () use ($setoranHarianId) {
            $info = SetoranHarian::where('id', $setoranHarianId)
                ->select('loket_id', 'tanggal_transaksi')
                ->firstOrFail();

            $rows = SetoranHarian::where('loket_id', $info->loket_id)
                ->where('tanggal_transaksi', $info->tanggal_transaksi->toDateString())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->info('[B] Lock didapat (berarti A baru saja commit & lepas lock).');

            $idRowSetanggal = $rows->pluck('id');

            // Query PERSIS sama dengan baris 312-315 controller.
            return ActivityLog::where('jenis_aksi', 'buat_pelunasan')
                ->whereIn('detail_perubahan->setoran_harian_id', $idRowSetanggal)
                ->where('detail_perubahan->' . self::RACE_TEST_MARKER, true)
                ->exists();
        }, 3);

        $durasi = round(microtime(true) - $mulai, 2);
        $this->info("[B] Lama nunggu sampai lock didapat + query selesai: {$durasi} detik.");

        if ($ketemu) {
            $this->info('[B] HASIL: exists() = true -- row yang di-commit A KELIHATAN. Snapshot gak basi, aman.');
        } else {
            $this->error('[B] HASIL: exists() = false -- row yang di-commit A TIDAK KELIHATAN. Snapshot basi -- race condition beneran kejadian!');
        }

        $this->bersihkan($setoranHarianId);

        return $ketemu ? self::SUCCESS : self::FAILURE;
    }

    private function bersihkan(int $setoranHarianId): void
    {
        $terhapus = ActivityLog::where('jenis_aksi', 'buat_pelunasan')
            ->where('detail_perubahan->setoran_harian_id', $setoranHarianId)
            ->where('detail_perubahan->' . self::RACE_TEST_MARKER, true)
            ->delete();

        $this->comment("[B] Bersihin {$terhapus} row ActivityLog dummy bekas tes.");
    }
}