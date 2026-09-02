import { useRef } from "react";
import { IconKpay, IconCheckGray, IconCheck, IconTrash } from "@/components/ui/Icon";

/**
 * Baris form khusus section KPAY — struktur field-nya beda dari TransactionRow
 * biasa (ada Nama Karyawan + NIK tambahan).
 *
 * Tombol upload & verifikasi manual pakai pola yang sama persis dengan
 * TransactionRow.jsx (lihat komentar di file itu untuk penjelasan lengkap).
 */
export default function KpayRow({ row, onNamaChange, onNikChange, onKeteranganChange, onNominalChange, onBuktiChange, onToggleVerified, onDelete }) {
  const fileInputRef = useRef(null);

  const handleFileSelected = (e) => {
    const file = e.target.files?.[0];
    if (file) onBuktiChange(file.name);
    e.target.value = "";
  };

  return (
    <div className="flex flex-wrap gap-2 items-center border-b border-[rgba(194,198,210,0.3)] pb-2 pt-2">
      <input
        type="text"
        placeholder="Nama Karyawan"
        value={row.nama}
        onChange={(e) => onNamaChange(e.target.value)}
        className="flex-1 min-w-[120px] bg-[#efefed] border border-[#d9d9d6] rounded-sm px-4 py-2.5 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
      />
      <input
        type="text"
        placeholder="Identitas Karyawan"
        value={row.nik}
        onChange={(e) => onNikChange(e.target.value)}
        className="flex-1 min-w-[80px] bg-[#efefed] border border-[#d9d9d6] rounded-sm px-4 py-2.5 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
      />
      <input
        type="text"
        placeholder="Keterangan"
        value={row.keterangan}
        onChange={(e) => onKeteranganChange(e.target.value)}
        className="flex-1 min-w-[100px] bg-[#efefed] border border-[#d9d9d6] rounded-sm px-4 py-2.5 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
      />
      <div className="relative shrink-0 w-36">
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

      <button
        className="shrink-0 p-1"
        onClick={onToggleVerified}
        aria-label={row.verified ? "Batalkan verifikasi" : "Verifikasi bukti"}
        title={row.verified ? "Sudah diverifikasi — klik untuk batalkan" : "Klik untuk verifikasi bukti"}
      >
        {row.verified ? <IconCheck /> : <IconCheckGray />}
      </button>
      <button className="shrink-0 p-1" onClick={onDelete} aria-label="Hapus">
        <IconTrash />
      </button>
    </div>
  );
}