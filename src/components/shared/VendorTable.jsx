import { IconInfo } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";
import { dataPendapatanSOPP, getTotalPendapatanSOPP } from "@/mocks/setoran";

/**
 * Tabel "Data Pendapatan SOPP" (provider Finnet, PUSKUD, JNE) di halaman
 * Input Setoran. Ini file yang sudah direncanakan di struktur folder awalmu
 * sebagai VendorTable.jsx.
 */
export default function VendorTable() {
  const total = getTotalPendapatanSOPP();

  return (
    <div className="relative bg-[rgba(0,71,130,0.05)] rounded-lg border border-[rgba(0,71,130,0.2)] w-full overflow-hidden">
      <div className="bg-white border-b border-[rgba(0,71,130,0.2)] flex gap-1 items-center px-6 py-2">
        <IconInfo />
        <span className="text-[#191c20] text-sm font-medium">Data berikut diambil otomatis dari sistem SOPP</span>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[480px] border border-[#d9d9d6]">
          <thead>
            <tr className="bg-[#efefed]">
              <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-left px-6 py-4 whitespace-nowrap">PROVIDER</th>
              <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-left px-6 py-4 whitespace-nowrap">KATEGORI TRANSAKSI</th>
              <th className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase text-right px-6 py-4 whitespace-nowrap">NOMINAL</th>
            </tr>
          </thead>
          <tbody>
            {dataPendapatanSOPP.map((row, i) => (
              <tr key={row.provider} className={`border border-[#d9d9d6] ${i % 2 === 1 ? "bg-[#f7f7f6]" : "bg-white"}`}>
                <td className="font-medium text-sm text-black px-6 py-4 whitespace-nowrap">{row.provider}</td>
                <td className="text-sm text-black px-6 py-4">{row.kategori}</td>
                <td className="text-sm text-black text-right px-6 py-4 whitespace-nowrap">{formatRupiah(row.nominal)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="bg-[#efefed] border-t border-[#d9d9d6]">
              <td colSpan={2} className="font-semibold text-[#710014] text-xl px-6 py-4">Total Pendapatan</td>
              <td className="font-semibold text-[#710014] text-xl text-right px-6 py-4 whitespace-nowrap">{formatRupiah(total)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );
}
