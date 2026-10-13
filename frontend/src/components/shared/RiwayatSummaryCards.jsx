import { IconTrendUp } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * [RIWAYAT] 3 kartu ringkasan di bagian bawah halaman Riwayat Setoran:
 * total setoran terverifikasi, total entry bulan ini, dan pending selisih.
 */
export default function RiwayatSummaryCards({ ringkasan }) {
  const safeRingkasan = ringkasan ?? {};
  const totalLunas = safeRingkasan.total_setoran_lunas ?? 0;
  const persen = safeRingkasan.persentase_vs_bulan_lalu;
  const pending = safeRingkasan.pending_selisih ?? 0;
  const totalEntry = safeRingkasan.total_entry_bulan_ini ?? 0;
  const rataRata = safeRingkasan.rata_rata_entry_per_hari ?? 0;

  return (
    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 w-full">
      {/* Kartu 1: Total Setoran Terverifikasi */}
      <div className="relative bg-white rounded-lg border border-[#d9d9d6] p-5 flex flex-col">
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs">Total Setoran Terverifikasi</span>
          <span className="font-bold text-[#191c20] text-2xl tracking-tight">{formatRupiah(totalLunas)}</span>
          <div className="flex gap-1 items-center mt-1">
            <IconTrendUp className="size-3" />
            <span className="text-[#006c49] text-xs font-semibold">
              {persen == null ? "Tidak ada pembanding bulan lalu" : `${persen > 0 ? `+${persen}` : persen}% vs bulan lalu`}
            </span>
          </div>
        </div>
      </div>

      {/* Kartu 2: Total Entry Bulan Ini */}
      <div className="relative bg-white rounded-lg border border-[#d9d9d6] p-5 flex flex-col">
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs">Total Entry Bulan Ini</span>
          <span className="font-bold text-[#191c20] text-2xl tracking-tight">{totalEntry} Dokumen</span>
          <span className="text-[#424751] text-xs mt-1">Rata-rata {rataRata} entry/hari</span>
        </div>
      </div>

      {/* Kartu 3: Pending Selisih */}
      <div className="relative bg-white rounded-lg border border-[#d9d9d6] p-5 flex flex-col">
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs">Pending Selisih</span>
          <span className="font-bold text-[#710014] text-2xl tracking-tight">{formatRupiah(pending)}</span>
          <span className="text-[#710014] text-xs mt-1">Memerlukan pelunasan segera</span>
        </div>
      </div>
    </div>
  );
}