import { useState, useEffect, useRef, useCallback } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import OptionCards from "@/components/shared/OptionCards";
import InfoTransaksi from "@/components/shared/InfoTransaksi";
import VendorTable from "@/components/shared/VendorTable";
import RincianSetoran from "@/components/shared/RincianSetoran";
import RingkasanOtomatis from "@/components/shared/RingkasanOtomatis";
import PelunasanContent from "@/components/shared/PelunasanContent";
import Toast from "@/components/ui/Toast";
import { IconSave, IconSend, IconSpinner } from "@/components/ui/Icon";
import useInputSetoran from "@/hooks/useInputSetoran";
import { buildSetoranFormData } from "@/utils/buildSetoranFormData";
import { getLokets } from "@/api/teller";
import { useAuthStore } from "@/store/authStore"; // sesuaikan nama export dengan store kamu

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

function validasiNominalDraft(rincian) {
  const errors = [];
  const cek = (rows, label) => {
    rows.forEach((r, i) => {
      const disentuh = r.nominal || r.bukti || r.nama || r.nik;
      if (disentuh && (!r.nominal || Number(r.nominal) <= 0)) {
        errors.push(`${label} baris ke-${i + 1}: nominal wajib diisi.`);
      }
    });
  };
  cek(rincian.transfer, "Transfer");
  cek(rincian.qris, "QRIS");
  cek(rincian.tunai, "Tunai");
  cek(rincian.kpay, "KPay");
  return errors;
}

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
    return Object.values(errorsObj).flat();
  }

  return [data.message || "Terjadi kesalahan. Coba lagi."];
}

