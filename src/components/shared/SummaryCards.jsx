import Card from "@/components/ui/Card";
import StatusBadge from "@/components/shared/StatusBadge";
import { IconTrendUp } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

export default function SummaryCards({ summary }) {
  if (!summary) return null;
  const { pendapatanHariIni, pendapatanStatus, persenVsKemarin, pendapatanKemarinStatus, statusSetoran, selisih } = summary;
  const selisihTidakDiketahui = selisih === null;
  const selisihColor = selisihTidakDiketahui ? "#727782" : selisih === 0 ? "#006c49" : "#ba1a1a";
  const bridgeGagal = pendapatanStatus === "gagal_dimuat";
  const dataBelumTerbaru = summary.dataTerknisi === false;

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
              {dataBelumTerbaru && (
                <span className="text-[#9a6700] text-xs tracking-[0.6px]">
                  Data mungkin belum terbaru
                </span>
              )}

              <div className="flex gap-1 items-center pt-1">
                <IconTrendUp />
                <span className="font-semibold text-[#006c49] text-xs tracking-[0.6px] whitespace-nowrap">
                  {pendapatanKemarinStatus === "gagal_dimuat"
                  ? "Data tidak bisa dimuat"
                  : persenVsKemarin === null
                  ? "Kemarin tidak ada pendapatan"
                  : `${persenVsKemarin}% vs Kemarin`}
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
            {selisihTidakDiketahui ? "Tidak diketahui" : formatRupiah(selisih)}
          </span>
          <span className="font-semibold italic text-xs tracking-[0.6px]" style={{ color: selisihColor }}>
            {selisihTidakDiketahui ? "Data pendapatan gagal dimuat" : selisih === 0 ? "Data Seimbang" : "Ada Selisih"}
          </span>
        </div>
      </Card>
    </div>
  );
}