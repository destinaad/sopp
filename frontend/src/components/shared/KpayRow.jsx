import { IconKpay, IconCheckGray, IconCheck, IconTrash } from "@/components/ui/Icon";

/**
 * Baris form khusus section KPAY — struktur field-nya beda dari TransactionRow
 * biasa (ada Nama Karyawan + NIK/NIP tambahan).
 *
 * KPAY TIDAK PUNYA upload bukti: BE melarang foto_bukti untuk metode kpay
 * (rule `prohibited`), jadi baris ini sengaja tanpa input file. Tombol
 * verifikasi manual pakai pola yang sama dengan TransactionRow.jsx.
 */
export default function KpayRow({
  row,
  onNamaChange,
  onNikChange,
  onKeteranganChange,
  onNominalChange,
  onToggleVerified,
  onDelete,
  readOnly = false,
  metode,
  onMetodeChange,
}) {
  return (
    <div className="flex flex-wrap gap-2 items-center border-b border-[rgba(194,198,210,0.3)] pb-2 pt-2">
      {onMetodeChange && (
        <select
          value={metode}
          onChange={(e) => onMetodeChange(e.target.value)}
          disabled={readOnly}
          className="shrink-0 bg-[#efefed] border border-[#d9d9d6] rounded-sm px-2.5 py-2.5 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
        >
          <option value="transfer">Transfer</option>
          <option value="qris">QRIS</option>
          <option value="tunai">Tunai</option>
          <option value="kpay">KPay</option>
        </select>
      )}
      <input
        type="text"
        placeholder="Nama Karyawan"
        value={row.nama}
        onChange={(e) => onNamaChange(e.target.value)}
        disabled={readOnly}
        className="flex-1 min-w-[120px] bg-[#efefed] border border-[#d9d9d6] rounded-sm px-4 py-2.5 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30 disabled:opacity-75 disabled:cursor-not-allowed"
      />
      <input
        type="text"
        placeholder="NIK, NIP, atau identitas karyawan"
        value={row.nik}
        onChange={(e) => onNikChange(e.target.value)}
        disabled={readOnly}
        className="flex-1 min-w-[80px] bg-[#efefed] border border-[#d9d9d6] rounded-sm px-4 py-2.5 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30 disabled:opacity-75 disabled:cursor-not-allowed"
      />
      <input
        type="text"
        placeholder="Keterangan"
        value={row.keterangan}
        onChange={(e) => onKeteranganChange(e.target.value)}
        disabled={readOnly}
        className="flex-1 min-w-[100px] bg-[#efefed] border border-[#d9d9d6] rounded-sm px-4 py-2.5 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30 disabled:opacity-75 disabled:cursor-not-allowed"
      />
      <div className="relative shrink-0 w-36">
        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-[#424751] text-sm select-none pointer-events-none">Rp</span>
        <input
          type="text"
          inputMode="numeric"
          placeholder="0"
          value={row.nominal}
          onChange={(e) => onNominalChange(e.target.value.replace(/\D/g, ""))}
          disabled={readOnly}
          className="w-full bg-[#efefed] border border-[#d9d9d6] rounded-sm pl-9 pr-4 py-2.5 text-sm text-right text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30 disabled:opacity-75 disabled:cursor-not-allowed"
        />
      </div>

      <button
        type="button"
        className={`shrink-0 flex items-center justify-center size-9 rounded border transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
          row.verified
            ? "bg-[#e6f4ea] border-[#16a34a] text-[#16a34a] hover:bg-[#ceead6]"
            : "bg-white border-[#d9d9d6] text-[#727782] hover:bg-[#f7f7f6] hover:border-[#b0b3b8]"
        }`}
        onClick={onToggleVerified}
        disabled={readOnly}
        aria-label={row.verified ? "Batalkan verifikasi" : "Verifikasi bukti"}
        title={row.verified ? "Sudah diverifikasi — klik untuk batalkan" : "Klik untuk verifikasi bukti"}
      >
        {row.verified ? <IconCheck /> : <IconCheckGray />}
      </button>

      {!readOnly && (
        <button
          type="button"
          className="shrink-0 flex items-center justify-center size-9 rounded border border-transparent hover:border-[#d9d9d6] hover:bg-[#f7f7f6] transition-colors"
          onClick={onDelete}
          aria-label="Hapus"
          title="Hapus baris"
        >
          <IconTrash />
        </button>
      )}
    </div>
  );
}