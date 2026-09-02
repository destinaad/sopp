import { IconCalendar, IconClockHistory, IconCardCheckCorner, IconClockSmall } from "@/components/ui/Icon";
import { formatDateWithDay } from "@/utils/formatDate";

/**
 * 2 kartu di paling atas halaman Input Setoran: "Setoran Hari Ini" dan
 * "Pelunasan Setoran Lama". Cuma untuk MEMILIH mode, tidak ada input tanggal
 * di sini lagi — untuk mode "pelunasan", pemilihan tanggal + "Muat Data"
 * sekarang ditangani PelunasanDatePicker.jsx di dalam PelunasanContent.jsx.
 *
 * `tanggal` (BARU, opsional) — tanggal ISO ("YYYY-MM-DD") yang lagi dipilih
 * teller lewat TanggalTransaksiPicker di InfoTransaksi. Kalau di-pass, badge
 * tanggal di Card A ikut tanggal itu (bukan hardcode hari ini lagi). Kalau
 * tidak di-pass (mis. dipakai di tempat lain tanpa konsep tanggal terpilih),
 * fallback ke hari ini supaya tetap kompatibel.
 */
export default function OptionCards({ mode, onModeChange, tanggal }) {
  const tanggalDitampilkan = formatDateWithDay(tanggal ? new Date(`${tanggal}T00:00:00`) : new Date());

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-6 w-full">
      {/* Card A: Setoran Hari Ini */}
      <button
        onClick={() => onModeChange("hari-ini")}
        className={`relative bg-white rounded-lg text-left overflow-hidden transition-all ${
          mode === "hari-ini"
            ? "ring-2 ring-[#710014] shadow-sm"
            : "border border-[#d9d9d6] hover:border-[#710014]/50"
        }`}
      >
        {mode === "hari-ini" && (
          <div className="absolute top-0 right-0 w-7 h-8 bg-[#710014] rounded-bl-sm flex items-center justify-center">
            <IconCardCheckCorner />
          </div>
        )}
        <div className="flex gap-4 items-start p-6">
          <div className="bg-[rgba(113,0,20,0.1)] rounded-xl size-12 flex items-center justify-center shrink-0">
            <IconCalendar />
          </div>
          <div className="flex flex-col gap-1">
            <span className="font-semibold text-[#191c20] text-xl">Setoran Hari Ini</span>
            <span className="text-[#424751] text-sm pb-3">Catat setoran untuk transaksi hari ini</span>
            <div className="bg-[rgba(113,0,20,0.1)] flex gap-1 items-center px-2 py-1 rounded-xl w-fit">
              <IconClockSmall />
              <span className="font-semibold text-[#191c20] text-xs tracking-[0.6px]">{tanggalDitampilkan}</span>
            </div>
          </div>
        </div>
      </button>

      {/* Card B: Pelunasan Setoran Lama */}
      <button
        onClick={() => onModeChange("pelunasan")}
        className={`relative bg-white rounded-lg text-left overflow-hidden transition-all ${
          mode === "pelunasan"
            ? "ring-2 ring-[#710014] shadow-sm"
            : "border border-[#d9d9d6] hover:border-[#710014]/50"
        }`}
      >
        {mode === "pelunasan" && (
          <div className="absolute top-0 right-0 w-7 h-8 bg-[#710014] rounded-bl-sm flex items-center justify-center">
            <IconCardCheckCorner />
          </div>
        )}
        <div className="flex gap-4 items-start p-6">
          <div className="bg-[rgba(113,0,20,0.1)] rounded-xl size-12 flex items-center justify-center shrink-0">
            <IconClockHistory />
          </div>
          <div className="flex flex-col gap-2 flex-1">
            <span className="font-semibold text-[#191c20] text-xl">Pelunasan Setoran Lama</span>
            <span className="text-[#424751] text-sm">Lunasi selisih dari setoran sebelumnya</span>
          </div>
        </div>
      </button>
    </div>
  );
}