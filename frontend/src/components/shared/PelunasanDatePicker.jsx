import { IconCalendar } from "@/components/ui/Icon";

/**
 * [PELUNASAN] Step pertama alur Pelunasan Setoran Lama: pilih tanggal setoran
 * lama yang sudah ada datanya, lalu klik "Muat Data" untuk ambil sisa selisih.
 *
 * <select> berisi HANYA tanggal yang dikembalikan BE lewat endpoint:
 *   GET /api/v1/teller/setoran/pelunasan?loket_id=xxx  (data.tanggal_tersedia)
 * Sehingga Bu Wiwik tidak pernah bisa memilih tanggal yang belum ada setoran.
 *
 * Props baru:
 *   tanggalTersedia  — array string tanggal dari BE, misal ["2026-07-24", ...]
 *   loadingTanggal   — true saat sedang fetch daftar tanggal dari BE
 */
export default function PelunasanDatePicker({
  tanggal,
  onTanggalChange,
  onMuatData,
  tanggalTersedia = [],
  loadingTanggal = false,
}) {
  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] p-5 w-full">
      <p className="text-[#424751] text-sm font-medium mb-3">Pilih Tanggal Setoran</p>
      <div className="flex flex-col sm:flex-row gap-3 items-start sm:items-center">

        {/* Dropdown — hanya berisi tanggal yang sudah ada data setorannya */}
        <div className="relative">
          <select
            value={tanggal}
            onChange={(e) => onTanggalChange(e.target.value)}
            disabled={loadingTanggal || tanggalTersedia.length === 0}
            className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30 disabled:opacity-50 disabled:cursor-not-allowed appearance-none pr-8 min-w-[200px]"
          >
            <option value="">
              {loadingTanggal
                ? "Memuat tanggal..."
                : tanggalTersedia.length === 0
                ? "Tidak ada data setoran"
                : "— Pilih tanggal —"}
            </option>
            {tanggalTersedia.map((tgl) => (
              <option key={tgl} value={tgl}>
                {tgl}
              </option>
            ))}
          </select>
          <IconCalendar className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[#727782] size-4" />
        </div>

        <button
          onClick={onMuatData}
          disabled={!tanggal || loadingTanggal}
          className="bg-[#710014] text-white px-5 py-2 rounded text-sm font-medium hover:bg-[#5a000f] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          Muat Data
        </button>
      </div>

      {/* Pesan kalau tidak ada data sama sekali untuk cabang ini */}
      {!loadingTanggal && tanggalTersedia.length === 0 && (
        <p className="text-[#727782] text-xs mt-2">
          Belum ada setoran tersimpan untuk cabang ini.
        </p>
      )}
    </div>
  );
}