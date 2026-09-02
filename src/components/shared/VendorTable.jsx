import { IconInfo } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * Tabel "Data Pendapatan SOPP" (provider Finnet, PUSKUD, JNE, dst) di
 * halaman Input Setoran.
 *
 * `rows` — array data_pendapatan_sopp dari respons GET /teller/setoran
 * (kontrak Card A §1): [{ vendor_id, vendor_name, kategori, total }].
 * BISA null kalau bridge SOPP gagal dimuat (pendapatan_sopp_status:
 * "gagal_dimuat") — komponen ini menampilkan pesan gagal, bukan tabel
 * kosong, supaya teller tidak salah kira "memang tidak ada pendapatan".
 *
 * `total` — total_pendapatan dari respons yang sama. BISA null dengan
 * alasan yang sama seperti rows.
 *
 * Sebelumnya komponen ini mengambil data langsung dari mocks/setoran.js
 * lewat import — sekarang datanya diteruskan lewat props dari
 * InputSetoran.jsx, supaya komponen ini netral terhadap sumber data
 * (tidak peduli dummy atau API asli).
 */
export default function VendorTable({ rows, total, gagalDimuat }) {
  return (
    <div className="relative bg-[rgba(0,71,130,0.05)] rounded-lg border border-[rgba(0,71,130,0.2)] w-full overflow-hidden">
      <div className="bg-white border-b border-[rgba(0,71,130,0.2)] flex gap-1 items-center px-6 py-2">
        <IconInfo />
        <span className="text-[#191c20] text-sm font-medium">Data berikut diambil otomatis dari sistem SOPP</span>
      </div>

      {gagalDimuat ? (
        <div className="px-6 py-8 text-center">
          <p className="text-[#93000a] text-sm font-semibold">Data pendapatan SOPP gagal dimuat.</p>
          <p className="text-[#727782] text-sm mt-1">Sistem SOPP lama tidak dapat diakses saat ini. Coba refresh beberapa saat lagi.</p>
        </div>
      ) : (
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
              {(rows ?? []).map((row, i) => (
                <tr key={`${row.vendor_id}-${i}`} className={`border border-[#d9d9d6] ${i % 2 === 1 ? "bg-[#f7f7f6]" : "bg-white"}`}>
                  <td className="font-medium text-sm text-black px-6 py-4 whitespace-nowrap">{row.vendor_name}</td>
                  <td className="text-sm text-black px-6 py-4">{row.kategori}</td>
                  <td className="text-sm text-black text-right px-6 py-4 whitespace-nowrap">{formatRupiah(row.total)}</td>
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
      )}
    </div>
  );
}