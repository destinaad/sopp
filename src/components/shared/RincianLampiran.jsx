import { useState } from "react";
import Modal from "@/components/ui/Modal";
import { IconBankTransfer, IconQrCode, IconWallet, IconKpay, IconEye } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

/**
 * [DETAIL] Satu baris bukti. `label` opsional (dipakai Transfer untuk nomor
 * urut "#1"/"#2" karena transaksinya banyak & mirip-mirip, perlu pembeda) —
 * QRIS/Tunai cukup nominal + Lihat Bukti saja, tanpa label deskriptif.
 */
function BuktiItem({ label, nominal, onLihat }) {
  return (
    <div className="flex items-center justify-between bg-[#f7f7f6] rounded px-4 py-3">
      <span className="text-sm text-[#191c20]">
        {label && <span className="text-[#727782]">{label} </span>}
        <span className="font-semibold">{formatRupiah(nominal)}</span>
      </span>
      <button onClick={onLihat} className="flex items-center gap-1 text-[#710014] text-xs font-semibold hover:opacity-80 transition-opacity">
        Lihat Bukti
        <IconEye className="size-3.5" />
      </button>
    </div>
  );
}

function SectionLabel({ icon, children }) {
  return (
    <div className="flex items-center gap-1.5">
      {icon}
      <span className="font-semibold text-[#727782] text-xs tracking-[0.6px] uppercase">{children}</span>
    </div>
  );
}

/**
 * [DETAIL] Kartu "Rincian Lampiran": daftar bukti Transfer/QRIS/Tunai, plus
 * tabel Nama/Nominal untuk KPAY. Klik "Lihat Bukti" membuka modal preview
 * (sementara placeholder, karena upload file sungguhan belum ada).
 */
export default function RincianLampiran({ lampiran }) {
  const [modalLabel, setModalLabel] = useState(null);

  return (
    <>
      <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
        <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3">
          <span className="font-bold text-[#191c20] text-base">Rincian Lampiran</span>
        </div>

        <div className="flex flex-col gap-5 p-5">
          <div className="flex flex-col gap-2">
            <SectionLabel icon={<IconBankTransfer className="size-4" color="#710014" />}>Transfer</SectionLabel>
            {lampiran.transfer.map((item) => (
              <BuktiItem key={item.label} label={item.label} nominal={item.nominal} onLihat={() => setModalLabel(`Transfer ${item.label}`)} />
            ))}
          </div>

          <div className="flex flex-col gap-2">
            <SectionLabel icon={<IconQrCode className="size-4" color="#710014" />}>QRIS</SectionLabel>
            {lampiran.qris.map((item, i) => (
              <BuktiItem key={`qr-${i}`} nominal={item.nominal} onLihat={() => setModalLabel(`QRIS #${i + 1}`)} />
            ))}
          </div>

          <div className="flex flex-col gap-2">
            <SectionLabel icon={<IconWallet className="size-4" color="#710014" />}>Tunai</SectionLabel>
            {lampiran.tunai.map((item, i) => (
              <BuktiItem key={`tu-${i}`} nominal={item.nominal} onLihat={() => setModalLabel(`Tunai #${i + 1}`)} />
            ))}
          </div>

          <div className="flex flex-col gap-2">
            <SectionLabel icon={<IconKpay className="size-4" color="#710014" />}>KPAY</SectionLabel>
            <p className="text-[#9ca3af] text-xs italic">"{lampiran.kpayKeterangan}"</p>
            <div className="border border-[#efefed] rounded-lg overflow-hidden">
              <div className="flex justify-between bg-[#f9fafb] px-3 py-2 border-b border-[#efefed]">
                <span className="font-semibold text-[#727782] text-xs">Nama</span>
                <span className="font-semibold text-[#727782] text-xs">Nominal</span>
              </div>
              {lampiran.kpayKaryawan.map((k, i) => (
                <div
                  key={k.nik}
                  className={`flex justify-between px-3 py-2.5 ${i < lampiran.kpayKaryawan.length - 1 ? "border-b border-[#efefed]" : ""}`}
                >
                  <span className="text-[#374151] text-sm">{k.nama}</span>
                  <span className="text-[#191c20] text-sm font-semibold tabular-nums">{formatRupiah(k.nominal ?? 0)}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

      {modalLabel && (
        <Modal title={`Bukti — ${modalLabel}`} onClose={() => setModalLabel(null)}>
          <div className="bg-[#f3f4f6] rounded-lg h-52 flex items-center justify-center text-[#9ca3af] text-sm">
            Preview bukti tidak tersedia
          </div>
        </Modal>
      )}
    </>
  );
}