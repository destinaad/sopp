<?php

namespace App\Console\Commands;

use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * php artisan laporan:seed-setoran-manual --teller=7503257 --loket=1 --tanggal=2026-09-01 --nominal=1000000 --metode=transfer
 *
 * Insert 1 SetoranHarian (status=submitted) + 1 SetoranDetail langsung
 * lewat Eloquent -- skip HTTP/Controller/FE sepenuhnya. HANYA buat data
 * uji manual pas FE belum ada; JANGAN dipakai di production (gak lewat
 * validasi/otorisasi/rate-limit apapun yang biasanya dilewati Card A).
 *
 * User teller dicari dari kolom users.user_id_sopp -- kalau belum ada
 * (belum pernah SSO login sungguhan), di-firstOrCreate() otomatis pakai
 * --nama yang dikasih (atau nama generik kalau --nama kosong). Ini
 * SENGAJA menyimpang dari alur provisioning SSO asli (yang isi lebih
 * banyak kolom) -- cukup buat kebutuhan cross-check Laporan doang,
 * JANGAN dipakai user hasil command ini buat tes fitur lain yang
 * butuh data provisioning lengkap (mis. Kapabilitas & Sesi).
 */
class SeedSetoranManual extends Command
{
    protected $signature = 'laporan:seed-setoran-manual
        {--teller= : user_id_sopp milik teller (wajib)}
        {--loket= : loket_id (wajib)}
        {--tanggal= : YYYY-MM-DD (wajib)}
        {--nominal=1000000 : nominal setoran}
        {--metode=transfer : transfer|qris|tunai|kpay}
        {--nama= : nama teller, dipakai KALAU user_id_sopp ini belum ada di DB (bikin baru)}';

    protected $description = 'Seed 1 setoran submitted langsung ke DB (skip HTTP/FE) buat cross-check manual Laporan';

    public function handle(): int
    {
        $userIdSopp = $this->option('teller');
        $loketId = $this->option('loket');
        $tanggal = $this->option('tanggal');

        if (! $userIdSopp || ! $loketId || ! $tanggal) {
            $this->error('Wajib isi --teller, --loket, --tanggal.');

            return self::FAILURE;
        }

        $userSebelumnyaAda = User::where('user_id_sopp', $userIdSopp)->exists();

        $user = User::firstOrCreate(
            ['user_id_sopp' => $userIdSopp],
            [
                'loket_id' => (int) $loketId,
                'nama' => $this->option('nama') ?? "Teller {$userIdSopp}",
            ],
        );

        if (! $userSebelumnyaAda) {
            $this->warn("User user_id_sopp='{$userIdSopp}' belum ada, dibikin baru: #{$user->id} ({$user->nama}).");
        }

        $setoran = SetoranHarian::create([
            'user_id' => $user->id,
            'loket_id' => (int) $loketId,
            'tanggal_transaksi' => $tanggal,
            'status' => 'submitted',
            'submitted_at' => now(),
            'entries_updated_at' => now(),
        ]);

        SetoranDetail::create([
            'setoran_harian_id' => $setoran->id,
            'metode_pembayaran' => $this->option('metode'),
            'nominal' => (int) $this->option('nominal'),
            'is_konfirmasi' => true,
        ]);

        $this->info("Berhasil: setoran_harian #{$setoran->id} (user={$user->nama}, loket={$loketId}, {$tanggal}, {$this->option('metode')}={$this->option('nominal')}).");
        $this->line('Sekarang jalanin ulang: php artisan laporan:cek-manual --tanggal=' . $tanggal . ' --loket=' . $loketId);

        return self::SUCCESS;
    }
}