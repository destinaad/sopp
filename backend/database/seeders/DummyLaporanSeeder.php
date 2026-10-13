<?php

namespace Database\Seeders;

use App\Models\SetoranDetail;
use App\Models\SetoranHarian;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * php artisan db:seed --class=DummyLaporanSeeder
 * HANYA DEV/TESTING. Aman dijalankan ulang (idempoten).
 * Data cocok dengan dev/fake-bridge/bridge.php (satu sumber: data.php).
 */
class DummyLaporanSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public function run(): void
    {
        require_once base_path('dev/fake-bridge/data.php');

        $this->buatUserAdmin();
        $tellers = $this->buatTeller();
        $this->buatSetoran($tellers);

        $this->command?->info('Selesai. Password semua akun dummy: ' . self::PASSWORD);
    }

    private function user(array $attr, array $flags = []): User
    {
        $u = User::firstOrNew(['username' => $attr['username']]);
        $u->forceFill($attr + $flags);
        if (! $u->exists) {
            $u->password = self::PASSWORD;
        }
        $u->save();
        return $u;
    }

    private function buatUserAdmin(): void
    {
        $this->user(['username' => 'akun_laporan', 'nama' => 'Akun Laporan', 'loket_id' => 1], ['bisa_akses_laporan' => true]);
        $this->user(['username' => 'qa_superadmin', 'nama' => 'Superadmin QA', 'loket_id' => null], ['is_superadmin' => true]);
        $this->user(['username' => 'admin_hakakses', 'nama' => 'Admin Hak Akses', 'loket_id' => null], ['bisa_atur_hak_akses' => true]);
        $this->user(['username' => 'admin_log', 'nama' => 'Admin Log', 'loket_id' => null], ['bisa_lihat_log' => true]);
        $this->user(['username' => 'verifikator', 'nama' => 'Verifikator Dummy', 'loket_id' => 1],
            ['bisa_input_setoran_semua_loket' => true, 'bisa_edit_setoran_semua_loket' => true]);
    }

    /** @return array<int, list<User>> loket_id => [teller1, teller2] */
    private function buatTeller(): array
    {
        $hasil = [];
        foreach ([1, 2, 3, 4] as $loket) {
            foreach (dummy_tellers($loket) as $i => $t) {
                $hasil[$loket][$i] = $this->user([
                    'username' => 'teller' . $loket . ($i + 1),
                    'user_id_sopp' => $t['user_id'],
                    'nama' => $t['nama'],
                    'loket_id' => $loket,
                ]);
            }
        }
        return $hasil;
    }

    private function buatSetoran(array $tellers): void
    {
        $mulai = strtotime('2026-08-01');
        $akhir = strtotime('yesterday');
        for ($d = $mulai; $d <= $akhir; $d += 86400) {
            $tgl = date('Y-m-d', $d);
            foreach ($tellers as $loket => $list) {
                foreach ($list as $idx => $user) {
                    $total = dummy_total_teller($tgl, $loket, $idx);
                    if ($total === 0) {
                        continue;
                    }
                    if (SetoranHarian::withTrashed()->where(['user_id' => $user->id, 'loket_id' => $loket, 'tanggal_transaksi' => $tgl])->exists()) {
                        continue;
                    }
                    $this->isiSetoran($user, $loket, $tgl, $total);
                }
            }
        }
    }

    private function isiSetoran(User $user, int $loket, string $tgl, int $total): void
    {
        $h = crc32("setor|$tgl|$loket|{$user->id}");
        // ~70% pas (selisih 0), sisanya kurang setor 50rb -> kolom Selisih merah
        $disetor = ($h % 10 < 7) ? $total : $total - 50000;

        $metode = $loket >= 3 ? ['transfer', 'tunai', 'kpay', 'qris'] : ['transfer', 'tunai', 'kpay'];
        // bagi ke 2 metode (rotasi berdasar hash); khusus 15 Sep loket 1: T1 transfer+kpay, T2 tunai
        $m1 = $metode[$h % count($metode)];
        $m2 = $metode[($h + 1) % count($metode)];
        if ($loket === 1 && $tgl === '2026-09-15') {
            [$m1, $m2] = $user->user_id_sopp === '90101' ? ['transfer', 'kpay'] : ['tunai', 'tunai'];
        }
        $bagian1 = (int) (round($disetor * 0.6 / 1000) * 1000);
        $porsi = $m1 === $m2 ? [[$m1, $disetor]] : [[$m1, $bagian1], [$m2, $disetor - $bagian1]];

        $setoran = SetoranHarian::create([
            'user_id' => $user->id, 'loket_id' => $loket, 'tanggal_transaksi' => $tgl,
            'status' => 'submitted', 'submitted_at' => "$tgl 16:00:00", 'entries_updated_at' => "$tgl 16:00:00",
        ]);

        foreach ($porsi as $n => [$m, $nominal]) {
            $foto = null;
            if (in_array($m, ['transfer', 'tunai'], true)) {
                $foto = "{$tgl}/{$loket}_{$m}_dummy_{$user->id}_{$n}.png";
                Storage::disk('local')->put("bukti_setoran/{$foto}", $this->gambar("{$m} {$tgl} #{$user->id}"));
            }
            SetoranDetail::create([
                'setoran_harian_id' => $setoran->id, 'metode_pembayaran' => $m, 'nominal' => $nominal,
                'foto_bukti_path' => $foto, 'is_konfirmasi' => true,
                'keterangan' => "Setoran {$m} dummy", 'nama_karyawan' => $user->nama, 'identitas_karyawan' => '19900101',
            ]);
        }
    }

    private function gambar(string $teks): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(400, 240);
            imagefill($im, 0, 0, imagecolorallocate($im, 235, 240, 250));
            imagestring($im, 5, 20, 100, 'BUKTI DUMMY', imagecolorallocate($im, 30, 60, 140));
            imagestring($im, 3, 20, 130, $teks, imagecolorallocate($im, 80, 80, 80));
            ob_start();
            imagepng($im);
            return ob_get_clean();
        }
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }
}
