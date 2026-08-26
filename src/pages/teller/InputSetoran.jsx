import { useState } from "react";
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
import { getTotalPendapatanSOPP } from "@/mocks/setoran";
import useInputSetoran from "@/hooks/useInputSetoran";

/**
 * Validasi dasar sebelum kirim ke server — dicek di FE dulu supaya teller
 * dapat feedback instan (bukan nunggu round-trip ke server buat tahu ada
 * baris yang belum lengkap), dan supaya tidak keluar 422 mentah dari BE
 * tanpa pesan yang jelas. Aturan mengikuti kontrak API §7.2:
 *  - Transfer/QRIS/Tunai: baris yang SUDAH MULAI DIISI (nominal atau bukti
 *    terisi salah satu) wajib LENGKAP (nominal > 0 DAN bukti ada). Baris
 *    yang MASIH KOSONG TOTAL (nominal maupun bukti) dianggap "belum
 *    dipakai" teller, BUKAN error — soalnya Transfer/Tunai selalu punya
 *    1 baris default kosong dari awal (lihat RincianSetoran.jsx), dan
 *    tidak semua loket transaksi Transfer/QRIS/Tunai setiap hari.
 *  - KPay: sama polanya — baris kosong total dilewati, baris yang sudah
 *    mulai diisi wajib nominal + nama + NIK lengkap. KPay TIDAK PERNAH
 *    boleh ada bukti foto (field itu memang tidak ditawarkan sama sekali
 *    di form, lihat KpayRow.jsx).
 *  - Total keseluruhan (semua metode) tidak boleh kosong semua — minimal
 *    1 transaksi harus tercatat sebelum submit.
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
      errors.push(`KPay baris ke-${i + 1}: NIK karyawan wajib diisi.`);
    }
  });

  // Kalau tidak ada satu pun error di atas, tapi ternyata SEMUA baris di
  // semua metode kosong total (belum ada transaksi tercatat sama sekali),
  // tetap tolak — submit kosong tidak masuk akal secara bisnis.
  const semuaRows = [...rincian.transfer, ...rincian.qris, ...rincian.tunai, ...rincian.kpay];
  const adaTransaksiTerisi = semuaRows.some((r) => r.nominal && Number(r.nominal) > 0);
  if (errors.length === 0 && !adaTransaksiTerisi) {
    errors.push("Isi minimal satu transaksi (Transfer, QRIS, Tunai, atau KPay) sebelum submit.");
  }

  return errors;
}

/**
 * Halaman Input Setoran untuk teller. Dipanggil dari routing di App.jsx:
 *   <Route path="/teller/input" element={<InputSetoran />} />
 *
 * Konten di bawah OptionCards BERGANTI TOTAL tergantung mode:
 * - "hari-ini"   -> InfoTransaksi + VendorTable + RincianSetoran + RingkasanOtomatis
 * - "pelunasan"  -> PelunasanContent (form pelunasan selisih setoran lama)
 *
 * `totalSetor` LIVE — naik/turun otomatis mengikuti input teller di
 * RincianSetoran, lewat callback `onTotalChange`.
 * `rincian` (BARU) — seluruh isi 4 array baris (transfer/qris/tunai/kpay),
 * diterima lewat callback `onRowsChange` dari RincianSetoran. Dipakai untuk
 * menyusun payload saveDraft/submit lewat useInputSetoran.
 *
 * `saveDraft`/`submit` (dari useInputSetoran -> api/teller.js) MASIH DUMMY
 * (belum benar-benar mengirim ke server) — tapi validasi, pengumpulan data,
 * dan feedback ke teller SUDAH fungsional penuh hari ini. Begitu backend
 * siap, cukup ganti isi saveDraftSetoran()/submitSetoran() di api/teller.js
 * jadi axios call sungguhan — halaman ini tidak perlu diubah lagi.
 */
export default function InputSetoran() {
  const navigate = useNavigate();
  const location = useLocation();

  // Kalau user datang dari klik "Detail" di baris draft Riwayat Setoran,
  // location.state.draftData berisi data draft tersebut.
  // Dipakai untuk mengisi form dengan data yang sudah pernah disimpan.
  const draftData = location.state?.draftData ?? null;
  const isEditDraft = draftData !== null;

  const [mode, setMode] = useState("hari-ini");
  const [totalSetor, setTotalSetor] = useState(draftData?.totalSetor ?? 0);
  const [rincian, setRincian] = useState(draftData?.rincian ?? { transfer: [], qris: [], tunai: [], kpay: [] });
  const [errors, setErrors] = useState([]);
  const [toastMessage, setToastMessage] = useState(null);

  const { loading, saveDraft, submit } = useInputSetoran();

  const totalPendapatanSOPP = getTotalPendapatanSOPP();
  const selisih = totalPendapatanSOPP - totalSetor;

  const handleSimpanDraft = async () => {
    setErrors([]);
    try {
      await saveDraft({ rincian, totalSetor, totalPendapatanSOPP, selisih });
      setToastMessage("Draft berhasil disimpan.");
    } catch (err) {
      setErrors([err?.message || "Gagal menyimpan draft. Coba lagi."]);
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
      await submit({ rincian, totalSetor, totalPendapatanSOPP, selisih });
      setToastMessage("Setoran berhasil disubmit.");
      // Submit TIDAK mengunci entries (masih bisa diedit lewat Detail Setoran
      // nanti, sesuai kontrak API §7.2) — jadi cukup arahkan balik ke
      // dashboard, tidak perlu mengunci form di halaman ini.
      setTimeout(() => navigate("/teller/dashboard"), 1200);
    } catch (err) {
      setErrors([err?.message || "Gagal submit setoran. Coba lagi."]);
    }
  };

  return (
    <>
      <div className="flex flex-col gap-0.5">
        <h1 className="font-semibold text-[#191c20] text-2xl tracking-tight">Input Setoran</h1>
        <p className="text-[#424751] text-sm">Pilih jenis transaksi yang akan dicatat</p>
      </div>

      {/* Option cards — selalu tampil di atas, apa pun mode-nya */}
      <OptionCards mode={mode} onModeChange={setMode} />

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

          <InfoTransaksi />
          <VendorTable />
          <RincianSetoran
            initialData={isEditDraft ? rincian : undefined}
            onTotalChange={setTotalSetor}
            onRowsChange={setRincian}
          />
          <RingkasanOtomatis totalSetor={totalSetor} totalPendapatanSOPP={totalPendapatanSOPP} />

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
              disabled={loading}
              className="relative bg-[#efefed] border border-[#d9d9d6] flex gap-2 items-center px-8 py-4 rounded hover:bg-[#e5e5e3] disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-[#424751] text-base"
            >
              {loading ? <IconSpinner className="size-5" /> : <IconSave />}
              <span>Simpan Draft</span>
            </button>
            <button
              onClick={handleSubmit}
              disabled={loading}
              className="bg-[#710014] flex gap-2 items-center px-8 py-4 rounded shadow-lg hover:bg-[#5a000f] disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-white text-base"
            >
              {loading ? <IconSpinner className="size-5" /> : <IconSend />}
              <span>Submit</span>
            </button>
          </div>
        </>
      ) : (
        <PelunasanContent onBatal={() => setMode("hari-ini")} />
      )}

      <StatusBar />

      {toastMessage && <Toast message={toastMessage} onClose={() => setToastMessage(null)} />}
    </>
  );
}