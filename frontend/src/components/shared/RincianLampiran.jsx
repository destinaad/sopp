import { useState } from "react";
import Modal from "@/components/ui/Modal";
import { IconBankTransfer, IconQrCode, IconWallet, IconKpay, IconEye } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

function BuktiItem({ label, nominal, fotoUrl, onLihat, verified }) {
  return (
    <div className="flex items-center justify-between bg-[#f7f7f6] rounded px-4 py-3">
      <span className="text-sm text-[#191c20]">
        {label && <span className="text-[#727782]">{label} </span>}
        <span className="font-semibold">{formatRupiah(nominal)}</span>
        <StatusVerifikasi verified={verified} />
      </span>
      {/* Tombol "Lihat Bukti" hanya muncul kalau foto_bukti_url tidak null
          — kontrak §3.2: kpay biasanya null, jangan render tombol kosong */}
      {fotoUrl && (
        <button
          onClick={onLihat}
          className="flex items-center gap-1 text-[#710014] text-xs font-semibold hover:opacity-80 transition-opacity"
        >
          Lihat Bukti
          <IconEye className="size-3.5" />
        </button>
      )}
    </div>
  );
}

function StatusVerifikasi({ verified }) {
  if (verified) {
    return (
      <span className="ml-2 inline-flex items-center rounded-full bg-[#e6f4ea] px-2 py-0.5 text-xs font-medium text-[#137333]">
        ✓ Terverifikasi
      </span>
    );
  }
  return (
    <span className="ml-2 inline-flex items-center rounded-full bg-[#efefed] px-2 py-0.5 text-xs font-medium text-[#727782]">
      Belum diverifikasi
    </span>
  );
}

function SectionLabel({ icon, children }) {
  return (
    <div className="flex items-center gap-1.5">
      {icon}
      <span className="font-semibold text-[#727782] text-xs">{children}</span>
    </div>
  );
}

/**
 * Menerima entries[] flat dari response API GET /teller/setoran/{id}
 * (bukan shape mock lama { transfer, qris, tunai, kpayKaryawan }).
 * Klik "Lihat Bukti" membuka modal dengan <img> langsung dari foto_bukti_url
 * — URL ini sudah siap pakai sebagai src, tidak perlu diproses lagi (kontrak §2).
 */
export default function RincianLampiran({ entries = [] }) {
  // { url: string, label: string } | null
  const [modalBukti, setModalBukti] = useState(null);

  // Pisahkan entries[] flat berdasarkan metode_pembayaran
  const transfer = entries.filter((e) => e.metode_pembayaran === "transfer");
  const qris     = entries.filter((e) => e.metode_pembayaran === "qris");
  const tunai    = entries.filter((e) => e.metode_pembayaran === "tunai");
  const kpay     = entries.filter((e) => e.metode_pembayaran === "kpay");

  return (
    <>
      <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
        <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3">
          <span className="font-bold text-[#191c20] text-base">Rincian Lampiran</span>
        </div>

        <div className="flex flex-col gap-5 p-5">
          {/* Transfer */}
          <div className="flex flex-col gap-2">
            <SectionLabel icon={<IconBankTransfer className="size-4" color="#710014" />}>
              Transfer
            </SectionLabel>
            {transfer.length === 0 ? (
              <p className="text-[#9ca3af] text-xs italic">Tidak ada transaksi transfer.</p>
            ) : (
              transfer.map((item, i) => (
                <BuktiItem
                  key={item.id}
                  label={`#${i + 1}`}
                  nominal={item.nominal}
                  verified={item.is_konfirmasi}
                  fotoUrl={item.foto_bukti_url}
                  onLihat={() => setModalBukti({ url: item.foto_bukti_url, label: `Transfer #${i + 1}` })}
                />
              ))
            )}
          </div>

          {/* QRIS */}
          <div className="flex flex-col gap-2">
            <SectionLabel icon={<IconQrCode className="size-4" color="#710014" />}>
              QRIS
            </SectionLabel>
            {qris.length === 0 ? (
              <p className="text-[#9ca3af] text-xs italic">Tidak ada transaksi QRIS.</p>
            ) : (
              qris.map((item, i) => (
                <BuktiItem
                  key={item.id}
                  nominal={item.nominal}
                  verified={item.is_konfirmasi}
                  fotoUrl={item.foto_bukti_url}
                  onLihat={() => setModalBukti({ url: item.foto_bukti_url, label: `QRIS #${i + 1}` })}
                />
              ))
            )}
          </div>

          {/* Tunai */}
          <div className="flex flex-col gap-2">
            <SectionLabel icon={<IconWallet className="size-4" color="#710014" />}>
              Tunai
            </SectionLabel>
            {tunai.length === 0 ? (
              <p className="text-[#9ca3af] text-xs italic">Tidak ada transaksi tunai.</p>
            ) : (
              tunai.map((item, i) => (
                <BuktiItem
                  key={item.id}
                  nominal={item.nominal}
                  verified={item.is_konfirmasi}
                  fotoUrl={item.foto_bukti_url}
                  onLihat={() => setModalBukti({ url: item.foto_bukti_url, label: `Tunai #${i + 1}` })}
                />
              ))
            )}
          </div>

          {/* KPay */}
          {kpay.length > 0 && (
            <div className="flex flex-col gap-2">
              <SectionLabel icon={<IconKpay className="size-4" color="#710014" />}>
                KPAY
              </SectionLabel>
              <div className="border border-[#efefed] rounded-lg overflow-hidden">
                <div className="flex justify-between bg-[#f9fafb] px-3 py-2 border-b border-[#efefed]">
                  <span className="font-semibold text-[#727782] text-xs">Nama</span>
                  <span className="font-semibold text-[#727782] text-xs">Nominal</span>
                </div>
                {kpay.map((k, i) => (
                  <div
                    key={k.id}
                    className={`flex justify-between px-3 py-2.5 ${i < kpay.length - 1 ? "border-b border-[#efefed]" : ""}`}
                  >
                    <span className="text-[#374151] text-sm">
                      {k.nama_karyawan}
                      <StatusVerifikasi verified={k.is_konfirmasi} />
                    </span>
                    <span className="text-[#191c20] text-sm font-semibold tabular-nums">
                      {formatRupiah(k.nominal)}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Modal preview foto — src langsung dari foto_bukti_url (kontrak §2:
          URL ini sudah final, FE tidak perlu proses apapun) */}
      {modalBukti && (
        <Modal title={`Bukti — ${modalBukti.label}`} onClose={() => setModalBukti(null)}>
          <img
            src={modalBukti.url}
            alt={`Bukti ${modalBukti.label}`}
            className="w-full rounded-lg object-contain max-h-[70vh]"
            onError={(e) => {
              // Foto ada di DB tapi file fisik hilang dari server (kontrak §4.2)
              // atau akses ditolak — ganti dengan pesan, jangan biarkan broken image
              e.target.style.display = "none";
              e.target.nextSibling.style.display = "flex";
            }}
          />
          <div
            style={{ display: "none" }}
            className="bg-[#f3f4f6] rounded-lg h-52 flex items-center justify-center text-[#9ca3af] text-sm"
          >
            Foto tidak dapat dimuat.
          </div>
        </Modal>
      )}
    </>
  );
}