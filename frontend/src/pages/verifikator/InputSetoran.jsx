import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { useAuthStore } from "@/store/authStore";
import OptionCards from "@/components/shared/OptionCards";
import InfoTransaksi from "@/components/shared/InfoTransaksi";
import VendorTable from "@/components/shared/VendorTable";
import RincianSetoran from "@/components/shared/RincianSetoran";
import RingkasanOtomatis from "@/components/shared/RingkasanOtomatis";
import PelunasanContent from "@/components/shared/PelunasanContent";
import Toast from "@/components/ui/Toast";
import { IconSave, IconSend, IconSpinner, IconLock } from "@/components/ui/Icon";
import useInputSetoran from "@/hooks/useInputSetoran";
import { buildSetoranFormData } from "@/utils/buildSetoranFormData";
import { getLokets } from "@/api/teller";

function validasiRincian(rincian) {
  const errors = [];

  const barisKosongTotal = (r) => !r.nominal && !r.bukti;

  const cekWajibBukti = (rows, label) => {
    rows.forEach((r, i) => {
      if (barisKosongTotal(r)) return;
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
    if (kpayKosongTotal) return;
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

  const semuaRows = [...rincian.transfer, ...rincian.qris, ...rincian.tunai, ...rincian.kpay];
  const adaTransaksiTerisi = semuaRows.some((r) => r.nominal && Number(r.nominal) > 0);
  if (errors.length === 0 && !adaTransaksiTerisi) {
    errors.push("Isi minimal satu transaksi (Transfer, QRIS, Tunai, atau KPay) sebelum submit.");
  }

  return errors;
}

/** Sama seperti di teller/InputSetoran.jsx — lihat komentar lengkap di sana. */
function uraikanErrorApi(err) {
  const status = err?.response?.status;
  const data = err?.response?.data;

  if (status === 403) {
    return [data?.message || "Anda tidak memiliki akses untuk melakukan aksi ini."];
  }
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
    return Object.values(errorsObj).flat();
  }
  return [data.message || "Terjadi kesalahan. Coba lagi."];
}

/** Sama seperti di teller/InputSetoran.jsx — lihat komentar lengkap di sana. */
function entriesKeRincian(entries) {
  const rincian = { transfer: [], qris: [], tunai: [], kpay: [] };
  for (const entry of entries ?? []) {
    const baris = {
      id: entry.id,
      entryId: entry.id,
      keterangan: entry.keterangan ?? "",
      nominal: entry.nominal,
      bukti: entry.foto_bukti_url,
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

function todayISO() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

const RINCIAN_KOSONG = { transfer: [], qris: [], tunai: [], kpay: [] };

/**
 * Halaman Input Setoran khusus Verifikator. Route (di App.jsx):
 *   <Route path="/verifikator/input-setoran" element={<InputSetoranKeuangan />} />
 *
 * BEDA UTAMA dari teller/InputSetoran.jsx: verifikator TIDAK terikat ke 1
 * loket (tidak seperti teller, yang loket_id-nya otomatis kebaca dari sesi
 * login). Verifikator memilih loket lewat dropdown "Pilih Cabang", dan
 * `loket_id` hasil pilihan itu WAJIB ikut dikirim eksplisit di setiap
 * request ke Card A (GET/POST/kalender) — lihat kontrak API §0.2.
 *
 * Dua lapis penjagaan akses:
 * 1. RoleGuard di App.jsx — memastikan yang bisa membuka route ini cuma
 *    yang memiliki kapabilitas verifikator, lihat App.jsx.
 * 2. Kapabilitas bisa_input_setoran_semua_loket — dicek DI DALAM halaman
 *    ini sendiri (bukan RoleGuard), karena ini bukan soal "role" tapi soal
 *    kapabilitas granular: seorang verifikator BISA SAJA belum di-grant
 *    kapabilitas ini oleh admin lewat Hak Akses (defaultnya false, tidak
 *    otomatis ikut status verifikator ataupun is_superadmin — lihat
 *    kontrak §0.2 & Bagian 1.A diskusi Rev3). Kalau tidak dicek di sini,
 *    dropdown akan tampil normal tapi SETIAP request ke Card A bakal
 *    ditolak 403 oleh BE begitu loket_id dikirim — mending dicegat di FE
 *    dengan pesan yang jelas daripada user bingung kenapa selalu gagal.
 */
export default function InputSetoranKeuangan() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const bolehInputLoketLain = user?.bisa_input_setoran_semua_loket === true;

  // ---- Daftar loket (untuk dropdown "Pilih Cabang") ----
  const [daftarLoket, setDaftarLoket] = useState([]);
  const [loadingLoket, setLoadingLoket] = useState(true);
  const [errorLoket, setErrorLoket] = useState(null);

  useEffect(() => {
    if (!bolehInputLoketLain) {
      setLoadingLoket(false);
      return;
    }
    let batal = false;
    async function muatDaftarLoket() {
      setLoadingLoket(true);
      setErrorLoket(null);
      try {
        const res = await getLokets();
        if (batal) return;
        if (res?.status === "ok") setDaftarLoket(res.data ?? []);
        else setErrorLoket("Gagal memuat daftar loket.");
      } catch {
        if (!batal) setErrorLoket("Gagal memuat daftar loket. Periksa koneksi dan coba lagi.");
      } finally {
        if (!batal) setLoadingLoket(false);
      }
    }
    muatDaftarLoket();
    return () => { batal = true; };
  }, [bolehInputLoketLain]);

  // loketId: number|null — null berarti "belum pilih cabang", form disembunyikan.
  const [loketId, setLoketId] = useState(null);
  const loketTerpilih = daftarLoket.find((l) => l.loket_id === loketId) ?? null;

  const [mode, setMode] = useState("hari-ini");
  const [tanggal, setTanggal] = useState(todayISO());
  const [totalSetor, setTotalSetor] = useState(0);
  const [rincian, setRincian] = useState(RINCIAN_KOSONG);
  const [errors, setErrors] = useState([]);
  const [toastMessage, setToastMessage] = useState(null);
  // Waktu "Simpan Draft" terakhir yang BERHASIL di sesi ini. Bukan auto-save (aplikasi
  // ini tidak punya auto-save), jadi hilang saat halaman di-refresh.
  const [draftDisimpan, setDraftDisimpan] = useState(null);
  const [formData, setFormData] = useState(null);
  const [loadingForm, setLoadingForm] = useState(false);
  const [kalenderMap, setKalenderMap] = useState({});

  const { loading, saveDraft, submit, fetchForm, fetchKalender } = useInputSetoran();

  // Begitu verifikator GANTI CABANG, seluruh state form harus direset —
  // ini form untuk loket yang sama sekali beda, bukan lanjutan dari yang
  // sebelumnya. Tanpa reset ini, sisa rincian/errors loket lama bisa
  // "nyangkut" dan salah kekirim ke loket yang baru dipilih.
  useEffect(() => {
    setMode("hari-ini");
    setTanggal(todayISO());
    setTotalSetor(0);
    setRincian(RINCIAN_KOSONG);
    setErrors([]);
    setFormData(null);
    setKalenderMap({});
  }, [loketId]);

  // Ambil state form dari server tiap kali cabang ATAU tanggal berganti.
  // loket_id WAJIB disertakan di sini (kontrak §0.2) — beda dari
  // teller/InputSetoran.jsx yang tidak pernah mengirim param ini sama
  // sekali.
  useEffect(() => {
    if (loketId == null) return;
    let batal = false;
    async function muatForm() {
      setLoadingForm(true);
      try {
        const data = await fetchForm({ loket_id: loketId, tanggal });
        if (!batal) setFormData(data);
      } catch (err) {
        if (!batal) setErrors(uraikanErrorApi(err));
      } finally {
        if (!batal) setLoadingForm(false);
      }
    }
    muatForm();
    return () => { batal = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loketId, tanggal]);

  // Isi ulang form dari entries tersimpan (draft sebelumnya untuk
  // loket+tanggal ini) begitu formData selesai dimuat.
  useEffect(() => {
    if (formData?.entries?.length > 0) {
      setRincian(entriesKeRincian(formData.entries));
    } else {
      setRincian(RINCIAN_KOSONG);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [formData]);

  const handleViewChange = async (bulanStr) => {
    if (loketId == null) return;
    try {
      const data = await fetchKalender({ loket_id: loketId, bulan: bulanStr });
      const map = {};
      for (const item of data.kalender) {
        map[item.tanggal] = { status: item.status, bisa_diinput: item.bisa_diinput };
      }
      setKalenderMap(map);
    } catch {
      // Kalender gagal dimuat bukan blocker untuk input setoran itu sendiri.
    }
  };

  const pendapatanGagalDimuat = formData?.pendapatan_sopp_status === "gagal_dimuat";
  const totalPendapatanSOPP = formData?.total_pendapatan ?? null;
  const isLocked = formData?.is_locked === true;

  const selisih = totalPendapatanSOPP !== null ? totalPendapatanSOPP - totalSetor : null;

  const handleSimpanDraft = async () => {
    setErrors([]);
    try {
      const payload = buildSetoranFormData(rincian, { loketId, tanggal });
      const hasil = await saveDraft(payload);
      setFormData(hasil);
      setToastMessage(`Draft untuk ${loketTerpilih?.nama_loket ?? "loket ini"} berhasil disimpan.`);
      setDraftDisimpan({ waktu: new Date(), kunci: `${loketId ?? ""}-${tanggal}` });
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
      const payload = buildSetoranFormData(rincian, { loketId, tanggal });
      const hasil = await submit(payload);
      setFormData(hasil);
      setToastMessage(`Setoran ${loketTerpilih?.nama_loket ?? ""} berhasil disubmit.`);
      setTimeout(() => navigate("/verifikator/dashboard"), 1200);
    } catch (err) {
      setErrors(uraikanErrorApi(err));
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };

  // ---- Gate kapabilitas: tampil SEBELUM dropdown/form apa pun ----
  // Sengaja dicek di sini (bukan cuma mengandalkan RoleGuard di App.jsx),
  // karena ini bukan soal role tapi soal kapabilitas granular yang bisa
  // saja belum di-grant lewat Hak Akses — lihat komentar di atas fungsi.
  if (!bolehInputLoketLain) {
    return (
      <div className="bg-white rounded-lg border border-[#d9d9d6] py-16 px-6 flex flex-col items-center text-center gap-3">
        <div className="bg-[#efefed] rounded-full size-14 flex items-center justify-center">
          <IconLock className="size-6" color="#727782" />
        </div>
        <div>
          <p className="text-[#191c20] text-base font-semibold">Belum Memiliki Akses</p>
          <p className="text-[#727782] text-sm mt-1 max-w-md">
            Akun Anda belum diberi izin untuk menginput setoran atas nama loket lain.
            Hubungi Super Admin untuk mengaktifkan kapabilitas ini lewat halaman Hak Akses.
          </p>
        </div>
      </div>
    );
  }

  return (
    <>
      <div className="flex flex-col gap-0.5">
        <h1 className="font-semibold text-[#191c20] text-2xl tracking-tight">Input Setoran</h1>
        <p className="text-[#424751] text-sm">Pilih cabang, lalu jenis transaksi yang akan dicatat</p>
      </div>

      <div className="bg-white rounded border border-[#d9d9d6] w-full p-4">
        <label className="font-semibold text-[#424751] text-xs block mb-1.5">
          Pilih Cabang
        </label>

        {errorLoket && (
          <p className="text-[#93000a] text-xs font-medium mb-2">{errorLoket}</p>
        )}

        <select
          value={loketId ?? ""}
          onChange={(e) => setLoketId(e.target.value ? Number(e.target.value) : null)}
          disabled={loadingLoket || !!errorLoket}
          className="w-full sm:w-80 border border-[#d9d9d6] rounded px-3 py-2.5 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <option value="">
            {loadingLoket ? "Memuat daftar loket..." : "— Pilih SOPP Cabang —"}
          </option>
          {daftarLoket.map((l) => (
            <option key={l.loket_id} value={l.loket_id}>{l.nama_loket}</option>
          ))}
        </select>
      </div>

      {loketId == null ? (
        <div className="bg-[#f7f7f6] border border-dashed border-[#d9d9d6] rounded-lg py-12 text-center">
          <p className="text-[#727782] text-sm">Pilih cabang terlebih dahulu untuk mulai input setoran.</p>
        </div>
      ) : (
        <>
          <OptionCards mode={mode} onModeChange={setMode} tanggal={tanggal} />

          {mode === "hari-ini" ? (
            <>
              {isLocked && (
                <div className="bg-[#efefed] border border-[#d9d9d6] rounded-lg px-5 py-4">
                  <p className="text-[#424751] text-sm font-semibold">
                    Setoran {loketTerpilih?.nama_loket} tanggal ini sudah disubmit dan terkunci.
                  </p>
                  <p className="text-[#727782] text-sm mt-0.5">
                    Perubahan lebih lanjut bisa dilakukan lewat halaman Detail Setoran.
                  </p>
                </div>
              )}

              {loadingForm ? (
                <div className="flex items-center justify-center gap-2 py-16 text-[#727782] text-sm">
                  <IconSpinner className="size-5" />
                  <span>Memuat data setoran {loketTerpilih?.nama_loket}...</span>
                </div>
              ) : (
                <>
                  <InfoTransaksi
                    loket={loketTerpilih?.nama_loket}
                    tanggal={tanggal}
                    onTanggalChange={setTanggal}
                    statusTanggal={kalenderMap[tanggal]?.status === "submitted" ? "terkunci" : null}
                    kalenderMap={kalenderMap}
                    onViewChange={handleViewChange}
                    labelPengisi="Diinput Oleh"
                    namaPengisi={user.name}
                  />
                  <VendorTable
                    rows={formData?.data_pendapatan_sopp}
                    total={totalPendapatanSOPP}
                    gagalDimuat={pendapatanGagalDimuat}
                  />
                  {/* key берет loketId+tanggal supaya RincianSetoran benar-benar
                      REMOUNT (state internalnya reset total) tiap ganti cabang
                      atau tanggal — komponen ini cuma membaca initialData SEKALI
                      lewat useState initializer, tidak otomatis sinkron kalau
                      prop-nya berubah setelah mount pertama. */}
                  <RincianSetoran
                    key={`${loketId}-${tanggal}`}
                    initialData={rincian}
                    onTotalChange={setTotalSetor}
                    onRowsChange={setRincian}
                    readOnly={isLocked}
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
            <PelunasanContent key={loketId} loketId={loketId} onBatal={() => setMode("hari-ini")} />
          )}
        </>
      )}

      {mode === "hari-ini" && draftDisimpan?.kunci === `${loketId ?? ""}-${tanggal}` && (
        <p className="text-[#727782] text-xs text-right pb-2">
          Draft terakhir disimpan pukul{" "}
          {draftDisimpan.waktu.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}
        </p>
      )}

      {toastMessage && <Toast message={toastMessage} onClose={() => setToastMessage(null)} />}
    </>
  );
}