import { useState, useEffect } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import OptionCards from "@/components/shared/OptionCards";
import InfoTransaksi from "@/components/shared/InfoTransaksi";
import VendorTable from "@/components/shared/VendorTable";
import RincianSetoran from "@/components/shared/RincianSetoran";
import RingkasanOtomatis from "@/components/shared/RingkasanOtomatis";
import PelunasanContent from "@/components/shared/PelunasanContent";
import StatusBar from "@/components/layout/StatusBar";
import Toast from "@/components/ui/Toast";
import { IconSave, IconSend, IconSpinner } from "@/components/ui/Icon";
import useInputSetoran from "@/hooks/useInputSetoran";
import { buildSetoranFormData } from "@/utils/buildSetoranFormData";

/**
 * Validasi dasar sebelum kirim ke server — dicek di FE dulu supaya teller
 * dapat feedback instan (bukan nunggu round-trip ke server buat tahu ada
 * baris yang belum lengkap), dan supaya tidak keluar 422 mentah dari BE
 * tanpa pesan yang jelas. Aturan mengikuti kontrak API Card A §0.6/§0.7:
 *  - Transfer/QRIS/Tunai: baris yang SUDAH MULAI DIISI (nominal atau bukti
 *    terisi salah satu) wajib LENGKAP (nominal > 0 DAN bukti ada). Baris
 *    yang MASIH KOSONG TOTAL (nominal maupun bukti) dianggap "belum
 *    dipakai" teller, BUKAN error — soalnya Transfer/Tunai selalu punya
 *    1 baris default kosong dari awal (lihat RincianSetoran.jsx), dan
 *    tidak semua loket transaksi Transfer/QRIS/Tunai setiap hari.
 *  - KPay: sama polanya — baris kosong total dilewati, baris yang sudah
 *    mulai diisi wajib nominal + nama + identitas karyawan lengkap. KPay
 *    TIDAK PERNAH boleh ada bukti foto (field itu memang tidak ditawarkan
 *    sama sekali di form, lihat KpayRow.jsx).
 *  - Total keseluruhan (semua metode) tidak boleh kosong semua — minimal
 *    1 transaksi harus tercatat sebelum submit (server juga menolak
 *    entries: [] saat submit, lihat kontrak §3).
 * Mengembalikan array pesan error (kosong = valid).
 */
function validasiRincian(rincian) {
  const errors = [];

  const barisKosongTotal = (r) => !r.nominal && !r.bukti;

  const cekWajibBukti = (rows, label) => {
    rows.forEach((r, i) => {
      if (barisKosongTotal(r)) return; // belum disentuh sama sekali, lewati
      if (!r.nominal || Number(r.nominal) <= 0) {
        errors.push(`${label} baris ke-${i + 1}: nominal wajib diisi.`);
      }
      if (!r.bukti) {
        errors.push(`${label} baris ke-${i + 1}: bukti wajib diunggah.`);
      }
    });
  };

  cekWajibBukti(rincian.transfer, "Transfer");
  cekWajibBukti(rincian.qris, "QRIS");
  cekWajibBukti(rincian.tunai, "Tunai");

  rincian.kpay.forEach((r, i) => {
    const kpayKosongTotal = !r.nominal && !r.nama && !r.nik;
    if (kpayKosongTotal) return; // belum disentuh sama sekali, lewati
    if (!r.nominal || Number(r.nominal) <= 0) {
      errors.push(`KPay baris ke-${i + 1}: nominal wajib diisi.`);
    }
    if (!r.nama) {
      errors.push(`KPay baris ke-${i + 1}: nama karyawan wajib diisi.`);
    }
    if (!r.nik) {
      errors.push(`KPay baris ke-${i + 1}: NIK/identitas karyawan wajib diisi.`);
    }
  });

  // Kalau tidak ada satu pun error di atas, tapi ternyata SEMUA baris di
  // semua metode kosong total (belum ada transaksi tercatat sama sekali),
  // tetap tolak — submit kosong tidak masuk akal secara bisnis, dan server
  // juga menolaknya (entries wajib minimal 1 baris saat submit, §3).
  const semuaRows = [...rincian.transfer, ...rincian.qris, ...rincian.tunai, ...rincian.kpay];
  const adaTransaksiTerisi = semuaRows.some((r) => r.nominal && Number(r.nominal) > 0);
  if (errors.length === 0 && !adaTransaksiTerisi) {
    errors.push("Isi minimal satu transaksi (Transfer, QRIS, Tunai, atau KPay) sebelum submit.");
  }

  return errors;
}

