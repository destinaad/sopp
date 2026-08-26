/**
 * Mock data Halaman Laporan — mencakup semua 6 skenario tabel.
 * Nanti diganti dengan fetch ke bridge.php berdasarkan filter yang aktif.
 *
 * Struktur kolom sesuai Laporan Bulanan Loket SOPP:
 *   Loket/Tanggal | Finnet | PUSKUD | JNE | Fee Lainnya | Jumlah Total
 *   | Transfer | QRIS | Tunai | KPAY | Jumlah Disetor | Selisih | Status
 *
 * 4 kolom metode setoran (Transfer, QRIS, Tunai, KPAY) SENGAJA dipisah,
 * bukan digabung "Transfer/QRIS" seperti versi lama — karena Transfer dan
 * QRIS adalah dua metode pembayaran yang berbeda.
 */

export const DAFTAR_LOKET = [
  "SOPP Blimbing",
  "SOPP Lowokwaru",
  "SOPP Klojen",
  "SOPP Sukun",
  "SOPP Kedungkandang",
  "SOPP Dinoyo",
  "SOPP Tlogomas",
  "SOPP Merjosari",
  "SOPP Sawojajar",
  "SOPP Bandulan",
  "SOPP Karangbesuki",
];

// ─────────────────────────────────────────────────────────────
// SKENARIO 3 & 1 BARIS DEFAULT: Harian + Semua Loket
// ─────────────────────────────────────────────────────────────
export const dataSkenario3HarianSemuaLoket = [
  { loket: "SOPP Blimbing",       finnet: 2205776,  puskud: 11159293, jne: 46000,  feeLain: 0, jumlahTotal: 13411069, transfer: 5320000, qris: 2400000, tunai: 2000000, kpay: 280000, jumlahSetor: 10000000, selisih: 3411069, status: "belum-lunas" },
  { loket: "SOPP Lowokwaru",      finnet: 1800000,  puskud: 8200000,  jne: 32000,  feeLain: 0, jumlahTotal: 10032000, transfer: 3658000, qris: 1650000, tunai: 1200000, kpay: 192000, jumlahSetor: 6700000,  selisih: 3332000, status: "belum-lunas" },
  { loket: "SOPP Klojen",         finnet: 2100000,  puskud: 9500000,  jne: 40000,  feeLain: 0, jumlahTotal: 11640000, transfer: 4788000, qris: 2160000, tunai: 1800000, kpay: 252000, jumlahSetor: 9000000,  selisih: 50000,   status: "belum-lunas" },
  { loket: "SOPP Sukun",          finnet: 1500000,  puskud: 5800000,  jne: 28000,  feeLain: 0, jumlahTotal: 7328000,  transfer: 2860000, qris: 1290000, tunai: 900000, kpay: 150000, jumlahSetor: 5200000,  selisih: 2128000, status: "belum-lunas" },
  { loket: "SOPP Kedungkandang",  finnet: 2000000,  puskud: 8100000,  jne: 35000,  feeLain: 0, jumlahTotal: 10135000, transfer: 4056000, qris: 1830000, tunai: 1400000, kpay: 214000, jumlahSetor: 7500000,  selisih: 2635000, status: "belum-lunas" },
  { loket: "SOPP Dinoyo",         finnet: 1300000,  puskud: 4900000,  jne: 22000,  feeLain: 0, jumlahTotal: 6222000,  transfer: 2594000, qris: 1170000, tunai: 800000, kpay: 136000, jumlahSetor: 4700000,  selisih: 1522000, status: "belum-lunas" },
  { loket: "SOPP Tlogomas",       finnet: 1700000,  puskud: 6800000,  jne: 30000,  feeLain: 0, jumlahTotal: 8530000,  transfer: 3458000, qris: 1560000, tunai: 1100000, kpay: 182000, jumlahSetor: 6300000,  selisih: 2230000, status: "belum-lunas" },
  { loket: "SOPP Merjosari",      finnet: 1600000,  puskud: 6200000,  jne: 27000,  feeLain: 0, jumlahTotal: 7827000,  transfer: 3126000, qris: 1410000, tunai: 950000, kpay: 164000, jumlahSetor: 5650000,  selisih: 2177000, status: "belum-lunas" },
  { loket: "SOPP Sawojajar",      finnet: 2300000,  puskud: 10200000, jne: 42000,  feeLain: 0, jumlahTotal: 12542000, transfer: 4522000, qris: 2040000, tunai: 1600000, kpay: 238000, jumlahSetor: 8400000,  selisih: 50000,   status: "belum-lunas" },
  { loket: "SOPP Bandulan",       finnet: 1200000,  puskud: 4500000,  jne: 20000,  feeLain: 0, jumlahTotal: 5720000,  transfer: 2394000, qris: 1080000, tunai: 750000, kpay: 126000, jumlahSetor: 4350000,  selisih: 1370000, status: "belum-lunas" },
  { loket: "SOPP Karangbesuki",   finnet: 1400000,  puskud: 5600000,  jne: 24000,  feeLain: 0, jumlahTotal: 7024000,  transfer: 2726000, qris: 1230000, tunai: 880000, kpay: 144000, jumlahSetor: 4980000,  selisih: 2044000, status: "belum-lunas" },
];

