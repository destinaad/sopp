import Card from "@/components/ui/Card";
import StatusBadge from "@/components/shared/StatusBadge";
import { IconTrendUp } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * 3 kartu ringkasan di paling atas dashboard teller:
 * pendapatan hari ini, status setoran, dan selisih.
 * Nilai-nilainya datang dari `summary` — di-map dari response getDashboard()
 * (lihat pages/teller/Dashboard.jsx) supaya nama field di sini tetap netral
 * (camelCase), tidak terikat ke bentuk snake_case API.
 *
 * persenVsKemarin BISA null (mis. pendapatan kemarin = 0, jadi persentase
 * kenaikan/penurunan tidak bisa dihitung) — sesuai kontrak API §7.1, kalau
 * null WAJIB tampil "---", bukan "null%" atau "0%".
 *
 * pendapatanStatus BISA "gagal_dimuat" — artinya bridge.php gagal diakses
 * BE saat menyusun response ini. Field pendapatan_sopp tetap ada (biasanya
 * 0 atau data basi), tapi TIDAK BOLEH ditampilkan sebagai angka yang
 * meyakinkan — kontrak minta pola konsisten: tunjukkan "Gagal dimuat",
 * bukan Rp 0 yang bisa disalahartikan sebagai "pendapatan hari ini
 * memang nol".
 */
export default function SummaryCards({ summary }) {
  if (!summary) return null;
  const { pendapatanHariIni, pendapatanStatus, persenVsKemarin, statusSetoran, selisih } = summary;
  const selisihColor = selisih === 0 ? "#006c49" : "#ba1a1a";
  const bridgeGagal = pendapatanStatus === "gagal_dimuat";

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 w-full">
      <Card>
        <div className="flex flex-col gap-1 p-6">
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase">PENDAPATAN HARI INI</span>
          {bridgeGagal ? (
            <>
              <span className="font-semibold text-[#93000a] text-2xl tracking-tight">Gagal dimuat</span>
              <span className="text-[#93000a] text-xs tracking-[0.6px] pt-1">
                Sistem SOPP tidak dapat diakses saat ini
              </span>
            </>
          ) : (
            <>
              <span className="font-semibold text-[#710014] text-2xl tracking-tight">{formatRupiah(pendapatanHariIni)}</span>
              <div className="flex gap-1 items-center pt-1">
                <IconTrendUp />
                <span className="font-semibold text-[#006c49] text-xs tracking-[0.6px] whitespace-nowrap">
                  {persenVsKemarin === null ? "---" : `${persenVsKemarin}%`} vs Kemarin
                </span>
              </div>
            </>
          )}
        </div>
      </Card>

      <Card>
        <div className="flex flex-col gap-4 p-6 h-full">
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase">STATUS SETORAN</span>
          <StatusBadge status={statusSetoran} />
        </div>
      </Card>

      <Card className="sm:col-span-2 lg:col-span-1">
        <div className="flex flex-col gap-1 p-6">
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase">SELISIH</span>
          <span className="font-semibold text-2xl tracking-tight" style={{ color: selisihColor }}>
            {formatRupiah(selisih)}
          </span>
          <span className="font-semibold italic text-xs tracking-[0.6px]" style={{ color: selisihColor }}>
            {selisih === 0 ? "Data Seimbang" : "Ada Selisih"}
          </span>
        </div>
      </Card>
    </div>
  );
}