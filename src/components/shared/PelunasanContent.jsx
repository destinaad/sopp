import { useState, useEffect } from "react";
import PelunasanDatePicker from "@/components/shared/PelunasanDatePicker";
import SisaSelisihCard from "@/components/shared/SisaSelisihCard";
import DetailPelunasan from "@/components/shared/DetailPelunasan";
import RingkasanPelunasan from "@/components/shared/RingkasanPelunasan";
import Toast from "@/components/ui/Toast";
import { IconSend, IconSpinner, IconCheck } from "@/components/ui/Icon";
import { useAuthStore } from "@/store/authStore";
import { formatRupiah } from "@/utils/formatRupiah";
import { getPelunasan, submitPelunasan } from "@/api/teller";

/**
 * `loketId` BARU (opsional) — dipakai halaman verifikator/InputSetoran.jsx
 * saat mengisi Pelunasan ATAS NAMA loket lain (dropdown "Pilih Cabang").
 * Diteruskan sebagai query/body param `loket_id` ke getPelunasan()/
 * submitPelunasan(), mengikuti aturan resolveLoketId() yang sama dengan
 * Card A (kontrak §0.2) — kalau tidak di-pass sama sekali (pemakaian
 * teller biasa, tidak berubah), BE otomatis pakai loket_id milik user
 * yang login sendiri seperti sebelumnya.
 */