// ─────────────────────────────────────────────────────────────
// SKENARIO 1: Bulanan + Semua Loket
// ─────────────────────────────────────────────────────────────
export const dataSkenario1BulananSemuaLoket = [
  { loket: "SOPP Blimbing",       finnet: 66173280,  puskud: 334778790, jne: 1380000, feeLain: 0, jumlahTotal: 402332070, transfer: 159600000, qris: 72000000, tunai: 60000000, kpay: 8400000, jumlahSetor: 300000000, selisih: 102332070, status: "belum-lunas" },
  { loket: "SOPP Lowokwaru",      finnet: 54000000,  puskud: 246000000, jne: 960000,  feeLain: 0, jumlahTotal: 300960000, transfer: 109725000, qris: 49500000, tunai: 36000000, kpay: 5775000, jumlahSetor: 201000000, selisih: 99960000,  status: "belum-lunas" },
  { loket: "SOPP Klojen",         finnet: 63000000,  puskud: 285000000, jne: 1200000, feeLain: 0, jumlahTotal: 349200000, transfer: 143640000, qris: 64800000, tunai: 54000000, kpay: 7560000, jumlahSetor: 270000000, selisih: 50000,     status: "belum-lunas" },
  { loket: "SOPP Sukun",          finnet: 45000000,  puskud: 174000000, jne: 840000,  feeLain: 0, jumlahTotal: 219840000, transfer: 85785000, qris: 38700000, tunai: 27000000, kpay: 4515000, jumlahSetor: 156000000, selisih: 63840000,  status: "belum-lunas" },
  { loket: "SOPP Kedungkandang",  finnet: 60000000,  puskud: 243000000, jne: 1050000, feeLain: 0, jumlahTotal: 304050000, transfer: 121695000, qris: 54900000, tunai: 42000000, kpay: 6405000, jumlahSetor: 225000000, selisih: 79050000,  status: "belum-lunas" },
  { loket: "SOPP Dinoyo",         finnet: 39000000,  puskud: 147000000, jne: 660000,  feeLain: 0, jumlahTotal: 186660000, transfer: 77805000, qris: 35100000, tunai: 24000000, kpay: 4095000, jumlahSetor: 141000000, selisih: 45660000,  status: "belum-lunas" },
  { loket: "SOPP Tlogomas",       finnet: 51000000,  puskud: 204000000, jne: 900000,  feeLain: 0, jumlahTotal: 255900000, transfer: 103740000, qris: 46800000, tunai: 33000000, kpay: 5460000, jumlahSetor: 189000000, selisih: 66900000,  status: "belum-lunas" },
  { loket: "SOPP Merjosari",      finnet: 48000000,  puskud: 186000000, jne: 810000,  feeLain: 0, jumlahTotal: 234810000, transfer: 93765000, qris: 42300000, tunai: 28500000, kpay: 4935000, jumlahSetor: 169500000, selisih: 65310000,  status: "belum-lunas" },
  { loket: "SOPP Sawojajar",      finnet: 69000000,  puskud: 306000000, jne: 1260000, feeLain: 0, jumlahTotal: 376260000, transfer: 135660000, qris: 61200000, tunai: 48000000, kpay: 7140000, jumlahSetor: 252000000, selisih: 50000,     status: "belum-lunas" },
  { loket: "SOPP Bandulan",       finnet: 36000000,  puskud: 135000000, jne: 600000,  feeLain: 0, jumlahTotal: 171600000, transfer: 71820000, qris: 32400000, tunai: 22500000, kpay: 3780000, jumlahSetor: 130500000, selisih: 41100000,  status: "belum-lunas" },
  { loket: "SOPP Karangbesuki",   finnet: 42000000,  puskud: 168000000, jne: 720000,  feeLain: 0, jumlahTotal: 210720000, transfer: 81795000, qris: 36900000, tunai: 26400000, kpay: 4305000, jumlahSetor: 149400000, selisih: 61320000,  status: "belum-lunas" },
];