/**
 * Kontrak API §0.1: errors bisa 2 bentuk berbeda tergantung sumbernya —
 * per-field (dari $request->validate()) atau kosong dengan detail di
 * message (dari business-rule check manual, mis. 409 lock, id duplikat).
 * Fungsi ini menyeragamkan keduanya jadi array pesan siap tampil.
 */
function uraikanErrorApi(err) {
  const status = err?.response?.status;
  const data = err?.response?.data;

  if (status === 429) {
    return [data?.message || "Tunggu sebentar sebelum menyimpan lagi."];
  }

  if (status === 409) {
    return [data?.message || "Setoran tanggal ini sudah disubmit dan tidak bisa diubah lagi lewat halaman ini."];
  }

  if (!data) {
    return ["Terjadi kesalahan. Periksa koneksi Anda dan coba lagi."];
  }

  const errorsObj = data.errors;
  if (errorsObj && Object.keys(errorsObj).length > 0) {
    // errors terisi per-field — kumpulkan semua pesan dari tiap field
    return Object.values(errorsObj).flat();
  }

  // errors kosong ({}) — business-rule error, detail lengkap ada di message
  return [data.message || "Terjadi kesalahan. Coba lagi."];
}

/**
 * Menerjemahkan entries[] dari respons API (GET /teller/setoran atau hasil
 * saveDraft/submit) jadi bentuk { transfer, qris, tunai, kpay } yang
 * dipakai RincianSetoran.jsx. Kebalikan dari buildSetoranFormData.
 *
 * entryId disimpan terpisah dari id (key lokal React) — lihat penjelasan
 * lengkap di buildSetoranFormData.js.
 */
function entriesKeRincian(entries) {
  const rincian = { transfer: [], qris: [], tunai: [], kpay: [] };
  for (const entry of entries ?? []) {
    const baris = {
      id: entry.id,
      entryId: entry.id,
      keterangan: entry.keterangan ?? "",
      nominal: entry.nominal,
      bukti: entry.foto_bukti_url, // string URL foto lama (bukan File) — komponen bukti perlu bisa preview dari URL
      verified: entry.is_konfirmasi,
    };
    if (entry.metode_pembayaran === "kpay") {
      baris.nama = entry.nama_karyawan;
      baris.nik = entry.identitas_karyawan;
      rincian.kpay.push(baris);
    } else if (rincian[entry.metode_pembayaran]) {
      rincian[entry.metode_pembayaran].push(baris);
    }
  }
  return rincian;
}

/**
 * Halaman Input Setoran untuk teller. Dipanggil dari routing di App.jsx:
 *   <Route path="/teller/input" element={<InputSetoran />} />
 *
 * Konten di bawah OptionCards BERGANTI TOTAL tergantung mode:
 * - "hari-ini"   -> InfoTransaksi + VendorTable + RincianSetoran + RingkasanOtomatis
 * - "pelunasan"  -> PelunasanContent (form pelunasan selisih setoran lama)
 *
 * Alur data (kontrak API Card A):
 * 1. Saat halaman dibuka, fetchForm() -> GET /teller/setoran, mengambil
 *    data pendapatan SOPP hari ini + entries yang sudah pernah tersimpan
 *    (draft sebelumnya, kalau ada) + status lock.
 * 2. Kalau ada entries tersimpan (dan bukan datang dari alur "lanjutkan
 *    draft" via Riwayat Setoran), form otomatis diisi ulang dari situ.
 * 3. Simpan Draft / Submit -> rincian (state lokal) diterjemahkan jadi
 *    FormData lewat buildSetoranFormData(), lalu dikirim. Respons berisi
 *    state terbaru (sama shape dengan GET), disinkronkan lagi ke formData.
 */
