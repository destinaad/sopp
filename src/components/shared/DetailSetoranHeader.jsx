import { IconCalendar, IconBuilding, IconUser } from "@/components/ui/Icon";
import { formatTanggalPanjang } from "@/utils/formatDate";

/**
 * [DETAIL] Info bar di bagian atas halaman Detail Setoran: tanggal, loket,
 * dan petugas yang mencatat setoran itu. Tidak ada badge status di kanan
 * (konsep "Closed" sengaja tidak dipakai di aplikasi ini).
 */
export default function DetailSetoranHeader({ tanggal, loket, petugas }) {
  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] w-full">
      <div className="flex flex-wrap gap-8 items-center px-6 py-4">
        <div className="flex gap-2 items-center">
          <IconCalendar className="size-5" color="#710014" />
          <div className="flex flex-col">
            <span className="text-[#727782] text-xs font-medium uppercase tracking-[0.6px]">Tanggal</span>
            <span className="font-semibold text-[#191c20] text-sm">{formatTanggalPanjang(tanggal)}</span>
          </div>
        </div>

        <div className="flex gap-2 items-center">
          <IconBuilding className="size-5" color="#710014" />
          <div className="flex flex-col">
            <span className="text-[#727782] text-xs font-medium uppercase tracking-[0.6px]">Loket</span>
            <span className="font-semibold text-[#191c20] text-sm">{loket}</span>
          </div>
        </div>

        <div className="flex gap-2 items-center">
          <IconUser className="size-5" color="#710014" />
          <div className="flex flex-col">
            <span className="text-[#727782] text-xs font-medium uppercase tracking-[0.6px]">Petugas</span>
            <span className="font-semibold text-[#191c20] text-sm">{petugas}</span>
          </div>
        </div>
      </div>
    </div>
  );
}