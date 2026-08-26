import { useAuthStore } from "@/store/authStore";

export default function InfoTransaksi({ loket }) {
  const user = useAuthStore((s) => s.user);
  const namaLoket = loket ?? user.loket;

  const displayDate = new Date().toLocaleDateString("id-ID", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });

  return (
    <div className="relative bg-white rounded border border-[#d9d9d6] w-full">
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4">
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase">Tanggal Transaksi</span>
          <span className="font-semibold text-black text-sm">{displayDate}</span>
        </div>
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase">Loket</span>
          <span className="font-semibold text-black text-sm">{namaLoket}</span>
        </div>
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase">Teller</span>
          <span className="font-semibold text-black text-sm">{user.name}</span>
        </div>
      </div>
    </div>
  );
}