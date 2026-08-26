import { useRef } from "react";
import { IconCheck, IconSave } from "@/components/ui/Icon";

/**
 * [PELUNASAN] Form detail pelunasan: jumlah, metode pembayaran, keterangan
 * opsional, dan upload bukti (drag & drop atau klik).
 */
export default function DetailPelunasan({
  jumlah,
  onJumlahChange,
  metode,
  onMetodeChange,
  keterangan,
  onKeteranganChange,
  buktiFile,
  onBuktiChange,
}) {
  const fileInputRef = useRef(null);

  const handleDrop = (e) => {
    e.preventDefault();
    const file = e.dataTransfer.files[0];
    if (file) onBuktiChange(file);
  };

  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] p-6 w-full">
      <h3 className="font-semibold text-[#191c20] text-xl mb-5">Detail Pelunasan</h3>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Kolom kiri: form fields */}
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <label className="font-semibold text-[#424751] text-sm">Jumlah Pelunasan</label>
            <div className="relative">
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-[#424751] text-sm select-none pointer-events-none">Rp.</span>
              <input
                type="text"
                inputMode="numeric"
                value={jumlah}
                onChange={(e) => onJumlahChange(e.target.value.replace(/\D/g, ""))}
                placeholder="0"
                className="w-full border border-[#d9d9d6] rounded px-3 py-2.5 pl-9 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
              />
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="font-semibold text-[#424751] text-sm">Metode</label>
            <select
              value={metode}
              onChange={(e) => onMetodeChange(e.target.value)}
              className="border border-[#d9d9d6] rounded px-3 py-2.5 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30 appearance-none"
            >
              <option value="Transfer Bank">Transfer Bank</option>
              <option value="Tunai">Tunai</option>
              <option value="QRIS">QRIS</option>
            </select>
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="font-semibold text-[#424751] text-sm">Keterangan (Opsional)</label>
            <textarea
              value={keterangan}
              onChange={(e) => onKeteranganChange(e.target.value)}
              placeholder="Contoh: Pembayaran selisih kasir shift pagi..."
              rows={4}
              className="border border-[#d9d9d6] rounded px-3 py-2.5 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30 resize-none"
            />
          </div>
        </div>

        {/* Kolom kanan: upload bukti */}
        <div className="flex flex-col gap-1.5">
          <label className="font-semibold text-[#424751] text-sm">Bukti Pelunasan (Opsional)</label>
          <div
            onDrop={handleDrop}
            onDragOver={(e) => e.preventDefault()}
            onClick={() => fileInputRef.current?.click()}
            className="border border-dashed border-[#d9d9d6] rounded-lg flex flex-col items-center justify-center gap-3 py-10 px-6 cursor-pointer bg-[#f7f7f6] hover:bg-[#efefed] transition-colors h-full min-h-[180px]"
          >
            <input
              ref={fileInputRef}
              type="file"
              accept=".png,.jpg,.jpeg,.img"
              className="hidden"
              onChange={(e) => onBuktiChange(e.target.files?.[0] ?? null)}
            />
            {buktiFile ? (
              <div className="flex flex-col items-center gap-2 text-center">
                <IconCheck className="size-10" color="#16A34A" />
                <span className="text-[#191c20] text-sm font-medium">{buktiFile.name}</span>
                <button
                  onClick={(e) => { e.stopPropagation(); onBuktiChange(null); }}
                  className="text-[#710014] text-xs underline"
                >
                  Hapus file
                </button>
              </div>
            ) : (
              <>
                <div className="bg-[#efefed] rounded-xl size-14 flex items-center justify-center">
                  <IconSave className="size-8" color="#710014" />
                </div>
                <div className="text-center">
                  <p className="text-[#191c20] text-sm font-medium">Klik untuk upload atau drag &amp; drop</p>
                  <p className="text-[#424751] text-xs mt-1">PNG, JPG, JPEG atau IMG (Max. 5MB)</p>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}