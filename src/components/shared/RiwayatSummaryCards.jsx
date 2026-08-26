import { IconDocumentText, IconArchiveBox, IconWarningTriangle, IconTrendUp } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * [RIWAYAT] 3 kartu ringkasan di bagian bawah halaman Riwayat Setoran:
 * total setoran terverifikasi, total entry bulan ini, dan pending selisih.
 */
export default function RiwayatSummaryCards({ ringkasan }) {
  const { totalTerverifikasi, persenVsBulanLalu, totalEntryBulanIni, rataRataEntryPerHari, pendingSelisih } = ringkasan;

  return (
    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 w-full">
      {/* Kartu 1: Total Setoran Terverifikasi */}
      <div className="relative bg-white rounded-lg border border-[#d9d9d6] p-5 flex gap-4 items-start">
        <div className="bg-[#e6f1fb] rounded-xl size-12 flex items-center justify-center shrink-0">
          <IconDocumentText color="#185fa5" />
        </div>
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase">Total Setoran Terverifikasi</span>
          <span className="font-bold text-[#191c20] text-2xl tracking-tight">{formatRupiah(totalTerverifikasi)}</span>
          <div className="flex gap-1 items-center mt-1">
            <IconTrendUp className="size-3" />
            <span className="text-[#006c49] text-xs font-semibold">+{persenVsBulanLalu}% vs bulan lalu</span>
          </div>
        </div>
      </div>

      {/* Kartu 2: Total Entry Bulan Ini */}
      <div className="relative bg-white rounded-lg border border-[#d9d9d6] p-5 flex gap-4 items-start">
        <div className="bg-[#e1f5ee] rounded-xl size-12 flex items-center justify-center shrink-0">
          <IconArchiveBox color="#006c49" />
        </div>
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase">Total Entry Bulan Ini</span>
          <span className="font-bold text-[#191c20] text-2xl tracking-tight">{totalEntryBulanIni} Dokumen</span>
          <span className="text-[#424751] text-xs mt-1">Rata-rata {rataRataEntryPerHari} entry/hari</span>
        </div>
      </div>

      {/* Kartu 3: Pending Selisih */}
      <div className="relative bg-white rounded-lg border border-[#d9d9d6] p-5 flex gap-4 items-start">
        <div className="bg-[rgba(113,0,20,0.08)] rounded-xl size-12 flex items-center justify-center shrink-0">
          <IconWarningTriangle color="#710014" />
        </div>
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase">Pending Selisih</span>
          <span className="font-bold text-[#710014] text-2xl tracking-tight">{formatRupiah(pendingSelisih)}</span>
          <span className="text-[#710014] text-xs mt-1">Memerlukan pelunasan segera</span>
        </div>
      </div>
    </div>
  );
}