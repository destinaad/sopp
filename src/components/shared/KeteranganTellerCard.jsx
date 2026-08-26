import { IconShieldOutline } from "@/components/ui/Icon";
import { formatDateWithDay } from "@/utils/formatDate";

/**
 * [DETAIL] Kartu "Keterangan Teller" — waktu submit + catatan opsional teller.
 * Styling pink-tinted mengikuti desain (bukan warning, cuma penanda "catatan
 * dari teller yang bersangkutan").
 *
 * `editable` (opsional): kalau true, kotak "Catatan" berubah jadi textarea
 * yang bisa diketik — dipakai saat DetailSetoran.jsx dalam mode edit.
 */
export default function KeteranganTellerCard({ waktu, catatan, editable = false, onCatatanChange }) {
  const tanggal = formatDateWithDay(waktu);
  const jam = new Date(waktu).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });

  return (
    <div className="relative bg-[rgba(113,0,20,0.05)] border border-[rgba(113,0,20,0.15)] rounded-lg p-5 w-full overflow-hidden">
      <IconShieldOutline className="absolute top-4 right-4 size-8 text-[#710014]/15" />

      <span className="font-bold text-[#191c20] text-base">Keterangan Teller</span>

      <p className="text-[#424751] text-sm font-medium mt-3">
        {tanggal}, {jam} WIB
      </p>

      <div className="bg-white border border-[#d9d9d6] rounded px-3 py-2.5 mt-2">
        <span className="font-semibold text-[#9ca3af] text-[10px] tracking-[0.4px] uppercase block mb-1">Catatan</span>
        {editable ? (
          <textarea
            value={catatan}
            onChange={(e) => onCatatanChange(e.target.value)}
            rows={3}
            placeholder="Tulis catatan (opsional)..."
            className="w-full text-sm text-[#191c20] placeholder-[#9ca3af] resize-none focus:outline-none"
          />
        ) : (
          <span className="text-[#9ca3af] text-sm">{catatan || "—"}</span>
        )}
      </div>
    </div>
  );
}