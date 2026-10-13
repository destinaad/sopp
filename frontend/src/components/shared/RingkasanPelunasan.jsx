import { IconArrowLong } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * [PELUNASAN] Kartu ringkasan otomatis di bagian bawah form Pelunasan:
 * bandingkan sisa selisih sebelumnya vs jumlah yang diinput teller,
 * lalu hitung sisa selisih setelah pelunasan.
 */
export default function RingkasanPelunasan({ sisaSelisihAwal, jumlahPelunasan }) {
  const sisaSetelah = Math.max(0, sisaSelisihAwal - jumlahPelunasan);
  const isLunas = sisaSetelah === 0;

  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
      <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-4 px-6 pt-6 pb-4 border-b border-[#d9d9d6]">
        <div className="flex flex-col gap-1">
          <span className="font-semibold text-[#424751] text-xs">Sisa Selisih Sebelumnya</span>
          <span className="font-bold text-[#710014] text-2xl tracking-tight">{formatRupiah(sisaSelisihAwal)}</span>
        </div>
        <div className="flex items-center justify-center">
          <IconArrowLong />
        </div>
        <div className="flex flex-col gap-1">
          <span className="font-semibold text-[#424751] text-xs">Jumlah Pelunasan Diinput</span>
          <span className="font-bold text-[#710014] text-2xl tracking-tight">{formatRupiah(jumlahPelunasan)}</span>
        </div>
      </div>

      <div className="flex flex-col items-center gap-1 px-6 py-5">
        <span className="text-[#424751] text-sm">Sisa Selisih Setelah Pelunasan</span>
        <span className="font-bold text-3xl tracking-tight" style={{ color: isLunas ? "#16a34a" : "#710014" }}>
          {formatRupiah(sisaSetelah)}
        </span>
      </div>
    </div>
  );
}