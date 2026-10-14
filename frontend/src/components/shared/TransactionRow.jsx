import { useRef, useState } from "react";
import { IconUpload, IconTrash, IconCheck, IconCheckGray } from "@/components/ui/Icon";

// Batas BE: foto_bukti `max:5120` (KB) = 5 MB. Dicek di FE supaya teller
// langsung tahu saat memilih file, bukan baru setelah request ditolak 422.
const MAKS_UKURAN_FOTO_BYTE = 5 * 1024 * 1024;

/**
 * Satu baris form: input Keterangan + Nominal + tombol upload bukti + tombol
 * verifikasi manual + tombol hapus. Dipakai berulang di RincianSection
 * (Transfer, QRIS, Tunai).
 *
 * Tombol upload: file picker via <input type="file"> tersembunyi + useRef
 * (pola sama dengan DetailPelunasan.jsx). Karena ruang di baris ini sempit
 * (bukan dropzone besar), feedback filenya cuma lewat warna icon (biru saat
 * ada file) + tooltip (`title`) berisi nama file saat mouse hover.
 *
 * Tombol centang (`onToggleVerified`) BUKAN indikator otomatis dari nominal —
 * ini verifikasi manual teller: dicentang setelah teller cek bukti transfer
 * di grup WhatsApp teller dan yakin dananya benar sudah masuk.
 */
export default function TransactionRow({
  row,
  onKeteranganChange,
  onNominalChange,
  onBuktiChange,
  onToggleVerified,
  onDelete,
  readOnly = false,
  metode,
  onMetodeChange,
}) {
  const fileInputRef = useRef(null);
  const [errorFile, setErrorFile] = useState("");
  // row.bukti = File (baru dipilih) ATAU string URL (foto lama dari draft).
  const namaBukti = row.bukti instanceof File ? row.bukti.name : row.bukti;

  const handleFileSelected = (e) => {
    const file = e.target.files?.[0];
    // Simpan File-nya, BUKAN file.name: buildSetoranFormData cuma mengirim
    // foto_bukti kalau bukti instanceof File, jadi kalau cuma nama yang
    // disimpan fotonya tidak pernah terkirim (error "foto bukti wajib diisi").
    if (file) {
      if (file.size > MAKS_UKURAN_FOTO_BYTE) {
        // Ditolak: bukti yang sudah ada (kalau ada) dibiarkan apa adanya.
        setErrorFile(`Ukuran foto ${(file.size / 1024 / 1024).toFixed(1)} MB melebihi batas 5 MB. Pilih foto yang lebih kecil.`);
      } else {
        setErrorFile("");
        onBuktiChange(file);
      }
    }
    e.target.value = ""; // reset supaya bisa pilih file yang sama lagi kalau perlu ganti
  };

  return (
    <>
      <div className="flex gap-3 items-center border-b border-[rgba(194,198,210,0.3)] pb-2 pt-2">
        {onMetodeChange && (
          <select
            value={metode}
            onChange={(e) => onMetodeChange(e.target.value)}
            disabled={readOnly}
            className="shrink-0 bg-[#efefed] border border-[#d9d9d6] rounded-sm px-2.5 py-2.5 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
          >
            <option value="transfer">Transfer</option>
            <option value="qris">QRIS</option>
            <option value="tunai">Tunai</option>
            <option value="kpay">KPay</option>
          </select>
        )}
        <input
          type="text"
          placeholder="Keterangan"
          value={row.keterangan}
          onChange={(e) => onKeteranganChange(e.target.value)}
          disabled={readOnly}
          className="flex-1 min-w-0 bg-[#efefed] border border-[#d9d9d6] rounded-sm px-4 py-2.5 text-sm text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30 disabled:opacity-75 disabled:cursor-not-allowed"
        />
        <div className="relative shrink-0 w-44">
          <span className="absolute left-3 top-1/2 -translate-y-1/2 text-[#424751] text-sm select-none pointer-events-none">Rp</span>
          <input
            type="text"
            inputMode="numeric"
            placeholder="0"
            value={row.nominal}
            onChange={(e) => onNominalChange(e.target.value.replace(/\D/g, ""))}
            disabled={readOnly}
            className="w-full bg-[#efefed] border border-[#d9d9d6] rounded-sm pl-9 pr-4 py-2.5 text-sm text-right text-[#191c20] placeholder-[#6b7280] focus:outline-none focus:ring-2 focus:ring-[#710014]/30 disabled:opacity-75 disabled:cursor-not-allowed"
          />
        </div>

        {!readOnly && (
          <>
            <input
              ref={fileInputRef}
              type="file"
              accept=".png,.jpg,.jpeg"
              className="hidden"
              onChange={handleFileSelected}
            />
            <button
              type="button"
              className={`shrink-0 flex items-center justify-center size-9 rounded border transition-colors ${
                row.bukti
                  ? "bg-[#e8f0fe] border-[#185fa5] text-[#185fa5] hover:bg-[#d2e3fc]"
                  : "bg-white border-[#d9d9d6] text-[#424751] hover:bg-[#f7f7f6] hover:border-[#b0b3b8]"
              }`}
              onClick={() => fileInputRef.current?.click()}
              aria-label={row.bukti ? `Bukti terlampir: ${namaBukti}` : "Upload bukti"}
              title={row.bukti ? `Bukti: ${namaBukti} — klik untuk ganti` : "Klik untuk upload bukti"}
            >
              <IconUpload color={row.bukti ? "#185fa5" : "#424751"} />
            </button>
          </>
        )}

        <button
          type="button"
          className={`shrink-0 flex items-center justify-center size-9 rounded border transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
            row.verified
              ? "bg-[#e6f4ea] border-[#16a34a] text-[#16a34a] hover:bg-[#ceead6]"
              : "bg-white border-[#d9d9d6] text-[#727782] hover:bg-[#f7f7f6] hover:border-[#b0b3b8]"
          }`}
          onClick={onToggleVerified}
          disabled={readOnly}
          aria-label={row.verified ? "Batalkan verifikasi" : "Verifikasi bukti transfer"}
          title={row.verified ? "Sudah diverifikasi — klik untuk batalkan" : "Klik untuk verifikasi bukti transfer"}
        >
          {row.verified ? <IconCheck /> : <IconCheckGray />}
        </button>

        {!readOnly && (
          <button
            type="button"
            className="shrink-0 flex items-center justify-center size-9 rounded border border-transparent hover:border-[#d9d9d6] hover:bg-[#f7f7f6] transition-colors"
            onClick={onDelete}
            aria-label="Hapus"
            title="Hapus baris"
          >
            <IconTrash />
          </button>
        )}
      </div>

      {errorFile && (
        <p role="alert" className="text-xs text-[#b3261e] pb-1">
          {errorFile}
        </p>
      )}
    </>
  );
}