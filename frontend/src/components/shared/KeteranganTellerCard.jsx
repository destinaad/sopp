import { IconShieldOutline } from "@/components/ui/Icon";
import { formatDateWithDay } from "@/utils/formatDate";

/**
 * [DETAIL] Kartu "Keterangan Teller" — waktu submit.
 * Styling pink-tinted mengikuti desain (bukan warning, cuma penanda "catatan
 * dari teller yang bersangkutan").
 */
export default function KeteranganTellerCard({ waktu }) {
  const tanggal = formatDateWithDay(waktu);
  const jam = new Date(waktu).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });

  return (
    <div className="relative bg-[rgba(113,0,20,0.05)] border border-[rgba(113,0,20,0.15)] rounded-lg p-5 w-full overflow-hidden">
      <IconShieldOutline className="absolute top-4 right-4 size-8 text-[#710014]/15" />

      <span className="font-bold text-[#191c20] text-base">Keterangan Teller</span>

      <p className="text-[#424751] text-sm font-medium mt-3">
        {tanggal}, {jam} WIB
      </p>
    </div>
  );
}