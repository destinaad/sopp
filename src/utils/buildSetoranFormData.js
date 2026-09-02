/**
 * Menerjemahkan state form Input Setoran — bentuk { transfer, qris, tunai,
 * kpay } yang dipakai RincianSetoran.jsx — jadi FormData sesuai kontrak
 * API Card A §2/§3 (entries[] flat, field metode_pembayaran/nominal/dst).
 *
 * Kenapa perlu file terpisah (bukan langsung di InputSetoran.jsx):
 * supaya kalau nanti aturan kontrak berubah, cukup ubah di SATU tempat
 * ini — komponen tampilan (RincianSetoran, RincianSection, KpayRow)
 * TIDAK PERLU disentuh sama sekali karena mereka tetap pakai nama field
 * lokal yang sudah ada (bukti, nik, verified, dst).
 *
 * @param {object} rincian - { transfer: [], qris: [], tunai: [], kpay: [] }
 * @param {object} opsi - { loketId?: number, tanggal?: string }
 * @returns {FormData}
 */
export function buildSetoranFormData(rincian, opsi = {}) {
  const formData = new FormData();

  if (opsi.loketId) formData.append("loket_id", opsi.loketId);
  if (opsi.tanggal) formData.append("tanggal", opsi.tanggal);

  // Gabungkan 4 kelompok jadi 1 array flat, sambil "menerjemahkan" nama
  // field dan metode_pembayaran masing-masing.
  const semuaBaris = [
    ...rincian.transfer.map((r) => ({ ...r, metode_pembayaran: "transfer" })),
    ...rincian.qris.map((r) => ({ ...r, metode_pembayaran: "qris" })),
    ...rincian.tunai.map((r) => ({ ...r, metode_pembayaran: "tunai" })),
    ...rincian.kpay.map((r) => ({ ...r, metode_pembayaran: "kpay" })),
  ];

  // §0.4 — baris yang KOSONG TOTAL (belum pernah disentuh teller) tidak
  // ikut dikirim sama sekali — bukan cuma untuk validasi FE, tapi juga
  // supaya tidak jadi baris "hantu" di payload API. Baris yang teller
  // hapus di form (lewat tombol Hapus) otomatis sudah tidak ada lagi di
  // `rincian` sejak awal, jadi delete-by-omission (§0.4) otomatis
  // terjadi tanpa perlu logic tambahan di sini.
  const barisTerisi = semuaBaris.filter((r) => {
    if (r.metode_pembayaran === "kpay") {
      return r.nominal || r.nama || r.nik;
    }
    return r.nominal || r.bukti;
  });

  let index = 0;
  for (const baris of barisTerisi) {
    const prefix = `entries[${index}]`;

    // id numerik asli dari server (baris lama) — baris baru dari
    // RincianSetoran pakai Date.now() sebagai id lokal (BUKAN id server),
    // jadi HARUS dibedakan supaya tidak ketuker dikirim sebagai id asli.
    // Konvensi: id server disimpan di r.entryId, sedangkan r.id murni
    // key lokal React (lihat entriesKeRincian di InputSetoran.jsx).
    if (baris.entryId) {
      formData.append(`${prefix}[id]`, baris.entryId);
    }

    formData.append(`${prefix}[metode_pembayaran]`, baris.metode_pembayaran);
    formData.append(`${prefix}[nominal]`, baris.nominal || 0);

    if (baris.keterangan) {
      formData.append(`${prefix}[keterangan]`, baris.keterangan);
    }

    formData.append(`${prefix}[is_konfirmasi]`, baris.verified ? "1" : "0");

    // §0.6 — foto_bukti: kpay DILARANG kirim foto sama sekali; metode lain
    // cuma dikirim kalau teller baru pilih/ganti file (baris.bukti berupa
    // File object baru). Kalau baris.bukti masih string URL lama (foto
    // yang sudah ada dari server), TIDAK dikirim ulang — itu sinyal ke BE
    // "pertahankan foto lama", sesuai aturan opsional di §0.6.
    if (baris.metode_pembayaran !== "kpay" && baris.bukti instanceof File) {
      formData.append(`${prefix}[foto_bukti]`, baris.bukti);
    }

    // §0.7 — nama_karyawan/identitas_karyawan CUMA untuk kpay. Untuk
    // metode lain, field ini WAJIB TIDAK IKUT TERKIRIM SAMA SEKALI (bukan
    // dikirim kosong) — makanya taruh di dalam if, bukan di luar.
    if (baris.metode_pembayaran === "kpay") {
      formData.append(`${prefix}[nama_karyawan]`, baris.nama || "");
      formData.append(`${prefix}[identitas_karyawan]`, baris.nik || "");
    }

    index += 1;
  }

  // §0.4 — entries WAJIB ada key-nya walau kosong (draft boleh kirim array
  // kosong). Kalau tidak ada satu pun baris terisi, FormData butuh
  // penanda eksplisit supaya BE tidak menganggap key "entries" tidak
  // dikirim sama sekali.
  if (barisTerisi.length === 0) {
    formData.append("entries", "[]");
  }

  return formData;
}