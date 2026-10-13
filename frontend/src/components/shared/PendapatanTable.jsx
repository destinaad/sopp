import { formatRupiah } from "@/utils/formatRupiah";

/**
 * [DETAIL] Tabel "Total Pendapatan" di halaman Detail Setoran: daftar
 * provider/layanan + nominal, ditutup dengan bar total merah tua.
 * Beda dari VendorTable.jsx (dipakai di Input Setoran) — di sini tiap baris
 * punya 2 baris teks (nama provider + daftar layanan di bawahnya).
 */
export default function PendapatanTable({ rows }) {
  // rows === null artinya backend tidak punya data pendapatan (akun bukan
  // teller SOPP, atau bridge gagal dimuat) -- BEDA dari [] (teller ada, tapi
  // tidak ada transaksi). Jangan dianggap Rp 0 supaya tidak menyesatkan.
  const tersedia = Array.isArray(rows);
  const daftar = tersedia ? rows : [];
  const total = daftar.reduce((sum, r) => sum + r.nominal, 0);

  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
      <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3">
        <span className="font-bold text-[#191c20] text-base">Total Pendapatan</span>
      </div>

      <div className="grid grid-cols-[1fr_auto] bg-[#f7f7f6] border-b border-[#d9d9d6] px-6 py-2">
        <span className="font-semibold text-[#727782] text-xs tracking-[0.6px] uppercase">Provider / Layanan</span>
        <span className="font-semibold text-[#727782] text-xs tracking-[0.6px] uppercase">Nominal (Rp)</span>
      </div>

      {!tersedia && (
        <div className="px-6 py-6 text-sm text-[#727782] border-b border-[#d9d9d6]">
          Data pendapatan tidak tersedia (akun ini tidak terhubung ke sistem SOPP lama, atau data gagal dimuat).
        </div>
      )}

      {daftar.map((row, i) => (
        <div
          key={row.id ?? row.provider}
          className={`grid grid-cols-[1fr_auto] items-center px-6 py-4 border-b border-[#d9d9d6] ${i % 2 === 1 ? "bg-[#f7f7f6]" : "bg-white"}`}
        >
          <div className="flex flex-col gap-0.5">
            <span className="font-bold text-[#191c20] text-sm">{row.provider}</span>
            <span className="text-[#9ca3af] text-xs">{row.layanan}</span>
          </div>
          <span className="text-[#191c20] text-base">{row.nominal.toLocaleString("id-ID")}</span>
        </div>
      ))}

      <div className="bg-[#710014] flex items-center justify-between px-6 py-4">
        <span className="font-bold text-white text-sm">Total Pendapatan</span>
        <span className="font-bold text-white text-xl">{tersedia ? formatRupiah(total) : "—"}</span>
      </div>
    </div>
  );
}