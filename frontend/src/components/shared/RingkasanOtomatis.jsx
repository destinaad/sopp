import { IconBadgeCheck } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * Kotak ringkasan otomatis di bagian bawah form Input Setoran:
 * bandingkan Total Setor (input teller) vs Total Pendapatan SOPP (data sistem),
 * lalu hitung selisihnya.
 *
 * totalSetor & totalPendapatanSOPP dihitung di page (InputSetoran.jsx),
 * komponen ini murni presentasi.
 */
export default function RingkasanOtomatis({ totalSetor, totalPendapatanSOPP, selisih }) {
  const pendapatanTersedia = totalPendapatanSOPP !== null && totalPendapatanSOPP !== undefined;
  const nilaiSelisih = pendapatanTersedia && selisih != null ? selisih : null;
  const isSeimbang = nilaiSelisih === 0;

  return (
    <div className="relative bg-white rounded-lg border border-[#d9d9d6] w-full">
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-6 px-6 py-8">
        <div className="flex flex-col gap-1">
          <span className="font-medium text-[#424751] text-sm">Total setor</span>
          <span className="font-bold text-[#191c20] text-3xl tracking-tight">{formatRupiah(totalSetor)}</span>
        </div>
        <div className="flex flex-col gap-1 sm:pl-8 sm:border-l border-[rgba(194,198,210,0.3)]">
          <span className="font-medium text-[#424751] text-sm">Total pendapatan SOPP</span>
          <span className="font-bold text-[#191c20] text-3xl tracking-tight">
            {pendapatanTersedia ? formatRupiah(totalPendapatanSOPP) : "Tidak tersedia"}
          </span>
        </div>
        <div className="flex flex-col gap-1 sm:pl-8 sm:border-l border-[rgba(194,198,210,0.3)]">
          <div className="flex gap-2 items-center">
          <span className="font-medium text-[#424751] text-sm">Selisih (perkiraan, belum resmi)</span>
            {isSeimbang && <IconBadgeCheck />}
          </div>
          <span
            className="font-bold text-3xl tracking-tight"
            style={{ color: isSeimbang ? "#16a34a" : nilaiSelisih === null ? "#727782" : "#ba1a1a" }}
          >
            {nilaiSelisih === null ? "Tidak tersedia" : formatRupiah(nilaiSelisih)}
          </span>
        </div>
      </div>
    </div>
  );
}