import { useState } from "react";
import { IconCheck, IconSave } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * [PELUNASAN] Kartu latar pink yang muncul setelah "Muat Data" ditekan,
 * menampilkan sisa selisih tanggal yang dipilih + tombol salin nominal.
 */
export default function SisaSelisihCard({ sisaSelisih }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = () => {
    navigator.clipboard.writeText(String(sisaSelisih));
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  };

  return (
    <div className="bg-[rgba(113,0,20,0.06)] border border-[rgba(113,0,20,0.15)] rounded-lg px-6 py-5 flex items-center justify-between w-full">
      <div className="flex flex-col gap-1">
        <span className="font-semibold text-[#710014] text-xs tracking-[0.6px] uppercase">Sisa Selisih</span>
        <span className="font-bold text-[#710014] text-3xl tracking-tight">{formatRupiah(sisaSelisih)}</span>
      </div>
      <button
        onClick={handleCopy}
        title="Salin nominal"
        className="bg-white border border-[rgba(113,0,20,0.2)] rounded-lg p-3 text-[#710014] hover:bg-[rgba(113,0,20,0.05)] transition-colors"
      >
        {copied ? <IconCheck /> : <IconSave />}
      </button>
    </div>
  );
}