export default function PelunasanContent({ onBatal, loketId }) {
  const user = useAuthStore((s) => s.user);

  const [tanggal, setTanggal] = useState("");
  const [isLoaded, setIsLoaded] = useState(false);
  const [sisaSelisih, setSisaSelisih] = useState(0);

  const [tanggalTersedia, setTanggalTersedia] = useState([]);
  const [loadingTanggal, setLoadingTanggal] = useState(false);
  
  useEffect(() => {
  async function muatDaftarTanggal() {
    setLoadingTanggal(true);
    try {
      const res = await getPelunasan(loketId ? { loket_id: loketId } : {});
      if (res?.status === "ok") {
        setTanggalTersedia(
          res.data.tanggal_tersedia.map((item) => item.tanggal)
        );
      }
    } catch {
      setTanggalTersedia([]);
    } finally {
      setLoadingTanggal(false);
    }
  }
  muatDaftarTanggal();
  // loketId sengaja masuk dependency: kalau verifikator ganti loket lewat
  // dropdown, daftar tanggal tersedia harus dimuat ulang untuk loket baru
  // itu — daftar loket lama sudah tidak relevan sama sekali.
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, [loketId]);

  const [jumlah, setJumlah] = useState("");
  const [metode, setMetode] = useState("Transfer Bank");
  const [keterangan, setKeterangan] = useState("");
  const [buktiFile, setBuktiFile] = useState(null);

  // Dipakai di validasi() dan handleSimpanPelunasan(), DAN di render
  // (RingkasanPelunasan) — makanya harus di level ini, bukan dideklarasikan
  // ulang secara lokal di dalam masing-masing fungsi seperti sebelumnya.
  // (Perbaikan bug: `jumlahNum` sebelumnya dipakai di JSX RingkasanPelunasan
  // tanpa pernah dideklarasikan di scope itu — ReferenceError begitu
  // isLoaded true, membuat mode Pelunasan crash total.)
  const jumlahNum = parseInt(jumlah) || 0;

  // [SUBMIT] State untuk proses submit dan hasil akhir
  const [loadingSubmit, setLoadingSubmit] = useState(false);
  const [errorSubmit, setErrorSubmit] = useState([]);
  const [sudahSubmit, setSudahSubmit] = useState(false);
  const [hasilPelunasan, setHasilPelunasan] = useState(null);
  const [toastMessage, setToastMessage] = useState(null);

  const [loadingMuat, setLoadingMuat] = useState(false);

  const handleMuatData = async () => {
    setLoadingMuat(true);
    setIsLoaded(false);
    setErrorSubmit([]);
    try {
      // dengan param tanggal → mode force-refresh; loket_id ikut disertakan
      // kalau verifikator sedang mengisi atas nama loket lain
      const res = await getPelunasan(loketId ? { tanggal, loket_id: loketId } : { tanggal });
      if (res?.status === "ok") {
        setSisaSelisih(res.data.sisa_selisih);
        setJumlah(String(res.data.sisa_selisih));
        setIsLoaded(true);
        setSudahSubmit(false);
        setHasilPelunasan(null);
      }
    } catch (err) {
      const status = err?.response?.status;
      const data = err?.response?.data;
      if (status === 429) {
        setErrorSubmit(["Tunggu beberapa detik sebelum refresh lagi."]);
      } else if (status === 422) {
        setErrorSubmit([data?.message || "Tanggal ini tidak memenuhi syarat pelunasan."]);
      } else if (status === 502) {
        setErrorSubmit(["Gagal mengambil data terbaru dari bridge, coba lagi."]);
      } else {
        setErrorSubmit(["Gagal memuat data. Periksa koneksi dan coba lagi."]);
      }
    } finally {
      setLoadingMuat(false);
    }
  };

  // [1] VALIDASI sebelum kirim ke BE
  const validasi = () => {
    const errs = [];

    if (!jumlah || jumlahNum <= 0) {
      errs.push("Jumlah pelunasan wajib diisi dan harus lebih dari Rp 0.");
    }
    if (jumlahNum > sisaSelisih) {
      errs.push(
        `Jumlah pelunasan (${formatRupiah(jumlahNum)}) tidak boleh melebihi sisa selisih (${formatRupiah(sisaSelisih)}).`
      );
    }
    if (!metode) {
      errs.push("Metode pelunasan wajib dipilih.");
    }
    return errs;
  };

  // [2] HANDLER SUBMIT — validasi → kirim BE → tampilkan read-only
  const handleSimpanPelunasan = async () => {
  const errs = validasi();
  if (errs.length > 0) {
    setErrorSubmit(errs);
    return;
  }

  setErrorSubmit([]);
  setLoadingSubmit(true);

  try {
    // Bangun FormData sesuai kontrak POST:
    // metode_pembayaran adalah array, tiap baris adalah object terpisah
    const formData = new FormData();
    formData.append("tanggal", tanggal);

    // Satu baris metode — index [0] karena form ini single-metode
    const metodeApi = metode === "Transfer Bank" ? "transfer"
      : metode === "Tunai" ? "tunai"
      : "qris"; // QRIS

    formData.append("metode_pembayaran[0][metode]", metodeApi);
    formData.append("metode_pembayaran[0][nominal]", String(jumlahNum));
    if (keterangan) {
      formData.append("metode_pembayaran[0][keterangan]", keterangan);
    }
    if (buktiFile) {
      formData.append("metode_pembayaran[0][bukti]", buktiFile);
    }

    const res = await submitPelunasan(formData, loketId ? { loket_id: loketId } : {});

    if (res?.status === "ok") {
      setHasilPelunasan({
        tanggal,
        jumlah: jumlahNum,
        metode,
        keterangan,
        buktiFile,
        sisaSelisihAwal: sisaSelisih,
        // gunakan angka resmi dari BE, bukan hitung sendiri di FE
        sisaSetelah: res.data.selisih,
        statusKeuangan: res.data.status_keuangan,
      });
      setSudahSubmit(true);
      setToastMessage("Pelunasan berhasil disimpan.");
    }
  } catch (err) {
    const status = err?.response?.status;
    const data = err?.response?.data;

    if (status === 422) {
      const errorCode = data?.error_code;
      if (errorCode === "selisih_belum_dimuat") {
        setErrorSubmit(["Data selisih belum dimuat. Klik 'Muat Data' terlebih dahulu."]);
      } else if (errorCode === "nominal_melebihi_selisih") {
        setErrorSubmit(["Jumlah pelunasan melebihi sisa selisih yang tersedia."]);
      } else if (data?.errors && Object.keys(data.errors).length > 0) {
        setErrorSubmit(Object.values(data.errors).flat());
      } else {
        setErrorSubmit([data?.message || "Terjadi kesalahan. Coba lagi."]);
      }
    } else {
      setErrorSubmit([data?.message || "Gagal menyimpan pelunasan. Coba lagi."]);
    }
  } finally {
    setLoadingSubmit(false);
  }
};

  // [3] TAMPILAN READ-ONLY setelah submit berhasil
  if (sudahSubmit && hasilPelunasan) {
    return (
      <div className="flex flex-col gap-6 w-full">
        <div className="flex flex-col gap-0.5">
          <h2 className="font-semibold text-[#191c20] text-2xl">Pelunasan Setoran</h2>
          <p className="text-[#424751] text-sm">Pelunasan telah berhasil disimpan</p>
        </div>

        {/* Banner sukses */}
        <div className="bg-[#e6f4ea] border border-[#16a34a]/30 rounded-lg px-5 py-4 flex items-start gap-3">
          <span className="mt-0.5 text-[#16a34a]">
            <IconCheck />
          </span>
          <div>
            <p className="text-[#14532d] text-sm font-semibold">Pelunasan berhasil dicatat</p>
            <p className="text-[#14532d] text-sm mt-0.5">
              Pelunasan sebesar{" "}
              <span className="font-medium">{formatRupiah(hasilPelunasan.jumlah)}</span>{" "}
              untuk setoran tanggal{" "}
              <span className="font-medium">{hasilPelunasan.tanggal}</span> sudah tersimpan.
              {hasilPelunasan.sisaSetelah === 0
                ? " Selisih sudah lunas sepenuhnya."
                : ` Sisa selisih: ${formatRupiah(hasilPelunasan.sisaSetelah)}.`}
            </p>
          </div>
        </div>

        {/* Ringkasan read-only */}
        <div className="bg-white rounded-lg border border-[#d9d9d6] p-6 flex flex-col gap-4">
          <h3 className="font-semibold text-[#191c20] text-base">Ringkasan Pelunasan</h3>
          <div className="grid grid-cols-2 gap-x-8 gap-y-3 text-sm">
            <span className="text-[#727782]">Tanggal Setoran</span>
            <span className="text-[#191c20] font-medium">{hasilPelunasan.tanggal}</span>

            <span className="text-[#727782]">Jumlah Dilunasi</span>
            <span className="text-[#191c20] font-medium">{formatRupiah(hasilPelunasan.jumlah)}</span>

            <span className="text-[#727782]">Metode</span>
            <span className="text-[#191c20] font-medium">{hasilPelunasan.metode}</span>

            {hasilPelunasan.keterangan && (
              <>
                <span className="text-[#727782]">Keterangan</span>
                <span className="text-[#191c20]">{hasilPelunasan.keterangan}</span>
              </>
            )}

            {hasilPelunasan.buktiFile && (
              <>
                <span className="text-[#727782]">Bukti</span>
                <span className="text-[#006c49] font-medium">{hasilPelunasan.buktiFile.name}</span>
              </>
            )}

            <span className="text-[#727782]">Sisa Selisih Awal</span>
            <span className="text-[#710014] font-medium">{formatRupiah(hasilPelunasan.sisaSelisihAwal)}</span>

            <span className="text-[#727782]">Sisa Setelah Pelunasan</span>
            <span
              className="font-semibold"
              style={{ color: hasilPelunasan.sisaSetelah === 0 ? "#16a34a" : "#710014" }}
            >
              {formatRupiah(hasilPelunasan.sisaSetelah)}
            </span>
          </div>
        </div>

        {/* Tombol kembali ke mode hari ini */}
        <div className="flex justify-end pt-2 pb-4">
          <button
            onClick={onBatal}
            className="border border-[#d9d9d6] bg-white px-8 py-4 rounded hover:bg-[#f7f7f6] transition-colors text-[#424751] text-base"
          >
            Kembali ke Setoran Hari Ini
          </button>
        </div>

        {toastMessage && (
          <Toast message={toastMessage} onClose={() => setToastMessage(null)} />
        )}
      </div>
    );
  }

  // Tampilan normal (sebelum submit)
  return (
    <div className="flex flex-col gap-6 w-full">
      <div className="flex flex-col gap-0.5">
        <h2 className="font-semibold text-[#191c20] text-2xl">Pelunasan Setoran</h2>
        <p className="text-[#424751] text-sm">Lunasi selisih dari setoran sebelumnya</p>
      </div>

      <PelunasanDatePicker
        tanggal={tanggal}
        onTanggalChange={(tgl) => {
          setTanggal(tgl);
          // Reset kalau user ganti tanggal sebelum muat data
          setIsLoaded(false);
          setErrorSubmit([]);
        }}
        onMuatData={handleMuatData}
        tanggalTersedia={tanggalTersedia}
        loadingTanggal={loadingTanggal}
      />

      {isLoaded && <SisaSelisihCard sisaSelisih={sisaSelisih} />}

      {isLoaded && (
        <DetailPelunasan
          jumlah={jumlah}
          onJumlahChange={(val) => {
            setJumlah(val);
            setErrorSubmit([]); // hapus error saat user mulai mengetik
          }}
          metode={metode}
          onMetodeChange={setMetode}
          keterangan={keterangan}
          onKeteranganChange={setKeterangan}
          buktiFile={buktiFile}
          onBuktiChange={setBuktiFile}
        />
      )}

      {isLoaded && (
        <RingkasanPelunasan sisaSelisihAwal={sisaSelisih} jumlahPelunasan={jumlahNum} />
      )}

      {/* Kotak error validasi */}
      {errorSubmit.length > 0 && (
        <div className="bg-[#ffdad6] border border-[#ba1a1a]/40 rounded-lg px-5 py-4">
          <p className="text-[#93000a] text-sm font-semibold mb-1.5">
            Periksa kembali sebelum menyimpan:
          </p>
          <ul className="list-disc pl-5 space-y-0.5">
            {errorSubmit.map((msg, i) => (
              <li key={i} className="text-[#93000a] text-sm">{msg}</li>
            ))}
          </ul>
        </div>
      )}

      <div className="flex gap-4 items-center justify-end pt-2 pb-4">
        <button
          onClick={onBatal}
          disabled={loadingSubmit}
          className="border border-[#d9d9d6] bg-white flex gap-2 items-center px-8 py-4 rounded hover:bg-[#f7f7f6] disabled:opacity-50 transition-colors text-[#424751] text-base"
        >
          Batal
        </button>
        <button
          onClick={handleSimpanPelunasan}
          disabled={!isLoaded || loadingSubmit}
          className="bg-[#710014] flex gap-2 items-center px-8 py-4 rounded shadow-lg hover:bg-[#5a000f] disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-white text-base"
        >
          {loadingSubmit ? <IconSpinner className="size-5" /> : <IconSend />}
          <span>{loadingSubmit ? "Menyimpan..." : "Simpan Pelunasan"}</span>
        </button>
      </div>

      {toastMessage && (
        <Toast message={toastMessage} onClose={() => setToastMessage(null)} />
      )}
    </div>
  );
}