import { IconCheck, IconWarningTriangle } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * [DETAIL] Kartu "Ringkasan Setoran": breakdown per metode (Transfer/QRIS/
 * Tunai/KPAY), Total Setoran (A) vs Total Pendapatan (B), dan status
 * lunas/belum-lunas dari selisihnya.
 *
 * Props:
 *   ringkasanSetoran — { transfer, qris, tunai, kpay } dari ringkasan_setoran API.
 *   totalPendapatan  — total_pendapatan dari API; null kalau status_keuangan=gagal_dimuat.
 *   selisihSendiri   — selisih_sendiri dari API (personal, live-fetch ke bridge SOPP);
 *                      null kalau fetch gagal atau teller tidak punya identitas legacy.
 *                      REVISI: sebelumnya FE menghitung sendiri dari (B - A), sekarang
 *                      pakai nilai autoritas dari API.
 *   statusKeuangan   — 'lunas' | 'belum_lunas' | 'gagal_dimuat'. Dipakai untuk
 *                      menentukan tampilan status tanpa re-derive dari selisihSendiri.
 */
export default function RingkasanSetoranCard({
  ringkasanSetoran,
  totalPendapatan,
  selisihSendiri,
  statusKeuangan,
}) {
  const { transfer = 0, qris = 0, tunai = 0, kpay = 0 } = ringkasanSetoran ?? {};
  const totalSetoran = transfer + qris + tunai + kpay;

  // Status ditentukan dari field statusKeuangan yang dikirim API (authoritative),
  // bukan dihitung ulang dari selisih. Ini sesuai kontrak §1: FE tidak boleh
  // menghitung ulang logic finansial — pakai apa adanya dari response.
  const isGagalDimuat = statusKeuangan === "gagal_dimuat";
  const isLunas = statusKeuangan === "lunas";

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
          {/* total_pendapatan null = gagal_dimuat: jangan tampilkan Rp 0 yang menyesatkan */}
          <span className="font-bold text-[#191c20] text-sm">
            {isGagalDimuat ? <span className="text-[#727782] font-normal italic text-xs">Tidak tersedia</span> : formatRupiah(totalPendapatan)}
          </span>
        </div>
      </div>

      <div className="px-6 pb-6">
        {isGagalDimuat ? (
          // Kasus gagal_dimuat: fetch bridge gagal ATAU teller tidak punya identitas legacy.
          // Tidak bisa dibedakan dari FE — keduanya ditampilkan sama.
          <div className="flex items-center gap-3 rounded-lg px-5 py-4" style={{ backgroundColor: "#fff8e1" }}>
            <div className="rounded-full size-9 flex items-center justify-center shrink-0" style={{ backgroundColor: "#f59e0b" }}>
              <IconWarningTriangle className="size-5" color="white" />
            </div>
            <div className="flex flex-col">
              <span className="font-bold text-sm text-[#92400e]">Data Pendapatan Tidak Tersedia</span>
              <span className="text-xs text-[#92400e]">
                Gagal memuat data pendapatan dari sistem SOPP. Status keuangan tidak dapat ditentukan.
              </span>
            </div>
          </div>
        ) : (
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
                  {isLunas
                    ? "Data setoran telah sesuai dengan pendapatan"
                    : "Data setoran belum sesuai dengan pendapatan"}
                </span>
              </div>
            </div>
            <div className="flex flex-col items-end shrink-0">
              <span className="text-xs" style={{ color: isLunas ? "#006c49" : "#93000a" }}>
                Selisih
              </span>
              {/* Pakai selisih_sendiri dari API (personal, live-fetch) — bukan re-derive dari B - A */}
              <span className="font-bold text-lg" style={{ color: isLunas ? "#006c49" : "#93000a" }}>
                {formatRupiah(selisihSendiri)}
              </span>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}