// ─────────────────────────────────────────────────────────────
// SKENARIO 2: Bulanan + 1 Loket (SOPP Blimbing) → per hari
// ─────────────────────────────────────────────────────────────
export const dataSkenario2BulananSatuLoket = [
  { tanggal: "2026-07-01", finnet: 1985198,  puskud: 10043364, jne: 41400, feeLain: 0, jumlahTotal: 12069962,  transfer: 4788000, qris: 2160000, tunai: 1800000, kpay: 252000, jumlahSetor: 9000000,  selisih: 3069962,  status: "belum-lunas" },
  { tanggal: "2026-07-02", finnet: 2095487,  puskud: 10601328, jne: 43700, feeLain: 0, jumlahTotal: 12740515,  transfer: 5054000, qris: 2280000, tunai: 1900000, kpay: 266000, jumlahSetor: 9500000,  selisih: 3240515,  status: "belum-lunas" },
  { tanggal: "2026-07-03", finnet: 2205776,  puskud: 11159293, jne: 46000, feeLain: 0, jumlahTotal: 13411069,  transfer: 5320000, qris: 2400000, tunai: 2000000, kpay: 280000, jumlahSetor: 10000000, selisih: 50000,    status: "belum-lunas" },
  { tanggal: "2026-07-04", finnet: 2316065,  puskud: 11717258, jne: 48300, feeLain: 0, jumlahTotal: 14081622,  transfer: 5586000, qris: 2520000, tunai: 2100000, kpay: 294000, jumlahSetor: 10500000, selisih: 3581622,  status: "belum-lunas" },
  { tanggal: "2026-07-05", finnet: 2426354,  puskud: 12275222, jne: 50600, feeLain: 0, jumlahTotal: 14752176,  transfer: 5852000, qris: 2640000, tunai: 2200000, kpay: 308000, jumlahSetor: 11000000, selisih: 3752176,  status: "belum-lunas" },
  { tanggal: "2026-07-07", finnet: 1985198,  puskud: 10043364, jne: 41400, feeLain: 0, jumlahTotal: 12069962,  transfer: 4788000, qris: 2160000, tunai: 1800000, kpay: 252000, jumlahSetor: 9000000,  selisih: 3069962,  status: "belum-lunas" },
  { tanggal: "2026-07-08", finnet: 2095487,  puskud: 10601328, jne: 43700, feeLain: 0, jumlahTotal: 12740516,  transfer: 5054000, qris: 2280000, tunai: 1900000, kpay: 266000, jumlahSetor: 9500000,  selisih: 3240516,  status: "belum-lunas" },
  { tanggal: "2026-07-09", finnet: 2205776,  puskud: 11159293, jne: 46000, feeLain: 0, jumlahTotal: 13411069,  transfer: 5320000, qris: 2400000, tunai: 2000000, kpay: 280000, jumlahSetor: 10000000, selisih: 3411069,  status: "belum-lunas" },
  { tanggal: "2026-07-10", finnet: 2316065,  puskud: 11717258, jne: 48300, feeLain: 0, jumlahTotal: 14081622,  transfer: 5586000, qris: 2520000, tunai: 2100000, kpay: 294000, jumlahSetor: 10500000, selisih: 3581622,  status: "belum-lunas" },
  { tanggal: "2026-07-11", finnet: 2426354,  puskud: 12275222, jne: 50600, feeLain: 0, jumlahTotal: 14752176,  transfer: 5852000, qris: 2640000, tunai: 2200000, kpay: 308000, jumlahSetor: 11000000, selisih: 3752176,  status: "belum-lunas" },
  { tanggal: "2026-07-14", finnet: 1985198,  puskud: 10043364, jne: 41400, feeLain: 0, jumlahTotal: 12069962,  transfer: 4788000, qris: 2160000, tunai: 1800000, kpay: 252000, jumlahSetor: 9000000,  selisih: 3069962,  status: "belum-lunas" },
  { tanggal: "2026-07-15", finnet: 2095487,  puskud: 10601328, jne: 43700, feeLain: 0, jumlahTotal: 12740516,  transfer: 5054000, qris: 2280000, tunai: 1900000, kpay: 266000, jumlahSetor: 9500000,  selisih: 3240516,  status: "belum-lunas" },
  { tanggal: "2026-07-16", finnet: 2205776,  puskud: 11159293, jne: 46000, feeLain: 0, jumlahTotal: 13411069,  transfer: 5320000, qris: 2400000, tunai: 2000000, kpay: 280000, jumlahSetor: 10000000, selisih: 3411069,  status: "belum-lunas" },
  { tanggal: "2026-07-17", finnet: 2316065,  puskud: 11717258, jne: 48300, feeLain: 0, jumlahTotal: 14081622,  transfer: 5586000, qris: 2520000, tunai: 2100000, kpay: 294000, jumlahSetor: 10500000, selisih: 3581622,  status: "belum-lunas" },
  { tanggal: "2026-07-18", finnet: 2426354,  puskud: 12275222, jne: 50600, feeLain: 0, jumlahTotal: 14752176,  transfer: 5852000, qris: 2640000, tunai: 2200000, kpay: 308000, jumlahSetor: 11000000, selisih: 3752176,  status: "belum-lunas" },
  { tanggal: "2026-07-21", finnet: 1985198,  puskud: 10043364, jne: 41400, feeLain: 0, jumlahTotal: 12069962,  transfer: 4788000, qris: 2160000, tunai: 1800000, kpay: 252000, jumlahSetor: 9000000,  selisih: 3069962,  status: "belum-lunas" },
  { tanggal: "2026-07-22", finnet: 2095487,  puskud: 10601328, jne: 43700, feeLain: 0, jumlahTotal: 12740516,  transfer: 5054000, qris: 2280000, tunai: 1900000, kpay: 266000, jumlahSetor: 9500000,  selisih: 3240516,  status: "belum-lunas" },
  { tanggal: "2026-07-23", finnet: 2205776,  puskud: 11159293, jne: 46000, feeLain: 0, jumlahTotal: 13411069,  transfer: 5320000, qris: 2400000, tunai: 2000000, kpay: 280000, jumlahSetor: 10000000, selisih: 3411069,  status: "belum-lunas" },
  { tanggal: "2026-07-24", finnet: 2316065,  puskud: 11717258, jne: 48300, feeLain: 0, jumlahTotal: 14081622,  transfer: 5586000, qris: 2520000, tunai: 2100000, kpay: 294000, jumlahSetor: 10500000, selisih: 3581622,  status: "belum-lunas" },
  { tanggal: "2026-07-25", finnet: 2426354,  puskud: 12275222, jne: 50600, feeLain: 0, jumlahTotal: 14752176,  transfer: 5852000, qris: 2640000, tunai: 2200000, kpay: 308000, jumlahSetor: 11000000, selisih: 3752176,  status: "belum-lunas" },
  { tanggal: "2026-07-28", finnet: 1985198,  puskud: 10043364, jne: 41400, feeLain: 0, jumlahTotal: 12069962,  transfer: 4788000, qris: 2160000, tunai: 1800000, kpay: 252000, jumlahSetor: 9000000,  selisih: 3069962,  status: "belum-lunas" },
  { tanggal: "2026-07-29", finnet: 2095487,  puskud: 10601328, jne: 43700, feeLain: 0, jumlahTotal: 12740516,  transfer: 5054000, qris: 2280000, tunai: 1900000, kpay: 266000, jumlahSetor: 9500000,  selisih: 3240516,  status: "belum-lunas" },
  { tanggal: "2026-07-30", finnet: 2205776,  puskud: 11159293, jne: 46000, feeLain: 0, jumlahTotal: 13411069,  transfer: 5320000, qris: 2400000, tunai: 2000000, kpay: 280000, jumlahSetor: 10000000, selisih: 3411069,  status: "belum-lunas" },
];

