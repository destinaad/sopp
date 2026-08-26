import { useRef } from "react";
import { IconUpload, IconTrash, IconCheck, IconCheckGray } from "@/components/ui/Icon";

/**
 * Satu baris form: input Keterangan + Nominal + tombol upload bukti + tombol
 * verifikasi manual + tombol hapus. Dipakai berulang di RincianSection
 * (Transfer, QRIS, Tunai).
 *
 * Tombol upload: file picker via <input type="file"> tersembunyi + useRef
 * (pola sama dengan DetailPelunasan.jsx). Karena ruang di baris ini sempit
 * (bukan dropzone besar), feedback filenya cuma lewat warna icon (biru saat
 * ada file) + tooltip (`title`) berisi nama file saat mouse hover.
 *
 * Tombol centang (`onToggleVerified`) BUKAN indikator otomatis dari nominal —
 * ini verifikasi manual teller: dicentang setelah teller cek bukti transfer
 * di grup WhatsApp teller dan yakin dananya benar sudah masuk.
 */
export default function TransactionRow({ row, onKeteranganChange, onNominalChange, onBuktiChange, onToggleVerified, onDelete }) {
  const fileInputRef = useRef(null);

  const handleFileSelected = (e) => {
    const file = e.target.files?.[0];
    if (file) onBuktiChange(file.name);
    e.target.value = ""; // reset supaya bisa pilih file yang sama lagi kalau perlu ganti
  };

  return (
    <div className="flex gap-3 items-center border-b border-[rgba(194,198,210,0.3)] pb-2 pt-2">
      <input
        type="text"
        placeholder="Keterangan"
        value={row.keterangan}
        onChange={(e) => onKeteranganChange(e.target.value)}
        className="flex-1 min-w-0 bg-[#efefed] border border-[#d9d9d6] rounded-sm px-4 py-2.5 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
      />
      <div className="relative shrink-0 w-44">
        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-[#424751] text-sm select-none pointer-events-none">Rp</span>
        <input
          type="text"
          inputMode="numeric"
          placeholder="0"
          value={row.nominal}
          onChange={(e) => onNominalChange(e.target.value.replace(/\D/g, ""))}
          className="w-full bg-[#efefed] border border-[#d9d9d6] rounded-sm pl-9 pr-4 py-2.5 text-sm text-right text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
        />
      </div>

      <input
        ref={fileInputRef}
        type="file"
        accept=".png,.jpg,.jpeg,.pdf"
        className="hidden"
        onChange={handleFileSelected}
      />
      <button
        className="shrink-0 p-1"
        onClick={() => fileInputRef.current?.click()}
        aria-label={row.bukti ? `Bukti terlampir: ${row.bukti}` : "Upload bukti"}
        title={row.bukti ? `Bukti: ${row.bukti} — klik untuk ganti` : "Klik untuk upload bukti"}
      >
        <IconUpload color={row.bukti ? "#185fa5" : "#424751"} />
      </button>

      <button
        className="shrink-0 p-1"
        onClick={onToggleVerified}
        aria-label={row.verified ? "Batalkan verifikasi" : "Verifikasi bukti transfer"}
        title={row.verified ? "Sudah diverifikasi — klik untuk batalkan" : "Klik untuk verifikasi bukti transfer"}
      >
        {row.verified ? <IconCheck /> : <IconCheckGray />}
      </button>
      <button className="shrink-0 p-1" onClick={onDelete} aria-label="Hapus">
        <IconTrash />
      </button>
    </div>
  );
}