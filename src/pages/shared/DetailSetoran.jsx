import { useState, useEffect } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useAuthStore } from "@/store/authStore";
import DetailSetoranHeader from "@/components/shared/DetailSetoranHeader";
import PendapatanTable from "@/components/shared/PendapatanTable";
import RingkasanSetoranCard from "@/components/shared/RingkasanSetoranCard";
import RincianLampiran from "@/components/shared/RincianLampiran";
import RincianSetoran from "@/components/shared/RincianSetoran";
import KeteranganTellerCard from "@/components/shared/KeteranganTellerCard";
import { IconSend } from "@/components/ui/Icon";
import useDetailSetoran from "@/hooks/useDetailSetoran";
import { IconSpinner } from "@/components/ui/icon";

/**
 * Ubah data lampiran (bentuk read-only: label/buktiUrl) jadi bentuk baris
 * form RincianSetoran (bentuk edit: keterangan/bukti/verified) supaya form
 * edit terisi data yang sudah ada, bukan form kosong.
 *
 * `verified: true` dipakai sebagai default karena data ini sudah pernah
 * disubmit sebelumnya (dianggap sudah pernah dicek) — teller/Bu Wiwik tetap
 * bisa batalkan centangnya manual kalau memang perlu diverifikasi ulang.
 *
 * `bukti` di sini baru berupa nama file placeholder (bukan file asli),
 * karena mock belum menyimpan metadata file sungguhan dari backend.
 */
function lampiranKeRincianAwal(lampiran) {
  const toRow = (item, i) => ({
    id: i + 1,
    keterangan: item.label,
    nominal: String(item.nominal),
    bukti: item.buktiUrl ? `${item.label.replace(/\s+/g, "_")}.jpg` : null,
    verified: true,
  });

  return {
    transfer: lampiran.transfer.map(toRow),
    qris: lampiran.qris.map(toRow),
    tunai: lampiran.tunai.map(toRow),
    kpay: lampiran.kpayKaryawan.map((k, i) => ({
      id: i + 1,
      nama: k.nama,
      nik: k.nik,
      keterangan: lampiran.kpayKeterangan,
      nominal: String(k.nominal ?? 0),
      bukti: null,
      verified: true,
    })),
  };
}

/**
 * Halaman Detail Setoran — lanjutan dari Riwayat Setoran, dibuka saat teller
 * klik ikon mata di kolom "Detail". Route (di App.jsx):
 *   <Route path="/setoran/:id" element={<DetailSetoran />} />
 *
 * Halaman ini "shared" (bukan di pages/teller/) karena berpotensi diakses
 * lebih dari satu role — teller lihat & edit punya sendiri, Bu Wiwik
 * (verifikator) lihat & edit semua loket.
 *
 * Mode "view" (default) -> semua baca-saja, seperti sebelumnya.
 * Mode "edit" (klik tombol Edit) -> Rincian Lampiran diganti jadi form
 * RincianSetoran (form yang sama dengan Input Setoran) + Keterangan Teller
 * jadi textarea, muncul tombol Batal & Simpan Perubahan.
 *
 * CATATAN: role Super Admin (IT) belum diputuskan apakah ikut boleh edit
 * atau cuma lihat — untuk sekarang tombol Edit HANYA muncul untuk role
 * "teller" dan "verifikator", sesuai yang sudah dikonfirmasi. Sesuaikan array
 * `ROLE_BOLEH_EDIT` di bawah begitu itu diputuskan.
 *
 * `getDetailSetoran(id)` masih mock dan SELALU mengembalikan data yang sama
 * berapa pun id-nya — nanti diganti fetch ke bridge.php berdasarkan id asli.
 * Begitu juga "Simpan Perubahan" di bawah cuma balik ke mode view tanpa
 * benar-benar mengirim apa pun ke server (belum ada endpoint PUT/POST-nya).
 */
const ROLE_BOLEH_EDIT = ["teller", "keuangan"];

export default function DetailSetoran() {
  const { id } = useParams();
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const { data: detail, loading, error, refresh } = useDetailSetoran(id);

  const [mode, setMode] = useState("view");
  const [catatanEdit, setCatatanEdit] = useState("");

  const bolehEdit = ROLE_BOLEH_EDIT.includes(user.role);
  
  useEffect(() => {
    if (detail) setCatatanEdit(detail.keteranganTeller.catatan);
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
    return (
      <div className="bg-white rounded-lg border border-[#ffdad6] p-6 text-center">
        <p className="text-[#93000a] text-sm font-medium mb-3">Gagal memuat detail setoran.</p>
        <button
          onClick={refresh}
          className="border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors"
        >
          Coba lagi
        </button>
      </div>
    );
  }

  if (!detail) return null;

  const totalPendapatan = detail.pendapatan.reduce((sum, r) => sum + r.nominal, 0);
  const initialRincian = lampiranKeRincianAwal(detail.lampiran);

  const handleBatal = () => {
    setCatatanEdit(detail.keteranganTeller.catatan);
    setMode("view");
  };

  const handleSimpan = () => {
    // TODO: kirim perubahan ke backend (PUT /bridge.php?action=edit_setoran&id=...)
    // begitu endpoint-nya tersedia. Untuk sekarang cuma balik ke mode lihat.
    setMode("view");
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
              className="border border-[#d9d9d6] bg-white px-5 py-2.5 rounded text-sm text-[#424751] font-medium hover:bg-[#f7f7f6] transition-colors"
            >
              Batal
            </button>
            <button
              onClick={handleSimpan}
              className="bg-[#710014] flex gap-2 items-center px-5 py-2.5 rounded text-white text-sm font-medium hover:bg-[#5a000f] transition-colors"
            >
              <IconSend />
              <span>Simpan Perubahan</span>
            </button>
          </div>
        )}
      </div>

      <DetailSetoranHeader tanggal={detail.tanggal} loket={detail.loket} petugas={detail.petugas} />

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-5 w-full items-start">
        <div className="flex flex-col gap-5">
          {/* Total Pendapatan TETAP read-only apa pun mode-nya — data otomatis dari sistem SOPP */}
          <PendapatanTable rows={detail.pendapatan} />
          <RingkasanSetoranCard ringkasanSetoran={detail.ringkasanSetoran} totalPendapatan={totalPendapatan} />
        </div>

        <div className="flex flex-col gap-5">
          {mode === "edit" ? (
            <RincianSetoran initialData={initialRincian} />
          ) : (
            <RincianLampiran lampiran={detail.lampiran} />
          )}

          <KeteranganTellerCard
            waktu={detail.keteranganTeller.waktu}
            catatan={mode === "edit" ? catatanEdit : detail.keteranganTeller.catatan}
            editable={mode === "edit"}
            onCatatanChange={setCatatanEdit}
          />
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
    </>
  );
}