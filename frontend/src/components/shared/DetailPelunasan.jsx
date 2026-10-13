import { IconCheck, IconSave } from "@/components/ui/Icon";

/**
 * [PELUNASAN] Form detail pelunasan: jumlah, metode pembayaran, keterangan
 * opsional, dan upload bukti (drag & drop atau klik).
 *
 * Kontrak API §3.1 menetapkan:
 *   - `bukti` WAJIB kecuali metode === 'kpay' (untuk kpay, bukti terlarang/prohibited).
 *   - `nama_karyawan` dan `identitas_karyawan` WAJIB untuk kpay, TERLARANG untuk non-kpay.
 * Komponen ini memastikan kondisi tersebut tercermin di UI sebelum menyentuh BE.
 */
const SEMUA_METODE = ["Transfer Bank", "Tunai", "QRIS", "KPay"];

export default function DetailPelunasan({
  payments,
  onPaymentsChange,
}) {
  const updatePayment = (index, patch) => {
    onPaymentsChange(payments.map((payment, paymentIndex) => paymentIndex === index ? { ...payment, ...patch } : payment));
  };
  const handleDrop = (e, index) => {
    e.preventDefault();
    const file = e.dataTransfer.files[0];
    if (file) updatePayment(index, { buktiFile: file });
  };

  return (
    <div className="bg-white rounded-lg border border-[#d9d9d6] p-6 w-full">
      <h3 className="font-semibold text-[#191c20] text-xl mb-5">Detail Pelunasan</h3>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="lg:col-span-2 flex flex-col gap-4">
          {payments.map((payment, index) => {
            const isKpay = payment.metode === "KPay";
            return <div key={index} className="grid grid-cols-1 lg:grid-cols-2 gap-4 border border-[#efefed] rounded-lg p-4">
              <div className="flex flex-col gap-3">
                <div className="flex items-center justify-between"><span className="font-semibold text-sm">Metode {index + 1}</span>{payments.length > 1 && <button type="button" onClick={() => onPaymentsChange(payments.filter((_, i) => i !== index))} className="text-xs text-[#93000a]">Hapus</button>}</div>
                <select value={payment.metode} onChange={(e) => updatePayment(index, { metode: e.target.value, buktiFile: null, namaKaryawan: "", identitasKaryawan: "" })} className="border border-[#d9d9d6] rounded px-3 py-2.5 text-sm bg-white">
                  {SEMUA_METODE.map((m) => (
                    <option key={m} value={m} disabled={m !== payment.metode && payments.some((p) => p.metode === m)}>
                      {m === "KPay" ? "KPay (Potong Saldo)" : m}
                    </option>
                  ))}
                </select>
                <input type="text" inputMode="numeric" value={payment.jumlah} onChange={(e) => updatePayment(index, { jumlah: e.target.value.replace(/\D/g, "") })} placeholder="Nominal" className="border border-[#d9d9d6] rounded px-3 py-2.5 text-sm" />
                {isKpay && <><input value={payment.namaKaryawan} onChange={(e) => updatePayment(index, { namaKaryawan: e.target.value })} placeholder="Nama karyawan" className="border border-[#d9d9d6] rounded px-3 py-2.5 text-sm" /><input value={payment.identitasKaryawan} onChange={(e) => updatePayment(index, { identitasKaryawan: e.target.value })} placeholder="NIK, NIP, atau identitas karyawan" className="border border-[#d9d9d6] rounded px-3 py-2.5 text-sm" /></>}
                <textarea value={payment.keterangan} onChange={(e) => updatePayment(index, { keterangan: e.target.value })} placeholder="Keterangan (opsional)" rows={3} className="border border-[#d9d9d6] rounded px-3 py-2.5 text-sm resize-none" />
              </div>
              <div className="flex flex-col gap-2">
                <label className="font-semibold text-sm">{isKpay ? "Bukti tidak diperlukan untuk KPay" : "Bukti Pelunasan *"}</label>
                {!isKpay && <div onDrop={(e) => handleDrop(e, index)} onDragOver={(e) => e.preventDefault()} onClick={() => document.getElementById(`pelunasan-bukti-${index}`)?.click()} className="border border-dashed border-[#d9d9d6] rounded-lg flex flex-col items-center justify-center gap-3 py-10 px-6 cursor-pointer bg-[#f7f7f6] min-h-[180px]">
                  <input id={`pelunasan-bukti-${index}`} type="file" accept=".png,.jpg,.jpeg" className="hidden" onChange={(e) => updatePayment(index, { buktiFile: e.target.files?.[0] ?? null })} />
                  {payment.buktiFile ? <><IconCheck className="size-10" color="#16A34A" /><span className="text-sm font-medium">{payment.buktiFile.name}</span></> : <><IconSave className="size-8" color="#710014" /><span className="text-xs">PNG, JPG, JPEG (Max. 5MB)</span></>}
                </div>}
              </div>
            </div>;
          })}
          {payments.length < 4 && <button type="button" onClick={() => onPaymentsChange([...payments, { metode: SEMUA_METODE.find((m) => !payments.some((p) => p.metode === m)) ?? "Tunai", jumlah: "", keterangan: "", buktiFile: null, namaKaryawan: "", identitasKaryawan: "" }])} className="self-start border border-[#d9d9d6] px-4 py-2 rounded text-sm">+ Tambah metode pembayaran</button>}
        </div>
      </div>
    </div>
  );
}