import TransactionRow from "@/components/shared/TransactionRow";
import { IconPlus } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * Satu section rincian setoran (Transfer / QRIS / Tunai). KPAY tidak pakai ini
 * karena struktur baris-nya beda (lihat components/shared/KpayRow.jsx).
 *
 * Tidak ada perbandingan "kurang Rp ..." per section lagi — perbandingan
 * total setor vs total pendapatan SOPP cukup satu kali di RingkasanOtomatis
 * (bagian paling bawah halaman), supaya tidak ada 2 sumber kebenaran.
 */
export default function RincianSection({
  title,
  addLabel,
  addColor,
  rows,
  onAdd,
  onKeteranganChange,
  onNominalChange,
  onBuktiChange,
  onToggleVerified,
  onDelete,
  emptyMessage,
}) {
  const subtotal = rows.reduce((sum, r) => sum + (parseInt(r.nominal, 10) || 0), 0);

  return (
    <div className="relative bg-white rounded-lg border border-[#c2c6d2] w-full overflow-hidden">
      <div className="bg-[#efefed] border-b border-[#c2c6d2] flex items-center justify-between px-6 py-2">
        <span className="font-bold text-[#191c20] text-base">{title}</span>
        <span className="font-semibold text-[#424751] text-xs tracking-[0.6px]">
          Subtotal: {formatRupiah(subtotal)}
        </span>
      </div>

      <div className="flex flex-col gap-2 p-4">
        {rows.length === 0 && emptyMessage ? (
          <div className="flex flex-col items-center justify-center py-8 gap-2">
            <p className="text-[#424751] text-base">
              {emptyMessage.split("**").map((part, i) => (i % 2 === 1 ? <strong key={i}>{part}</strong> : part))}
            </p>
          </div>
        ) : (
          rows.map((row) => (
            <TransactionRow
              key={row.id}
              row={row}
              onKeteranganChange={(v) => onKeteranganChange(row.id, v)}
              onNominalChange={(v) => onNominalChange(row.id, v)}
              onBuktiChange={(v) => onBuktiChange(row.id, v)}
              onToggleVerified={() => onToggleVerified(row.id)}
              onDelete={() => onDelete(row.id)}
            />
          ))
        )}

        <button onClick={onAdd} className="flex gap-1 items-center text-base" style={{ color: addColor }}>
          <IconPlus />
          <span>{addLabel}</span>
        </button>
      </div>
    </div>
  );
}