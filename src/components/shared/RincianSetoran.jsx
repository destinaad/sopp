import { useState, useEffect } from "react";
import RincianSection from "@/components/shared/RincianSection";
import KpayRow from "@/components/shared/KpayRow";
import { IconPlus } from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";

export default function RincianSetoran({ onTotalChange, onRowsChange, initialData }) {
  const [transferRows, setTransferRows] = useState(
    initialData?.transfer ?? [{ id: 1, keterangan: "", nominal: "", bukti: null, verified: false }]
  );
  const [qrisRows, setQrisRows] = useState(initialData?.qris ?? []);
  const [tunaiRows, setTunaiRows] = useState(
    initialData?.tunai ?? [{ id: 1, keterangan: "", nominal: "", bukti: null, verified: false }]
  );
  const [kpayRows, setKpayRows] = useState(initialData?.kpay ?? []);

  const makeRowHandlers = (setter) => ({
    add: (extraFields = {}) =>
      setter((rows) => [...rows, { id: Date.now(), keterangan: "", nominal: "", bukti: null, verified: false, ...extraFields }]),
    field: (id, key, v) => setter((rows) => rows.map((r) => (r.id === id ? { ...r, [key]: v } : r))),
    keterangan: (id, v) => setter((rows) => rows.map((r) => (r.id === id ? { ...r, keterangan: v } : r))),
    nominal: (id, v) => setter((rows) => rows.map((r) => (r.id === id ? { ...r, nominal: v } : r))),
    bukti: (id, v) => setter((rows) => rows.map((r) => (r.id === id ? { ...r, bukti: v } : r))),
    toggleVerified: (id) => setter((rows) => rows.map((r) => (r.id === id ? { ...r, verified: !r.verified } : r))),
    del: (id) => setter((rows) => rows.filter((r) => r.id !== id)),
  });

  const tr = makeRowHandlers(setTransferRows);
  const qr = makeRowHandlers(setQrisRows);
  const tu = makeRowHandlers(setTunaiRows);
  const kp = makeRowHandlers(setKpayRows);

  const sumRows = (rows) => rows.reduce((sum, r) => sum + (parseInt(r.nominal, 10) || 0), 0);

  const transferSubtotal = sumRows(transferRows);
  const qrisSubtotal = sumRows(qrisRows);
  const tunaiSubtotal = sumRows(tunaiRows);
  const kpaySubtotal = sumRows(kpayRows);
  const totalSetor = transferSubtotal + qrisSubtotal + tunaiSubtotal + kpaySubtotal;

  // Setiap subtotal berubah, kabari page (kalau page peduli lewat onTotalChange)
  useEffect(() => {
    onTotalChange?.(totalSetor);
  }, [totalSetor, onTotalChange]);

  // Setiap salah satu dari 4 array baris berubah, kirim SELURUH isinya ke
  // page lewat onRowsChange — dipakai page untuk menyusun payload saveDraft/submit.
  useEffect(() => {
    onRowsChange?.({ transfer: transferRows, qris: qrisRows, tunai: tunaiRows, kpay: kpayRows });
  }, [transferRows, qrisRows, tunaiRows, kpayRows, onRowsChange]);

  return (
    <div className="flex flex-col gap-6 w-full">
      <h2 className="font-semibold text-[#191c20] text-xl">Rincian Setoran</h2>

      <RincianSection
        title="TRANSFER"
        addLabel="+ Tambah Transaksi Transfer"
        addColor="#004782"
        rows={transferRows}
        onAdd={() => tr.add()}
        onKeteranganChange={tr.keterangan}
        onNominalChange={tr.nominal}
        onBuktiChange={tr.bukti}
        onToggleVerified={tr.toggleVerified}
        onDelete={tr.del}
      />

      <RincianSection
        title="QRIS"
        addLabel="+ Tambah Transaksi QRIS"
        addColor="#006c49"
        rows={qrisRows}
        onAdd={() => qr.add()}
        onKeteranganChange={qr.keterangan}
        onNominalChange={qr.nominal}
        onBuktiChange={qr.bukti}
        onToggleVerified={qr.toggleVerified}
        onDelete={qr.del}
        emptyMessage="Belum ada transaksi — klik **+ Tambah Transaksi** untuk mulai"
      />

      <RincianSection
        title="TUNAI"
        addLabel="+ Tambah Transaksi Tunai"
        addColor="#15803d"
        rows={tunaiRows}
        onAdd={() => tu.add()}
        onKeteranganChange={tu.keterangan}
        onNominalChange={tu.nominal}
        onBuktiChange={tu.bukti}
        onToggleVerified={tu.toggleVerified}
        onDelete={tu.del}
      />

      {/* KPAY punya struktur baris beda (Nama Karyawan + NIK), jadi tidak pakai RincianSection */}
      <div className="relative bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
        <div className="bg-[#efefed] border-b border-[#c2c6d2] flex items-center justify-between px-6 py-2">
          <span className="font-bold text-[#191c20] text-base">KPAY (POTONG SALDO)</span>
          <span className="font-semibold text-[#424751] text-xs tracking-[0.6px]">
            Subtotal: {formatRupiah(kpaySubtotal)}
          </span>
        </div>
        <div className="flex flex-col gap-2 p-4">
          {kpayRows.map((row) => (
            <KpayRow
              key={row.id}
              row={row}
              onNamaChange={(v) => kp.field(row.id, "nama", v)}
              onNikChange={(v) => kp.field(row.id, "nik", v)}
              onKeteranganChange={(v) => kp.keterangan(row.id, v)}
              onNominalChange={(v) => kp.nominal(row.id, v)}
              onBuktiChange={(v) => kp.bukti(row.id, v)}
              onToggleVerified={() => kp.toggleVerified(row.id)}
              onDelete={() => kp.del(row.id)}
            />
          ))}
          <button
            onClick={() => kp.add({ nama: "", nik: "" })}
            className="flex gap-1 items-center text-[#b45309] text-base"
          >
            <IconPlus />
            <span>+ Tambah Transaksi Kpay</span>
          </button>
        </div>
      </div>
    </div>
  );
}