// ─────────────────────────────────────────────────────────────
// SKENARIO 4: Harian + 1 Loket → expand penuh rincian transaksi
// ─────────────────────────────────────────────────────────────
export const dataSkenario4HarianSatuLoket = {
  loket: "SOPP Blimbing",
  tanggal: "2026-07-24",
  finnet: 2205776,
  puskud: 11159293,
  jne: 46000,
  feeLain: 0,
  jumlahTotal: 13411069,
  selisih: 3411069,
  status: "belum-lunas",
  metodeSetoran: {
    transfer: { nominal: 8000000, bukti: true },
    qris: { nominal: 2000000, bukti: true },
    tunai: { nominal: 2500000, bukti: true },
    kpay: { nominal: 900000 },
  },
};

// ─────────────────────────────────────────────────────────────
// SKENARIO 5: Rentang + Semua Loket (1 Jul – 15 Jul 2026)
// ─────────────────────────────────────────────────────────────
export const dataSkenario5RentangSemuaLoket = [
  { loket: "SOPP Blimbing",       finnet: 33086640, puskud: 167389395, jne: 690000, feeLain: 0, jumlahTotal: 201166035, transfer: 79800000, qris: 36000000, tunai: 30000000, kpay: 4200000, jumlahSetor: 150000000, selisih: 51166035, status: "belum-lunas" },
  { loket: "SOPP Lowokwaru",      finnet: 27000000, puskud: 123000000, jne: 480000, feeLain: 0, jumlahTotal: 150480000, transfer: 54862000, qris: 24750000, tunai: 18000000, kpay: 2888000, jumlahSetor: 100500000, selisih: 49980000, status: "belum-lunas" },
  { loket: "SOPP Klojen",         finnet: 31500000, puskud: 142500000, jne: 600000, feeLain: 0, jumlahTotal: 174600000, transfer: 71820000, qris: 32400000, tunai: 27000000, kpay: 3780000, jumlahSetor: 135000000, selisih: 50000,    status: "belum-lunas" },
  { loket: "SOPP Sukun",          finnet: 22500000, puskud: 87000000,  jne: 420000, feeLain: 0, jumlahTotal: 109920000, transfer: 42892000, qris: 19350000, tunai: 13500000, kpay: 2258000, jumlahSetor: 78000000,  selisih: 31920000, status: "belum-lunas" },
  { loket: "SOPP Kedungkandang",  finnet: 30000000, puskud: 121500000, jne: 525000, feeLain: 0, jumlahTotal: 152025000, transfer: 60848000, qris: 27450000, tunai: 21000000, kpay: 3202000, jumlahSetor: 112500000, selisih: 39525000, status: "belum-lunas" },
  { loket: "SOPP Dinoyo",         finnet: 19500000, puskud: 73500000,  jne: 330000, feeLain: 0, jumlahTotal: 93330000,  transfer: 38902000, qris: 17550000, tunai: 12000000, kpay: 2048000, jumlahSetor: 70500000,  selisih: 22830000, status: "belum-lunas" },
  { loket: "SOPP Tlogomas",       finnet: 25500000, puskud: 102000000, jne: 450000, feeLain: 0, jumlahTotal: 127950000, transfer: 51870000, qris: 23400000, tunai: 16500000, kpay: 2730000, jumlahSetor: 94500000,  selisih: 33450000, status: "belum-lunas" },
  { loket: "SOPP Merjosari",      finnet: 24000000, puskud: 93000000,  jne: 405000, feeLain: 0, jumlahTotal: 117405000, transfer: 46882000, qris: 21150000, tunai: 14250000, kpay: 2468000, jumlahSetor: 84750000,  selisih: 32655000, status: "belum-lunas" },
  { loket: "SOPP Sawojajar",      finnet: 34500000, puskud: 153000000, jne: 630000, feeLain: 0, jumlahTotal: 188130000, transfer: 67830000, qris: 30600000, tunai: 24000000, kpay: 3570000, jumlahSetor: 126000000, selisih: 50000,    status: "belum-lunas" },
  { loket: "SOPP Bandulan",       finnet: 18000000, puskud: 67500000,  jne: 300000, feeLain: 0, jumlahTotal: 85800000,  transfer: 35910000, qris: 16200000, tunai: 11250000, kpay: 1890000, jumlahSetor: 65250000,  selisih: 20550000, status: "belum-lunas" },
  { loket: "SOPP Karangbesuki",   finnet: 21000000, puskud: 84000000,  jne: 360000, feeLain: 0, jumlahTotal: 105360000, transfer: 40898000, qris: 18450000, tunai: 13200000, kpay: 2152000, jumlahSetor: 74700000,  selisih: 30660000, status: "belum-lunas" },
];

