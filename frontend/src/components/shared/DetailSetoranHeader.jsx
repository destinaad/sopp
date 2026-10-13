import { IconCalendar, IconBuilding, IconUser, IconClockHistory } from "@/components/ui/Icon";
import { formatTanggalPanjang } from "@/utils/formatDate";

/**
 * Label aksi dalam bahasa Indonesia, sesuai nilai-nilai yang dikirim API.
 * Hanya 4 nilai valid per kontrak: input, edit, hapus, pelunasan.
 */
const LABEL_AKSI = {
  input: "Input",
  edit: "Edit",
  hapus: "Hapus",
  pelunasan: "Pelunasan",
};

/**
 * Format ISO waktu menjadi "HH:mm WIB" ringkas untuk tampilan audit trail.
 */
function formatJam(iso) {
  const d = new Date(iso);
  const jam = d.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
  return `${jam} WIB`;
}

/**
 * [DETAIL] Info bar di bagian atas halaman Detail Setoran: tanggal, loket,
 * dan riwayat petugas dari array audit trail `detail.petugas`.
 *
 * Props:
 *   tanggal — ISO date string (tanggal_transaksi).
 *   loket   — nama loket (string, "-" kalau loket sudah dihapus).
 *   petugas — array [{ nama, aksi, waktu }] dari API, diurutkan ascending.
 *             Sebelumnya: string tunggal nama_teller. Sekarang: array penuh.
 */
export default function DetailSetoranHeader({ tanggal, loket, petugas }) {
  // Normalise: kalau petugas masih dikirim sebagai string (backward-compat),
  // bungkus jadi array satu elemen agar rendering tetap aman.
  const petugasList = Array.isArray(petugas)
    ? petugas
    : petugas
    ? [{ nama: petugas, aksi: "input", waktu: null }]
    : [];

  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] w-full">
      <div className="flex flex-wrap gap-8 items-start px-6 py-4">
        {/* Tanggal Transaksi */}
        <div className="flex gap-2 items-center">
          <IconCalendar className="size-5 shrink-0 mt-0.5" color="#710014" />
          <div className="flex flex-col">
            <span className="text-[#727782] text-xs font-medium">Tanggal</span>
            <span className="font-semibold text-[#191c20] text-sm">{formatTanggalPanjang(tanggal)}</span>
          </div>
        </div>

        {/* Loket */}
        <div className="flex gap-2 items-center">
          <IconBuilding className="size-5 shrink-0 mt-0.5" color="#710014" />
          <div className="flex flex-col">
            <span className="text-[#727782] text-xs font-medium">Loket</span>
            <span className="font-semibold text-[#191c20] text-sm">{loket}</span>
          </div>
        </div>

        {/* Petugas — audit trail dari detail.petugas[] */}
        <div className="flex gap-2 items-start">
          {petugasList.length === 1 ? (
            <IconUser className="size-5 shrink-0 mt-0.5" color="#710014" />
          ) : (
            <IconClockHistory className="size-5 shrink-0 mt-0.5" />
          )}
          <div className="flex flex-col gap-1">
            <span className="text-[#727782] text-xs font-medium">
              {petugasList.length <= 1 ? "Petugas" : "Riwayat Petugas"}
            </span>
            {petugasList.length === 0 ? (
              <span className="font-semibold text-[#191c20] text-sm">—</span>
            ) : (
              petugasList.map((p, i) => (
                <div key={i} className="flex items-baseline gap-1.5">
                  <span className="font-semibold text-[#191c20] text-sm">{p.nama}</span>
                  {(p.aksi || p.waktu) && (
                    <span className="text-[#727782] text-xs">
                      {p.aksi ? `(${LABEL_AKSI[p.aksi] ?? p.aksi})` : ""}
                      {p.waktu ? ` · ${formatJam(p.waktu)}` : ""}
                    </span>
                  )}
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}