export default function InputSetoran() {
  const navigate = useNavigate();
  const location = useLocation();

  // Kalau user datang dari klik "Detail" di baris draft Riwayat Setoran,
  // location.state.draftData berisi data draft tersebut — ini prioritas
  // lebih tinggi dari entries hasil fetchForm() otomatis (lihat useEffect
  // pengisian ulang form di bawah).
  const draftData = location.state?.draftData ?? null;
  const isEditDraft = draftData !== null;

  const [mode, setMode] = useState("hari-ini");
  const [totalSetor, setTotalSetor] = useState(draftData?.totalSetor ?? 0);
  const [rincian, setRincian] = useState(draftData?.rincian ?? { transfer: [], qris: [], tunai: [], kpay: [] });
  const [errors, setErrors] = useState([]);
  const [toastMessage, setToastMessage] = useState(null);
  const [formData, setFormData] = useState(null); // hasil GET /teller/setoran
  const [loadingForm, setLoadingForm] = useState(true);

  /** "2026-07-24" (local) — dipakai sebagai default tanggal aktif. */
function todayISO() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

  const [tanggal, setTanggal] = useState(draftData?.tanggal ?? todayISO());
  const [kalenderMap, setKalenderMap] = useState({});

  const { loading, saveDraft, submit, fetchForm, fetchKalender } = useInputSetoran();

  // Ambil state form dari server tiap kali tanggal aktif berganti.
  useEffect(() => {
    async function muatForm() {
      setLoadingForm(true);
      try {
        const data = await fetchForm({ tanggal }); // kirim tanggal terpilih
        setFormData(data);
      } catch (err) {
        setErrors(uraikanErrorApi(err));
      } finally {
        setLoadingForm(false);
      }
    }
    muatForm();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tanggal]);

  // Ambil status kalender untuk bulan yang sedang ditampilkan di picker.
  const handleViewChange = async (bulanStr) => {
    try {
      const data = await fetchKalender({ bulan: bulanStr });
      const map = {};
      for (const item of data.kalender) {
        map[item.tanggal] = { status: item.status, bisa_diinput: item.bisa_diinput };
      }
      setKalenderMap(map);
    } catch {
      // Kalender gagal dimuat bukan blocker untuk input setoran itu sendiri
      // (form tetap bisa dipakai) — cukup silent-fail, tidak perlu tampil
      // sebagai error blocking di halaman.
    }
  };

  // Kalau tidak datang dari Riwayat Setoran (draftData kosong), tapi server
  // ternyata sudah punya entries tersimpan (draft sebelumnya untuk hari
  // ini), isi ulang form otomatis begitu formData selesai dimuat.
  useEffect(() => {
    if (!isEditDraft && formData?.entries?.length > 0) {
      setRincian(entriesKeRincian(formData.entries));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [formData]);

  const pendapatanGagalDimuat = formData?.pendapatan_sopp_status === "gagal_dimuat";
  const totalPendapatanSOPP = formData?.total_pendapatan ?? null;
  const isLocked = formData?.is_locked === true;

  // Selisih dihitung FE cuma untuk preview live selama teller mengetik —
  // angka RESMI tetap dari respons BE (field selisih di GET/draft/submit),
  // karena BE menghitungnya dari total_setor_loket gabungan semua teller
  // yang submitted, bukan dari totalSetor personal yang sedang diketik di
  // form ini (kontrak §1 business-logic notes).
  const selisih = totalPendapatanSOPP !== null ? totalPendapatanSOPP - totalSetor : null;

  const handleSimpanDraft = async () => {
    setErrors([]);
    try {
      const payload = buildSetoranFormData(rincian);
      const hasil = await saveDraft(payload);
      setFormData(hasil);
      setToastMessage("Draft berhasil disimpan.");
    } catch (err) {
      setErrors(uraikanErrorApi(err));
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };

  const handleSubmit = async () => {
    const validasiErrors = validasiRincian(rincian);
    if (validasiErrors.length > 0) {
      setErrors(validasiErrors);
      window.scrollTo({ top: 0, behavior: "smooth" });
      return;
    }
    setErrors([]);
    try {
      const payload = buildSetoranFormData(rincian);
      const hasil = await submit(payload);
      setFormData(hasil);
      setToastMessage("Setoran berhasil disubmit.");
      setTimeout(() => navigate("/teller/dashboard"), 1200);
    } catch (err) {
      setErrors(uraikanErrorApi(err));
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };

  return (
    <>
      <div className="flex flex-col gap-0.5">
        <h1 className="font-semibold text-[#191c20] text-2xl tracking-tight">Input Setoran</h1>
        <p className="text-[#424751] text-sm">Pilih jenis transaksi yang akan dicatat</p>
      </div>

      {/* Option cards — selalu tampil di atas, apa pun mode-nya */}
      <OptionCards mode={mode} onModeChange={setMode} tanggal={tanggal} />

      {mode === "hari-ini" ? (
        <>
          {/* Banner info kalau sedang mengedit draft — tampil hanya kalau
              user datang dari klik detail baris draft di Riwayat Setoran */}
          {isEditDraft && (
            <div className="bg-[#fff8e1] border border-[#f0c040] rounded-lg px-5 py-4 flex items-start gap-3">
              <span className="text-[#b45309] text-lg leading-none mt-0.5">📝</span>
              <div>
                <p className="text-[#92400e] text-sm font-semibold">Melanjutkan draft</p>
                <p className="text-[#92400e] text-sm mt-0.5">
                  Kamu sedang mengedit draft setoran tanggal{" "}
                  <span className="font-medium">{draftData.tanggal}</span>.
                  Lengkapi data yang kurang, lalu klik Submit untuk mengirimkan.
                </p>
              </div>
            </div>
          )}

          {/* Banner lock — tampil kalau setoran tanggal ini sudah submitted
              (kontrak §0.5). Mencegah teller coba simpan/submit ulang yang
              pasti akan ditolak 409 oleh server. */}
          {isLocked && (
            <div className="bg-[#efefed] border border-[#d9d9d6] rounded-lg px-5 py-4">
              <p className="text-[#424751] text-sm font-semibold">
                Setoran tanggal ini sudah disubmit dan terkunci.
              </p>
              <p className="text-[#727782] text-sm mt-0.5">
                Perubahan lebih lanjut bisa dilakukan lewat halaman Detail Setoran.
              </p>
            </div>
          )}

          {loadingForm ? (
            <div className="flex items-center justify-center gap-2 py-16 text-[#727782] text-sm">
              <IconSpinner className="size-5" />
              <span>Memuat data setoran...</span>
            </div>
          ) : (
            <>
              <InfoTransaksi 
                tanggal={tanggal}
                onTanggalChange={setTanggal}
                statusTanggal={kalenderMap[tanggal]?.status === "submitted" ? "terkunci" : null}
                kalenderMap={kalenderMap}
                onViewChange={handleViewChange}
              />
              <VendorTable
                rows={formData?.data_pendapatan_sopp}
                total={totalPendapatanSOPP}
                gagalDimuat={pendapatanGagalDimuat}
              />
              <RincianSetoran
                initialData={isEditDraft ? rincian : undefined}
                onTotalChange={setTotalSetor}
                onRowsChange={setRincian}
              />
              <RingkasanOtomatis totalSetor={totalSetor} totalPendapatanSOPP={totalPendapatanSOPP} selisih={selisih} />

              {errors.length > 0 && (
                <div className="bg-[#ffdad6] border border-[#ba1a1a]/40 rounded-lg px-5 py-4">
                  <p className="text-[#93000a] text-sm font-semibold mb-1.5">
                    Periksa kembali data sebelum submit:
                  </p>
                  <ul className="list-disc pl-5 space-y-0.5">
                    {errors.map((msg, i) => (
                      <li key={i} className="text-[#93000a] text-sm">{msg}</li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="flex gap-4 items-center justify-end pt-2 pb-4">
                <button
                  onClick={handleSimpanDraft}
                  disabled={loading || isLocked}
                  className="relative bg-[#efefed] border border-[#d9d9d6] flex gap-2 items-center px-8 py-4 rounded hover:bg-[#e5e5e3] disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-[#424751] text-base"
                >
                  {loading ? <IconSpinner className="size-5" /> : <IconSave />}
                  <span>Simpan Draft</span>
                </button>
                <button
                  onClick={handleSubmit}
                  disabled={loading || isLocked}
                  className="bg-[#710014] flex gap-2 items-center px-8 py-4 rounded shadow-lg hover:bg-[#5a000f] disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-white text-base"
                >
                  {loading ? <IconSpinner className="size-5" /> : <IconSend />}
                  <span>Submit</span>
                </button>
              </div>
            </>
          )}
        </>
      ) : (
        <PelunasanContent onBatal={() => setMode("hari-ini")} />
      )}

      <StatusBar />

      {toastMessage && <Toast message={toastMessage} onClose={() => setToastMessage(null)} />}
    </>
  );
}