// ─────────────────────────────────────────────────────────────
// SKENARIO 6: Rentang + 1 Loket (1–15 Jul 2026) → per hari
// ─────────────────────────────────────────────────────────────
export const dataSkenario6RentangSatuLoket = [
  { tanggal: "2026-07-01", finnet: 1985198, puskud: 10043364, jne: 41400, feeLain: 0, jumlahTotal: 12069962, transfer: 4788000, qris: 2160000, tunai: 1800000, kpay: 252000, jumlahSetor: 9000000,  selisih: 3069962,  status: "belum-lunas" },
  { tanggal: "2026-07-02", finnet: 2095487, puskud: 10601328, jne: 43700, feeLain: 0, jumlahTotal: 12740515, transfer: 5054000, qris: 2280000, tunai: 1900000, kpay: 266000, jumlahSetor: 9500000,  selisih: 3240515,  status: "belum-lunas" },
  { tanggal: "2026-07-03", finnet: 2205776, puskud: 11159293, jne: 46000, feeLain: 0, jumlahTotal: 13411069, transfer: 5320000, qris: 2400000, tunai: 2000000, kpay: 280000, jumlahSetor: 10000000, selisih: 50000,    status: "belum-lunas" },
  { tanggal: "2026-07-04", finnet: 2316065, puskud: 11717258, jne: 48300, feeLain: 0, jumlahTotal: 14081622, transfer: 5586000, qris: 2520000, tunai: 2100000, kpay: 294000, jumlahSetor: 10500000, selisih: 3581622,  status: "belum-lunas" },
  { tanggal: "2026-07-05", finnet: 2426354, puskud: 12275222, jne: 50600, feeLain: 0, jumlahTotal: 14752176, transfer: 5852000, qris: 2640000, tunai: 2200000, kpay: 308000, jumlahSetor: 11000000, selisih: 3752176,  status: "belum-lunas" },
  { tanggal: "2026-07-07", finnet: 1985198, puskud: 10043364, jne: 41400, feeLain: 0, jumlahTotal: 12069962, transfer: 4788000, qris: 2160000, tunai: 1800000, kpay: 252000, jumlahSetor: 9000000,  selisih: 3069962,  status: "belum-lunas" },
  { tanggal: "2026-07-08", finnet: 2095487, puskud: 10601328, jne: 43700, feeLain: 0, jumlahTotal: 12740515, transfer: 5054000, qris: 2280000, tunai: 1900000, kpay: 266000, jumlahSetor: 9500000,  selisih: 3240515,  status: "belum-lunas" },
  { tanggal: "2026-07-09", finnet: 2205776, puskud: 11159293, jne: 46000, feeLain: 0, jumlahTotal: 13411069, transfer: 5320000, qris: 2400000, tunai: 2000000, kpay: 280000, jumlahSetor: 10000000, selisih: 3411069,  status: "belum-lunas" },
  { tanggal: "2026-07-10", finnet: 2316065, puskud: 11717258, jne: 48300, feeLain: 0, jumlahTotal: 14081622, transfer: 5586000, qris: 2520000, tunai: 2100000, kpay: 294000, jumlahSetor: 10500000, selisih: 3581622,  status: "belum-lunas" },
  { tanggal: "2026-07-11", finnet: 2426354, puskud: 12275222, jne: 50600, feeLain: 0, jumlahTotal: 14752176, transfer: 5852000, qris: 2640000, tunai: 2200000, kpay: 308000, jumlahSetor: 11000000, selisih: 3752176,  status: "belum-lunas" },
  { tanggal: "2026-07-12", finnet: 1985198, puskud: 10043364, jne: 41400, feeLain: 0, jumlahTotal: 12069962, transfer: 4788000, qris: 2160000, tunai: 1800000, kpay: 252000, jumlahSetor: 9000000,  selisih: 3069962,  status: "belum-lunas" },
  { tanggal: "2026-07-13", finnet: 2095487, puskud: 10601328, jne: 43700, feeLain: 0, jumlahTotal: 12740515, transfer: 5054000, qris: 2280000, tunai: 1900000, kpay: 266000, jumlahSetor: 9500000,  selisih: 3240515,  status: "belum-lunas" },
  { tanggal: "2026-07-14", finnet: 2205776, puskud: 11159293, jne: 46000, feeLain: 0, jumlahTotal: 13411069, transfer: 5320000, qris: 2400000, tunai: 2000000, kpay: 280000, jumlahSetor: 10000000, selisih: 3411069,  status: "belum-lunas" },
  { tanggal: "2026-07-15", finnet: 2426354, puskud: 12275222, jne: 50600, feeLain: 0, jumlahTotal: 14752176, transfer: 5852000, qris: 2640000, tunai: 2200000, kpay: 308000, jumlahSetor: 11000000, selisih: 3752176,  status: "belum-lunas" },
];

