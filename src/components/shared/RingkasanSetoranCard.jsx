import { IconCheck } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * [DETAIL] Kartu "Ringkasan Setoran": breakdown per metode (Transfer/QRIS/
 * Tunai/KPAY), Total Setoran (A) vs Total Pendapatan (B), dan status
 * lunas/belum-lunas dari selisihnya.
 */
export default function RingkasanSetoranCard({ ringkasanSetoran, totalPendapatan }) {
  const { transfer, qris, tunai, kpay } = ringkasanSetoran;
  const totalSetoran = transfer + qris + tunai + kpay;
  const selisih = totalPendapatan - totalSetoran;
  const isLunas = selisih === 0;

  const baris = [
    { label: "Transfer Bank", nominal: transfer },
    { label: "QRIS Settlement", nominal: qris },
    { label: "Tunai / Cash", nominal: tunai },
    { label: "Kpay (Potong Saldo)", nominal: kpay },
  ];

  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
      <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3">
        <span className="font-bold text-[#191c20] text-base">Ringkasan Setoran</span>
      </div>

      <div className="flex flex-col px-6">
        {baris.map((b) => (
          <div key={b.label} className="flex items-center justify-between py-3.5 border-b border-[#efefed]">
            <span className="text-[#424751] text-sm">{b.label}</span>
            <span className="text-[#191c20] text-sm">{formatRupiah(b.nominal)}</span>
          </div>
        ))}

        <div className="flex items-center justify-between py-3.5 border-b border-[#efefed]">
          <span className="font-bold text-[#191c20] text-sm">Total Setoran (A)</span>
          <span className="font-bold text-[#191c20] text-sm">{formatRupiah(totalSetoran)}</span>
        </div>
        <div className="flex items-center justify-between py-3.5">
          <span className="font-bold text-[#191c20] text-sm">Total Pendapatan (B)</span>
          <span className="font-bold text-[#191c20] text-sm">{formatRupiah(totalPendapatan)}</span>
        </div>
      </div>

      <div className="px-6 pb-6">
        <div
          className="flex items-center justify-between gap-4 rounded-lg px-5 py-4"
          style={{ backgroundColor: isLunas ? "#e1f5ee" : "#ffdad6" }}
        >
          <div className="flex gap-3 items-center">
            <div
              className="rounded-full size-9 flex items-center justify-center shrink-0"
              style={{ backgroundColor: isLunas ? "#006c49" : "#ba1a1a" }}
            >
              <IconCheck className="size-5" color="white" />
            </div>
            <div className="flex flex-col">
              <span className="font-bold text-sm" style={{ color: isLunas ? "#006c49" : "#93000a" }}>
                {isLunas ? "Lunas" : "Belum Lunas"}
              </span>
              <span className="text-xs" style={{ color: isLunas ? "#006c49" : "#93000a" }}>
                {isLunas ? "Data setoran telah sesuai dengan pendapatan" : "Data setoran belum sesuai dengan pendapatan"}
              </span>
            </div>
          </div>
          <div className="flex flex-col items-end shrink-0">
            <span className="text-xs uppercase tracking-[0.6px]" style={{ color: isLunas ? "#006c49" : "#93000a" }}>
              Selisih
            </span>
            <span className="font-bold text-lg" style={{ color: isLunas ? "#006c49" : "#93000a" }}>
              {formatRupiah(selisih)}
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}