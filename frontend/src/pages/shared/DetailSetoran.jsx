import { useState, useEffect } from "react";
import { useParams, useNavigate } from "react-router-dom";
import DetailSetoranHeader from "@/components/shared/DetailSetoranHeader";
import PendapatanTable from "@/components/shared/PendapatanTable";
import RingkasanSetoranCard from "@/components/shared/RingkasanSetoranCard";
import RincianLampiran from "@/components/shared/RincianLampiran";
import RincianSetoran from "@/components/shared/RincianSetoran";
import KeteranganTellerCard from "@/components/shared/KeteranganTellerCard";
import { IconSend, IconSpinner } from "@/components/ui/Icon";
import useDetailSetoran from "@/hooks/useDetailSetoran";
import { updateSetoran } from "@/api/teller";
import { buildSetoranFormData } from "@/utils/buildSetoranFormData";
import Toast from "@/components/ui/Toast";

/**
 * Menerjemahkan entries[] dari respons GET/PUT Detail Setoran jadi bentuk
 * { transfer, qris, tunai, kpay } yang dipakai RincianSetoran.jsx.
 * entryId disimpan terpisah dari id (key lokal React) — sama pola dengan
 * entriesKeRincian di pages/teller/InputSetoran.jsx.
 */
function entriesKeRincianEdit(entries) {
  const rincian = { transfer: [], qris: [], tunai: [], kpay: [] };
  for (const entry of entries ?? []) {
    const baris = {
      id: entry.id,
      entryId: entry.id,
      keterangan: entry.keterangan ?? "",
      nominal: String(entry.nominal),
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

/**
 * Validasi dasar sebelum PUT. Beda dari draft Card A: di sini TIDAK ADA
 * mode "boleh kosong" — kontrak §2 menegaskan entries wajib minimal 1, dan
 * aturan foto_bukti per skenario (lihat tabel kontrak) lebih ketat karena
 * tidak ada anggapan "baris belum disentuh" seperti di Input Setoran.
 * Mengembalikan array pesan error (kosong = valid).
 */
function validasiRincianEdit(rincian) {
  const errors = [];
  const semuaBaris = [
    ...rincian.transfer.map((r) => ({ ...r, metode_pembayaran: "transfer" })),
    ...rincian.qris.map((r) => ({ ...r, metode_pembayaran: "qris" })),
    ...rincian.tunai.map((r) => ({ ...r, metode_pembayaran: "tunai" })),
    ...rincian.kpay.map((r) => ({ ...r, metode_pembayaran: "kpay" })),
  ];

  if (semuaBaris.length === 0) {
    errors.push("Minimal harus ada satu transaksi tercatat.");
    return errors;
  }

  semuaBaris.forEach((r, i) => {
    const label = r.metode_pembayaran;
    if (!r.nominal || Number(r.nominal) <= 0) {
      errors.push(`${label} baris ke-${i + 1}: nominal wajib diisi.`);
    }
    if (r.metode_pembayaran === "kpay") {
      if (!r.nama) errors.push(`KPay baris ke-${i + 1}: nama karyawan wajib diisi.`);
      if (!r.nik) errors.push(`KPay baris ke-${i + 1}: NIK/identitas karyawan wajib diisi.`);
    } else if (!r.bukti && !r.entryId) {
      // Ikut BE (validateEntries): foto WAJIB hanya untuk baris BARU (tanpa
      // entryId). Baris lama non-kpay foto-nya `nullable` di BE -- kalau tidak
      // mengirim file, foto lama dipertahankan, dan kalau memang belum pernah
      // ada foto, tetap boleh disimpan. (Kasus pindah dari KPay ke non-KPay tanpa
      // foto ditolak langsung oleh BE dengan 422 dan ditampilkan lewat uraikanErrorApi.)
      errors.push(`${label} baris ke-${i + 1}: bukti wajib diunggah.`);
    }
  });

  return errors;
}

/**
 * Kontrak §2: errors bisa 409 (optimistic lock), 429 (debounce), atau 422
 * (per-field/business-rule) — diseragamkan jadi array pesan siap tampil.
 */
function uraikanErrorApi(err) {
  const status = err?.response?.status;
  const data = err?.response?.data;

  if (status === 409) {
    return [data?.message || "Data ini sudah diubah pihak lain. Muat ulang halaman dulu."];
  }
  if (status === 429) {
    return [data?.message || "Tunggu sebentar sebelum menyimpan lagi."];
  }
  if (!data) {
    return ["Terjadi kesalahan. Periksa koneksi Anda dan coba lagi."];
  }
  const errorsObj = data.errors;
  if (errorsObj && Object.keys(errorsObj).length > 0) {
    return Object.values(errorsObj).flat();
  }
  return [data.message || "Gagal menyimpan. Periksa kembali."];
}

export default function DetailSetoran() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { data: detail, loading, error, refresh } = useDetailSetoran(id);

  const [mode, setMode] = useState("view");
  const [rincianEdit, setRincianEdit] = useState(null);
  const [loadingSimpan, setLoadingSimpan] = useState(false);
  const [errorSimpan, setErrorSimpan] = useState([]);
  const [toastMessage, setToastMessage] = useState(null);

  useEffect(() => {
    if (detail) {
      setRincianEdit(null); // reset supaya initialRincian dari data terbaru dipakai lagi
    }
  }, [detail]);

  if (loading && !detail) {
    return (
      <div className="flex items-center justify-center gap-2 py-24 text-[#727782] text-sm">
        <IconSpinner className="size-5" />
        <span>Memuat detail setoran...</span>
      </div>
    );
  }

  if (error && !detail) {
    // 404 (row tidak ada / masih draft -- BE sengaja menyamakan keduanya) dan
    // 403 (bukan pemilik & tanpa kapabilitas) adalah keputusan akses dari BE:
    // coba lagi tidak akan mengubah hasilnya, jadi tidak ada tombol ulang.
    const status = error?.response?.status;
    const ditolak = status === 403 || status === 404;
    const pesan =
      status === 404
        ? "Setoran tidak ditemukan."
        : status === 403
          ? error.response.data?.message || "Anda tidak memiliki akses untuk melakukan aksi ini."
          : "Gagal memuat detail setoran.";
    return (
      <div className="bg-white rounded-lg border border-[#ffdad6] p-6 text-center">
        <p className="text-[#93000a] text-sm font-medium mb-3">{pesan}</p>
        {!ditolak && (
          <button
            onClick={refresh}
            className="border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors"
          >
            Coba lagi
          </button>
        )}
      </div>
    );
  }

  if (!detail) return null;

  // §1: is_editable dihitung BE (kepemilikan row + bisa_edit_setoran_semua_loket).
  // FE DILARANG menghitung ulang logic ini sendiri — pakai apa adanya dari respons.
  const bolehEdit = detail.is_editable === true;

  const initialRincian = entriesKeRincianEdit(detail.entries);

  const handleBatal = () => {
    setRincianEdit(null);
    setErrorSimpan([]);
    setMode("view");
  };

  const handleSimpan = async () => {
    const rincianUntukSimpan = rincianEdit ?? initialRincian;
    const validasiErrors = validasiRincianEdit(rincianUntukSimpan);
    if (validasiErrors.length > 0) {
      setErrorSimpan(validasiErrors);
      window.scrollTo({ top: 0, behavior: "smooth" });
      return;
    }

    setErrorSimpan([]);
    setLoadingSimpan(true);
    try {
      // buildSetoranFormData sudah tau membedakan entryId (id server)
      // vs id (key lokal React) — aman dipakai langsung di sini
      const formData = buildSetoranFormData(rincianUntukSimpan);

      // entries_updated_at WAJIB selalu dikirim — echo balik dari GET
      // terakhir, walau nilainya null (kontrak §2: "present tapi boleh
      // null"). String kosong dipakai di sini sebagai representasi null
      // lewat multipart — PERLU DIKONFIRMASI ke BE apakah ini diterima
      // sama seperti null asli, atau ada representasi lain yang diharapkan.
      formData.append("entries_updated_at", detail.entries_updated_at ?? "");

      const res = await updateSetoran(id, formData);
      if (res?.status === "ok") {
        setMode("view");
        setToastMessage("Perubahan berhasil disimpan.");
        refresh(); // ambil ulang data terbaru (termasuk entries_updated_at yang baru ter-bump)
      }
    } catch (err) {
      setErrorSimpan(uraikanErrorApi(err));
      window.scrollTo({ top: 0, behavior: "smooth" });
    } finally {
      setLoadingSimpan(false);
    }
  };

  return (
    <>
      <div className="flex items-center justify-between gap-4">
        <h1 className="font-bold text-[#191c20] text-3xl tracking-tight">Detail Setoran</h1>

        {bolehEdit && mode === "view" && (
          <button
            onClick={() => setMode("edit")}
            className="bg-[#710014] flex gap-2 items-center px-5 py-2.5 rounded text-white text-sm font-medium hover:bg-[#5a000f] transition-colors shrink-0"
          >
            <span>✏️</span>
            <span>Edit</span>
          </button>
        )}

        {mode === "edit" && (
          <div className="flex gap-3 shrink-0">
            <button
              onClick={handleBatal}
              disabled={loadingSimpan}
              className="border border-[#d9d9d6] bg-white px-5 py-2.5 rounded text-sm text-[#424751] font-medium hover:bg-[#f7f7f6] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              Batal
            </button>
            <button
              onClick={handleSimpan}
              disabled={loadingSimpan}
              className="bg-[#710014] flex gap-2 items-center px-5 py-2.5 rounded text-white text-sm font-medium hover:bg-[#5a000f] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {loadingSimpan ? <IconSpinner className="size-4" /> : <IconSend />}
              <span>Simpan Perubahan</span>
            </button>
          </div>
        )}
      </div>

      {errorSimpan.length > 0 && (
        <div className="bg-[#ffdad6] border border-[#ba1a1a]/40 rounded-lg px-5 py-4">
          <p className="text-[#93000a] text-sm font-semibold mb-1.5">
            Periksa kembali data sebelum menyimpan:
          </p>
          <ul className="list-disc pl-5 space-y-0.5">
            {errorSimpan.map((msg, i) => (
              <li key={i} className="text-[#93000a] text-sm">{msg}</li>
            ))}
          </ul>
        </div>
      )}

      <DetailSetoranHeader
        tanggal={detail.tanggal_transaksi}
        loket={detail.nama_loket}
        petugas={detail.petugas}
      />

      {loading && (
        <div className="flex items-center gap-2 rounded-lg border border-[#f0c040] bg-[#fff8e1] px-4 py-3 text-sm text-[#92400e]">
          <IconSpinner className="size-4" />
          <span>Memuat ulang selisih personal dari sistem SOPP...</span>
        </div>
      )}

      <div className={`grid grid-cols-1 ${mode === "edit" ? "lg:grid-cols-1" : "lg:grid-cols-[1fr_360px]"} gap-5 w-full items-start`}>
        <div className="flex flex-col gap-5">
          {/* Total Pendapatan TETAP read-only apa pun mode-nya — data otomatis dari sistem SOPP */}
          <PendapatanTable
            rows={
              detail.pendapatan?.map((p) => ({
                id: p.vendor_id,
                provider: p.vendor_name,
                layanan: p.kategori,
                nominal: p.total,
              })) ?? null
            }
          />
          <RingkasanSetoranCard
            ringkasanSetoran={{
              transfer: detail.ringkasan_setoran.nominal_transfer,
              qris: detail.ringkasan_setoran.nominal_qris,
              tunai: detail.ringkasan_setoran.nominal_tunai,
              kpay: detail.ringkasan_setoran.nominal_kpay,
            }}
            totalPendapatan={detail.total_pendapatan}
            selisihSendiri={detail.selisih_sendiri}
            statusKeuangan={detail.status_keuangan}
          />
        </div>

        <div className="flex flex-col gap-5">
          {mode === "edit" ? (
            <RincianSetoran
              initialData={initialRincian}
              onRowsChange={setRincianEdit}
              bisaGantiMetode
            />
          ) : (
            <RincianLampiran entries={detail.entries} />
          )}

          <KeteranganTellerCard waktu={detail.submitted_at} />
        </div>
      </div>

      {mode === "view" && (
        <div className="flex items-center pt-2 pb-6">
          <button
            onClick={() => navigate(-1)}
            className="flex items-center gap-2 text-[#424751] text-sm font-medium hover:text-[#191c20] transition-colors"
          >
            <span>←</span>
            <span>Kembali</span>
          </button>
        </div>
      )}

      {toastMessage && <Toast message={toastMessage} onClose={() => setToastMessage(null)} />}
    </>
  );
}