// ─────────────────────────────────────────────────────────────
// HELPER: Ambil data sesuai kombinasi filter aktif
// ─────────────────────────────────────────────────────────────

/**
 * getMockLaporanData(filter)
 *
 * filter = {
 *   modePeriode: "harian" | "bulanan" | "rentang",
 *   tanggal:     string (YYYY-MM-DD)  — hanya untuk harian
 *   bulan:       string (YYYY-MM)     — hanya untuk bulanan
 *   dari:        string (YYYY-MM-DD)  — hanya untuk rentang
 *   sampai:      string (YYYY-MM-DD)  — hanya untuk rentang
 *   loket:       string | "semua"
 * }
 *
 * Return:
 *   { skenario: 1|2|3|4|5|6, rows: [...], detailExpand: {...}|null }
 */
export function getMockLaporanData(filter) {
  const { modePeriode, loket } = filter;
  const semuaLoket = !loket || loket === "semua";

  if (modePeriode === "harian") {
    if (semuaLoket) {
      return { skenario: 3, rows: dataSkenario3HarianSemuaLoket, detailExpand: null };
    } else {
      return { skenario: 4, rows: [], detailExpand: dataSkenario4HarianSatuLoket };
    }
  }

  if (modePeriode === "bulanan") {
    if (semuaLoket) {
      return { skenario: 1, rows: dataSkenario1BulananSemuaLoket, detailExpand: null };
    } else {
      return { skenario: 2, rows: dataSkenario2BulananSatuLoket, detailExpand: null };
    }
  }

  if (modePeriode === "rentang") {
    if (semuaLoket) {
      return { skenario: 5, rows: dataSkenario5RentangSemuaLoket, detailExpand: null };
    } else {
      return { skenario: 6, rows: dataSkenario6RentangSatuLoket, detailExpand: null };
    }
  }

  // Fallback ke skenario 3
  return { skenario: 3, rows: dataSkenario3HarianSemuaLoket, detailExpand: null };
}