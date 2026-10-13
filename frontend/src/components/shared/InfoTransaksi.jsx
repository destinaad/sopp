import { useAuthStore } from "@/store/authStore";
import TanggalTransaksiPicker from "@/components/shared/TanggalTransaksiPicker";

/**
 * `tanggal`/`onTanggalChange`/`statusTanggal` BARU — sebelumnya tanggal di
 * sini hardcode `new Date()` (selalu hari ini, tidak bisa diganti). Sekarang
 * diangkat jadi controlled component: state tanggal-nya dipegang oleh
 * InputSetoran.jsx (parent), supaya bisa dipakai juga untuk menentukan
 * form aktif/terkunci. Lihat InputSetoran.jsx untuk logika lengkapnya.
 *
 * `labelPengisi`/`namaPengisi` BARU (opsional) — dipakai halaman
 * verifikator/InputSetoran.jsx. Teller selalu mengisi form untuk dirinya
 * sendiri, jadi kolom ketiga wajar berlabel "Teller" + nama dirinya
 * (default, tidak perlu di-pass apa-apa dari pemanggil teller). Tapi kalau
 * yang mengisi adalah verifikator ATAS NAMA loket lain (dropdown "Pilih
 * Cabang"), label "Teller" jadi menyesatkan — verifikator bukan teller
 * loket itu. Pemanggil bisa override jadi mis. labelPengisi="Diinput Oleh"
 * supaya jelas ini bukan klaim "saya teller loket ini", cuma pencatatan
 * siapa yang menekan submit.
 */
export default function InfoTransaksi({
  loket,
  tanggal,
  onTanggalChange,
  statusTanggal,
  kalenderMap = {},
  onViewChange,
  labelPengisi = "Teller",
  namaPengisi,
}) {
  const user = useAuthStore((s) => s.user);
  const namaLoket = loket ?? user.loket;
  const namaDitampilkan = namaPengisi ?? user.name;

  return (
    <div className="relative bg-white rounded border border-[#d9d9d6] w-full">
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4">
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs">Tanggal Transaksi</span>
          <TanggalTransaksiPicker 
          value={tanggal} 
          onChange={onTanggalChange} 
          statusTanggal={statusTanggal}
          kalenderMap={kalenderMap}
          onViewChange={onViewChange} 
          />
        </div>
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs">Loket</span>
          <span className="font-semibold text-black text-sm">{namaLoket}</span>
        </div>
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs">{labelPengisi}</span>
          <span className="font-semibold text-black text-sm">{namaDitampilkan}</span>
        </div>
      </div>
    </div>
  );
}