function entriesKeRincian(entries) {
  const rincian = { transfer: [], qris: [], tunai: [], kpay: [] };
  for (const entry of entries ?? []) {
    const baris = {
      id: entry.id,
      entryId: entry.id,
      keterangan: entry.keterangan ?? "",
      nominal: entry.nominal,
      bukti: entry.foto_bukti_url, // string URL foto lama (bukan File)
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

/** "2026-07-24" (local) — default tanggal aktif. */
function todayISO() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

export default function InputSetoran() {
  const navigate = useNavigate();
  const location = useLocation();

  const tanggalDraft = location.state?.tanggalDraft ?? null;
  const isEditDraft = tanggalDraft !== null;

  const [mode, setMode] = useState("hari-ini");
  const [totalSetor, setTotalSetor] = useState(0);
  const [rincian, setRincian] = useState({ transfer: [], qris: [], tunai: [], kpay: [] });
  const [errors, setErrors] = useState([]);
  const [toastMessage, setToastMessage] = useState(null);
 
  const [draftDisimpan, setDraftDisimpan] = useState(null);
  const [formData, setFormData] = useState(null); // hasil GET /teller/setoran
  const [loadingForm, setLoadingForm] = useState(true);

  const [tanggal, setTanggal] = useState(tanggalDraft ?? todayISO());
  const [kalenderMap, setKalenderMap] = useState({});
  const [versiForm, setVersiForm] = useState(0);

  // Dropdown loket: hanya untuk user dengan kapabilitas input semua loket.
  const user = useAuthStore((s) => s.user);
  const bisaSemuaLoket = user?.bisa_input_setoran_semua_loket === true;
  const [loketList, setLoketList] = useState([]);
  const [loketId, setLoketId] = useState(""); // "" = loket bawaan akun
  const bulanAktifRef = useRef(null);
  const loketPertamaRender = useRef(true);

  // Params loket: kosong kalau bukan user multi-loket atau belum memilih.
  const loketParams = bisaSemuaLoket && loketId ? { loket_id: loketId } : {};

  const { loading, saveDraft, submit, fetchForm, fetchKalender } = useInputSetoran();

  // Ambil daftar loket sekali, hanya kalau user berhak.
  useEffect(() => {
    if (!bisaSemuaLoket) return;
    getLokets()
      .then((res) => setLoketList(res?.data ?? []))
      .catch(() => setLoketList([]));
  }, [bisaSemuaLoket]);

  // Ambil state form dari server tiap kali tanggal atau loket berganti.
  useEffect(() => {
    let batal = false;
    async function muatForm() {
      setLoadingForm(true);
      try {
        const data = await fetchForm({ tanggal, ...loketParams });
        if (batal) return;
        setFormData(data);
        setRincian(data?.entries?.length ? entriesKeRincian(data.entries) : { transfer: [], qris: [], tunai: [], kpay: [] });
      } catch (err) {
        if (!batal) setErrors(uraikanErrorApi(err));
      } finally {
        if (!batal) setLoadingForm(false);
      }
    }
    muatForm();
    return () => { batal = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tanggal, loketId]);

  // Ambil status kalender untuk bulan yang sedang ditampilkan di picker.
  const muatKalender = useCallback(async (bulanStr) => {
    try {
      const params = { bulan: bulanStr };
      if (bisaSemuaLoket && loketId) params.loket_id = loketId;
      const data = await fetchKalender(params);
      const map = {};
      for (const item of data.kalender) {
        map[item.tanggal] = { status: item.status, bisa_diinput: item.bisa_diinput };
      }
      setKalenderMap(map);
    } catch {
      // Kalender gagal dimuat bukan blocker untuk input setoran; silent-fail.
    }
  }, [fetchKalender, bisaSemuaLoket, loketId]);

  const handleViewChange = (bulanStr) => {
    bulanAktifRef.current = bulanStr;
    muatKalender(bulanStr);
  };

  // Ganti loket -> kosongkan form lama dan muat ulang kalender loket baru.
  // Dilewati saat render pertama supaya data draft dari Riwayat tidak terhapus.
  useEffect(() => {
    if (loketPertamaRender.current) {
      loketPertamaRender.current = false;
      return;
    }
    setRincian({ transfer: [], qris: [], tunai: [], kpay: [] });
    setTotalSetor(0);
    setKalenderMap({});
    if (bulanAktifRef.current) muatKalender(bulanAktifRef.current);
  }, [loketId, muatKalender]);

  const pendapatanGagalDimuat = formData?.pendapatan_sopp_status === "gagal_dimuat";
  const totalPendapatanSOPP = formData?.total_pendapatan ?? null;
  const isLocked = formData?.is_locked === true;

  // Selisih di FE hanya preview live; angka resmi dari respons BE.
  const selisih = formData?.selisih ?? null;

  const [refreshing, setRefreshing] = useState(false);
  const [refreshCooldown, setRefreshCooldown] = useState(false);

  const handleRefreshPendapatan = async () => {
    setErrors([]);
    setRefreshing(true);
    try {
      const data = await fetchForm({ tanggal, ...loketParams, refresh: true });
      setFormData(data);
    } catch (err) {
      setErrors(uraikanErrorApi(err));
      window.scrollTo({ top: 0, behavior: "smooth" });
    } finally {
      setRefreshing(false);
      setRefreshCooldown(true);
      setTimeout(() => setRefreshCooldown(false), 10000);
    }
  };

    const handleSimpanDraft = async () => {
    const nominalErrors = validasiNominalDraft(rincian);
    if (nominalErrors.length > 0) {
      setErrors(nominalErrors);
      window.scrollTo({ top: 0, behavior: "smooth" });
      return;
    }
    setErrors([]);
    try {
      const payload = buildSetoranFormData(rincian, { tanggal });
      const hasil = await saveDraft(payload, loketParams);
      setFormData(hasil);
      setRincian(hasil?.entries?.length ? entriesKeRincian(hasil.entries) : { transfer: [], qris: [], tunai: [], kpay: [] });
      setVersiForm((v) => v + 1);
      setToastMessage("Draft berhasil disimpan.");
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
      const payload = buildSetoranFormData(rincian, { tanggal });
      const hasil = await submit(payload, loketParams);
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

      {bisaSemuaLoket && (
        <div className="flex flex-col gap-1 max-w-sm">
          <label htmlFor="pilih-loket" className="text-[#424751] text-sm font-medium">
            Loket
          </label>
          <select
            id="pilih-loket"
            value={loketId}
            onChange={(e) => setLoketId(e.target.value)}
            disabled={loading || isEditDraft}
            className="bg-white border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] disabled:opacity-50"
          >
            <option value="">Loket saya (bawaan)</option>
            {loketList.map((l) => (
              <option key={l.loket_id} value={l.loket_id}>
                {l.nama_loket}
              </option>
            ))}
          </select>
        </div>
      )}

      {/* Option cards — selalu tampil di atas, apa pun mode-nya */}
      <OptionCards mode={mode} onModeChange={setMode} tanggal={tanggal} />

      {mode === "hari-ini" ? (
        <>
          {isEditDraft && (
            <div className="bg-[#fff8e1] border border-[#f0c040] rounded-lg px-5 py-4 flex items-start gap-3">
              <span className="text-[#b45309] text-lg leading-none mt-0.5">📝</span>
              <div>
                <p className="text-[#92400e] text-sm font-semibold">Melanjutkan draft</p>
                <p className="text-[#92400e] text-sm mt-0.5">
                  Kamu sedang mengedit draft setoran tanggal{" "}
                  <span className="font-medium">{tanggal}</span>.
                  Lengkapi data yang kurang, lalu klik Submit untuk mengirimkan.
                </p>
              </div>
            </div>
          )}

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
                onRefresh={handleRefreshPendapatan}
                refreshing={refreshing}
                refreshDisabled={refreshCooldown || loadingForm}
                fetchedAt={formData?.fetched_at}
              />
              <RincianSetoran
                key={`${loketId || "default"}-${tanggal}-${versiForm}`}
                initialData={formData?.entries?.length ? rincian : undefined}
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
        <PelunasanContent key={loketParams.loket_id ?? "default"} loketId={loketParams.loket_id} onBatal={() => setMode("hari-ini")} />
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