import { useState, useEffect, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import Badge from "@/components/ui/Badge";
import Modal from "@/components/ui/Modal";
import {
  IconDownload,
  IconEye,
  IconSpinner,
  IconRefresh,
  IconChevronLeftOutline,
  IconChevronRightOutline,
} from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";
import useLaporan from "@/hooks/useLaporan";
import { downloadLaporanLoket, exportLaporan } from "@/api/admin";
import { getLokets } from "@/api/teller";
import { tanggalLokal } from "@/utils/tanggal";
import BannerNotif from "@/components/shared/BannerNotif";

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

const today = tanggalLokal();
const thisMonth = today.slice(0, 7);

// Request export/download memakai responseType "blob", jadi isi error 422 dari
// server datang sebagai Blob dan harus dibaca dulu sebelum bisa ditampilkan.
async function pesanDariError(err, fallback) {
  try {
    const data = err?.response?.data;
    const json = data instanceof Blob ? JSON.parse(await data.text()) : data;
    return (
      json?.errors?.tanggal_akhir?.[0] ??
      json?.errors?.loket_id?.[0] ??
      json?.message ??
      fallback
    );
  } catch {
    return fallback;
  }
}

function formatTanggalIndo(isoStr) {
  if (!isoStr) return "-";
  const [y, m, d] = isoStr.split("-");
  const bulan = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"];
  return `${d} ${bulan[parseInt(m, 10) - 1]} ${y}`;
}

function formatBulanIndo(yyyyMM) {
  if (!yyyyMM) return "-";
  const [y, m] = yyyyMM.split("-");
  const bulan = ["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
  return `${bulan[parseInt(m, 10) - 1]} ${y}`;
}

function buildNamaFileLaporan(namaLoket, modePeriode, { tanggal, bulan, dari, sampai }) {
  let periode = "";
  if (modePeriode === "harian") periode = formatTanggalIndo(tanggal);
  else if (modePeriode === "bulanan") periode = formatBulanIndo(bulan);
  else periode = `${formatTanggalIndo(dari)} - ${formatTanggalIndo(sampai)}`;
  return `Laporan SOPP ${namaLoket} ${periode}.xlsx`;
}

function sumRows(rows, key) {
  return rows.reduce((acc, r) => acc + (r[key] ?? 0), 0);
}

// ─────────────────────────────────────────────────────────────
// StatusBadge lokal
// ─────────────────────────────────────────────────────────────
function StatusLaporanBadge({ status }) {
  if (status === "lunas") {
    return (
      <Badge variant="outline" bgColor="#e1f5ee" borderColor="#006c49" textColor="#006c49">
        Lunas
      </Badge>
    );
  }
  return (
    <Badge variant="outline" bgColor="#ffdad6" borderColor="#ba1a1a" textColor="#93000a">
      Belum Lunas
    </Badge>
  );
}

// ─────────────────────────────────────────────────────────────
// TH / TD style helpers
// ─────────────────────────────────────────────────────────────
const TH = ({ children, right = false, center = false, colSpan }) => (
  <th
    colSpan={colSpan}
    className={`font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-3 py-3 whitespace-nowrap border-b border-[#d9d9d6] ${
      right ? "text-right" : center ? "text-center" : "text-left"
    }`}
  >
    {children}
  </th>
);

const TD = ({ children, right = false, center = false, bold = false, className = "" }) => (
  <td
    className={`px-3 py-3 text-sm whitespace-nowrap ${right ? "text-right" : center ? "text-center" : "text-left"} ${bold ? "font-semibold" : ""} ${className}`}
  >
    {children}
  </td>
);

const THGroup = ({ children, colSpan }) => (
  <th
    colSpan={colSpan}
    className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-3 py-3 text-center border-b border-r border-[#d9d9d6] last:border-r-0 bg-[#f0f0ee]"
  >
    {children}
  </th>
);

// ─────────────────────────────────────────────────────────────
// Baris total (footer tabel)
// ─────────────────────────────────────────────────────────────
function TotalRow({ vendors = [], totalRow = null, rows = [], showLoketCol = false }) {
  const vendorTotals = vendors.map((v) => {
    if (totalRow?.vendors && totalRow.vendors[v] !== undefined) {
      return totalRow.vendors[v];
    }
    if (totalRow && totalRow[v] !== undefined) {
      return totalRow[v];
    }
    return rows.reduce((acc, r) => acc + (r.vendors?.[v] ?? r[v] ?? 0), 0);
  });

  const jumlahTotal = totalRow?.total_pendapatan_sopp ?? totalRow?.jumlahTotal ?? sumRows(rows, "total_pendapatan_sopp");
  const transfer = totalRow?.metode_setoran?.transfer ?? totalRow?.transfer ?? rows.reduce((acc, r) => acc + (r.metode_setoran?.transfer ?? r.transfer ?? 0), 0);
  const qris = totalRow?.metode_setoran?.qris ?? totalRow?.qris ?? rows.reduce((acc, r) => acc + (r.metode_setoran?.qris ?? r.qris ?? 0), 0);
  const tunai = totalRow?.metode_setoran?.tunai ?? totalRow?.tunai ?? rows.reduce((acc, r) => acc + (r.metode_setoran?.tunai ?? r.tunai ?? 0), 0);
  const kpay = totalRow?.metode_setoran?.kpay ?? totalRow?.kpay ?? rows.reduce((acc, r) => acc + (r.metode_setoran?.kpay ?? r.kpay ?? 0), 0);
  const jumlahSetor = totalRow?.total_setor ?? totalRow?.jumlahSetor ?? sumRows(rows, "total_setor");
  const selisih = totalRow?.selisih !== undefined ? totalRow.selisih : sumRows(rows, "selisih");

  return (
    <tr className="bg-[#f0f0ee] font-semibold border-t-2 border-[#c2c6d2]">
      <TD bold className="text-[#191c20]">{showLoketCol ? "TOTAL SEMUA" : "TOTAL"}</TD>
      {vendors.map((v, idx) => (
        <TD key={v} right bold className="text-[#191c20]">
          {formatRupiah(vendorTotals[idx])}
        </TD>
      ))}
      <TD right bold className="text-[#710014]">{formatRupiah(jumlahTotal)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(transfer)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(qris)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(tunai)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(kpay)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(jumlahSetor)}</TD>
      <TD right bold className={selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}>
        {formatRupiah(selisih)}
      </TD>
      <td />
      {showLoketCol && <td />}
    </tr>
  );
}

// ─────────────────────────────────────────────────────────────
// Paginasi
// ─────────────────────────────────────────────────────────────
function PaginasiTabel({ page, totalPages, onChange }) {
  if (totalPages <= 1) return null;
  return (
    <div className="flex items-center justify-between px-6 py-3 border-t border-[#d9d9d6] bg-white">
      <span className="text-[#727782] text-xs font-medium">
        Halaman {page} dari {totalPages}
      </span>
      <div className="flex gap-2">
        <button
          onClick={() => onChange(Math.max(1, page - 1))}
          disabled={page === 1}
          className="flex items-center gap-1 px-3 py-1.5 rounded border border-[#d9d9d6] text-xs font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >
          <IconChevronLeftOutline className="size-3.5" />
          Sebelumnya
        </button>
        <button
          onClick={() => onChange(Math.min(totalPages, page + 1))}
          disabled={page === totalPages}
          className="flex items-center gap-1 px-3 py-1.5 rounded border border-[#d9d9d6] text-xs font-medium text-[#424751] bg-white hover:bg-[#f7f7f6] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >
          Selanjutnya
          <IconChevronRightOutline className="size-3.5" />
        </button>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// TABEL BENTUK A — Semua Loket (Skenario 1, 3, 5)
// ─────────────────────────────────────────────────────────────
function TabelBentukA({ rows, allRows, vendors = [], totalRow = null, onDetailClick, onDownload, loadingDownload }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[1100px]">
        <thead className="bg-[#f7f7f6]">
          <tr>
            <TH>Loket</TH>
            <TH colSpan={vendors.length > 0 ? vendors.length : 1} center>TRANSAKSI (Rp)</TH>
            <TH right>Jumlah Total</TH>
            <TH colSpan={4} center>DISETOR (Rp)</TH>
            <TH right>Jumlah Disetor</TH>
            <TH right>Selisih</TH>
            <TH center>Detail</TH>
            <TH center>Download</TH>
          </tr>
          <tr className="bg-[#fafafa] text-[11px]">
            <td />
            {vendors.map((v) => (
              <TH key={v}>{v}</TH>
            ))}
            <td />
            <TH>Transfer</TH>
            <TH>QRIS</TH>
            <TH>Tunai</TH>
            <TH>KPAY</TH>
            <td />
            <td />
            <td />
            <td />
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr
              key={row.loket_id ?? row.nama_loket}
              className={`border-b border-[#f0f0ee] ${i % 2 === 1 ? "bg-[#fafafa]" : "bg-white"} hover:bg-[#f7f3f3] transition-colors`}
            >
              <TD bold className="text-[#191c20]">{row.nama_loket}</TD>
              {vendors.map((v) => (
                <TD key={v} right className="text-[#424751]">
                  {formatRupiah(row.vendors?.[v] ?? row[v])}
                </TD>
              ))}
              <TD right bold className="text-[#191c20]">{formatRupiah(row.total_pendapatan_sopp ?? row.jumlahTotal)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.metode_setoran?.transfer ?? row.transfer)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.metode_setoran?.qris ?? row.qris)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.metode_setoran?.tunai ?? row.tunai)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.metode_setoran?.kpay ?? row.kpay)}</TD>
              <TD right bold className="text-[#191c20]">{formatRupiah(row.total_setor ?? row.jumlahSetor)}</TD>
              <TD right bold className={row.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}>
                {row.selisih === null ? "—" : formatRupiah(row.selisih)}
              </TD>
              <td className="px-3 py-3 text-center">
                <TombolDetailDrillDown
                  tellerAktif={(row.drill_down_teller ?? []).filter((t) => t.setoran_id !== null)}
                  bisaDetail={(row.drill_down_teller ?? []).some((t) => t.setoran_id !== null)}
                  onNavigate={onDetailClick}
                />
              </td>
              <td className="px-3 py-3 text-center">
                <button
                  onClick={() => onDownload?.(row.loket_id, row.nama_loket)}
                  disabled={loadingDownload === row.loket_id}
                  title={`Unduh Excel — ${row.nama_loket}`}
                  className="inline-flex items-center gap-1 text-[#424751] text-xs font-medium hover:text-[#191c20] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                >
                  <IconDownload className="size-3.5" />
                  <span>{loadingDownload === row.loket_id ? "Mengunduh..." : "Excel"}</span>
                </button>
              </td>
            </tr>
          ))}
          <TotalRow vendors={vendors} totalRow={totalRow} rows={allRows} showLoketCol />
        </tbody>
      </table>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// TABEL BENTUK B — 1 Loket, per Tanggal (Skenario 2, 6)
// ─────────────────────────────────────────────────────────────
function TabelBentukB({ rows, allRows, totalHari, vendors = [], totalRow = null, namaLoket, onDetailClick }) {
  return (
    <div className="overflow-x-auto">
      <div className="px-4 py-3 border-b border-[#d9d9d6] bg-[#f7f7f6]">
        <span className="text-sm text-[#424751]">
          Menampilkan <strong>{totalHari ?? allRows.length}</strong> hari aktif untuk{" "}
          <strong className="text-[#191c20]">{namaLoket}</strong>
        </span>
      </div>
      <table className="w-full min-w-[1100px]">
        <thead className="bg-[#f7f7f6]">
          <tr>
            <TH>Tanggal</TH>
            <TH colSpan={vendors.length > 0 ? vendors.length : 1} center>TRANSAKSI (Rp)</TH>
            <TH right>Jumlah Total</TH>
            <TH colSpan={4} center>DISETOR (Rp)</TH>
            <TH right>Jumlah Disetor</TH>
            <TH right>Selisih</TH>
            <TH center>Detail</TH>
          </tr>
          <tr className="bg-[#fafafa] text-[11px]">
            <td />
            {vendors.map((v) => (
              <TH key={v}>{v}</TH>
            ))}
            <td />
            <TH>Transfer</TH>
            <TH>QRIS</TH>
            <TH>Tunai</TH>
            <TH>KPAY</TH>
            <td />
            <td />
            <td />
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => {
            // drill_down_teller: hitung entry yang punya setoran_id
            const drillTeller = row.drill_down_teller ?? [];
            const tellerAktif = drillTeller.filter((t) => t.setoran_id !== null);
            const bisaDetail = tellerAktif.length > 0;

            return (
              <tr
                key={row.tanggal}
                className={`border-b border-[#f0f0ee] ${i % 2 === 1 ? "bg-[#fafafa]" : "bg-white"} hover:bg-[#f7f3f3] transition-colors`}
              >
                <TD bold className="text-[#191c20]">{formatTanggalIndo(row.tanggal)}</TD>
                {vendors.map((v) => (
                  <TD key={v} right className="text-[#424751]">
                    {formatRupiah(row.vendors?.[v] ?? row[v])}
                  </TD>
                ))}
                <TD right bold className="text-[#191c20]">{formatRupiah(row.total_pendapatan_sopp ?? row.jumlahTotal)}</TD>
                <TD right className="text-[#424751]">{formatRupiah(row.metode_setoran?.transfer ?? row.transfer)}</TD>
                <TD right className="text-[#424751]">{formatRupiah(row.metode_setoran?.qris ?? row.qris)}</TD>
                <TD right className="text-[#424751]">{formatRupiah(row.metode_setoran?.tunai ?? row.tunai)}</TD>
                <TD right className="text-[#424751]">{formatRupiah(row.metode_setoran?.kpay ?? row.kpay)}</TD>
                <TD right bold className="text-[#191c20]">{formatRupiah(row.total_setor ?? row.jumlahSetor)}</TD>
                <TD right bold className={row.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}>
                  {row.selisih === null ? "—" : formatRupiah(row.selisih)}
                </TD>
                <td className="px-3 py-3 text-center">
                  <TombolDetailDrillDown
                    tellerAktif={tellerAktif}
                    bisaDetail={bisaDetail}
                    onNavigate={onDetailClick}
                  />
                </td>
              </tr>
            );
          })}
          <TotalRow vendors={vendors} totalRow={totalRow} rows={allRows} />
        </tbody>
      </table>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// Tombol Detail dengan logika drill_down_teller
// 0 teller aktif → disabled
// 1 teller aktif → langsung navigate
// >1 teller aktif → tampilkan dropdown pilih teller
// ─────────────────────────────────────────────────────────────
function TombolDetailDrillDown({ tellerAktif, bisaDetail, onNavigate }) {
  const [showDropdown, setShowDropdown] = useState(false);

  if (!bisaDetail) {
    return (
      <span className="text-[#9ca3af] text-xs">Belum ada</span>
    );
  }

  if (tellerAktif.length === 1) {
    return (
      <button
        onClick={() => onNavigate(tellerAktif[0].setoran_id)}
        className="inline-flex items-center gap-1 text-[#710014] text-xs font-semibold hover:opacity-75 transition-opacity"
      >
        <IconEye className="size-3.5" />
        Detail
      </button>
    );
  }

  // >1 teller — tampilkan dropdown
  return (
    <div className="relative inline-block">
      <button
        onClick={() => setShowDropdown((s) => !s)}
        className="inline-flex items-center gap-1 text-[#710014] text-xs font-semibold hover:opacity-75 transition-opacity"
      >
        <IconEye className="size-3.5" />
        Detail ({tellerAktif.length})
      </button>
      {showDropdown && (
        <div className="absolute z-30 right-0 top-full mt-1 bg-white border border-[#d9d9d6] rounded-lg shadow-lg min-w-[160px] py-1">
          {tellerAktif.map((t) => (
            <button
              key={t.setoran_id}
              onClick={() => {
                setShowDropdown(false);
                onNavigate(t.setoran_id);
              }}
              className="w-full text-left px-4 py-2 text-xs text-[#424751] hover:bg-[#f7f7f6] transition-colors"
            >
              {t.nama}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// Daftar entry per metode — dipakai TabelBentukC
// ─────────────────────────────────────────────────────────────
function DaftarEntryMetode({ entries, tampilkanBukti = true, onLihatBukti }) {
  if (!entries || entries.length === 0) {
    return (
      <p className="text-[#9ca3af] text-xs italic px-2 py-1">Tidak ada transaksi.</p>
    );
  }
  return (
    <ol className="list-decimal list-inside flex flex-col gap-1.5 py-1">
      {entries.map((entry, i) => (
        <li key={entry.id ?? i} className="text-xs text-[#424751]">
          <span className="font-semibold text-[#191c20]">{formatRupiah(entry.nominal)}</span>
          {entry.keterangan && (
            <span className="text-[#727782] ml-1">— {entry.keterangan}</span>
          )}
          {tampilkanBukti && (
            <span className="ml-2">
              {entry.foto_bukti_url ? (
                <button
                  onClick={() => onLihatBukti?.(entry.foto_bukti_url, i + 1)}
                  className="text-[#710014] font-semibold hover:opacity-75 transition-opacity"
                >
                  Lihat bukti
                </button>
              ) : (
                <span className="text-[#93000a]">Tidak ada bukti</span>
              )}
            </span>
          )}
        </li>
      ))}
    </ol>
  );
}

// ─────────────────────────────────────────────────────────────
// TABEL BENTUK C — 1 Loket + 1 Hari, rincian penuh (Skenario 4)
// ─────────────────────────────────────────────────────────────
function TabelBentukC({ detail, vendors = [] }) {
  // rincian_transaksi dari API sudah dikelompokkan per metode
  const rincian = detail.rincian_transaksi ?? {};
  const transferEntries = rincian.transfer ?? [];
  const qrisEntries     = rincian.qris ?? [];
  const tunaiEntries    = rincian.tunai ?? [];
  const kpayEntries     = rincian.kpay ?? [];

  const totalTransfer = detail.metode_setoran?.transfer ?? detail.transfer ?? 0;
  const totalQris     = detail.metode_setoran?.qris ?? detail.qris ?? 0;
  const totalTunai    = detail.metode_setoran?.tunai ?? detail.tunai ?? 0;
  const totalKpay     = detail.metode_setoran?.kpay ?? detail.kpay ?? 0;
  const jumlahDisetor = detail.total_setor ?? detail.jumlahSetor ?? 0;

  const [modalBukti, setModalBukti] = useState(null);

  return (
    <>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[1200px] border-collapse">
          <thead>
            <tr>
              <th
                rowSpan={2}
                className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-3 py-3 text-left align-middle border-b border-r border-[#d9d9d6] bg-[#f7f7f6]"
              >
                Loket
              </th>
              <THGroup colSpan={vendors.length > 0 ? vendors.length + 1 : 1}>Transaksi (Rp)</THGroup>
              <THGroup colSpan={1}>Transfer</THGroup>
              <THGroup colSpan={1}>QRIS</THGroup>
              <THGroup colSpan={1}>Tunai</THGroup>
              <THGroup colSpan={1}>KPay</THGroup>
              <THGroup colSpan={2}>Ringkasan</THGroup>
            </tr>
            <tr className="bg-[#f7f7f6] text-[11px]">
              {vendors.map((v) => (
                <TH key={v}>{v}</TH>
              ))}
              <TH right>Jml Total</TH>
              <TH right>Transfer (Rp)</TH>
              <TH right>QRIS (Rp)</TH>
              <TH right>Tunai (Rp)</TH>
              <TH right>KPay (Rp)</TH>
              <TH right>Jml Disetor</TH>
              <TH right>Selisih (Rp)</TH>
            </tr>
          </thead>
          <tbody>
            <tr className="bg-white border-b border-[#f0f0ee]">
              <TD bold className="text-[#191c20] border-r border-[#f0f0ee] align-top">
                {detail.nama_loket}
              </TD>
              {vendors.map((v) => (
                <TD key={v} right className="text-[#424751] align-top">
                  {formatRupiah(detail.vendors?.[v] ?? detail[v])}
                </TD>
              ))}
              <TD right bold className="text-[#191c20] border-r-2 border-[#d9d9d6] align-top">
                {formatRupiah(detail.total_pendapatan_sopp ?? detail.jumlahTotal)}
              </TD>

              {/* Transfer */}
              <td className="px-3 py-3 text-sm align-top border-r border-[#f0f0ee]">
                <div className="font-semibold text-[#191c20] text-right mb-1">{formatRupiah(totalTransfer)}</div>
                <DaftarEntryMetode
                  entries={transferEntries}
                  tampilkanBukti={true}
                  onLihatBukti={(url, no) => setModalBukti({ url, label: `Transfer #${no}` })}
                />
              </td>

              {/* QRIS */}
              <td className="px-3 py-3 text-sm align-top border-r border-[#f0f0ee]">
                <div className="font-semibold text-[#191c20] text-right mb-1">{formatRupiah(totalQris)}</div>
                <DaftarEntryMetode
                  entries={qrisEntries}
                  tampilkanBukti={true}
                  onLihatBukti={(url, no) => setModalBukti({ url, label: `QRIS #${no}` })}
                />
              </td>

              {/* Tunai */}
              <td className="px-3 py-3 text-sm align-top border-r border-[#f0f0ee]">
                <div className="font-semibold text-[#191c20] text-right mb-1">{formatRupiah(totalTunai)}</div>
                <DaftarEntryMetode
                  entries={tunaiEntries}
                  tampilkanBukti={true}
                  onLihatBukti={(url, no) => setModalBukti({ url, label: `Tunai #${no}` })}
                />
              </td>

              {/* KPay — tanpa bukti */}
              <td className="px-3 py-3 text-sm align-top border-r-2 border-[#d9d9d6]">
                <div className="font-semibold text-[#191c20] text-right mb-1">{formatRupiah(totalKpay)}</div>
                <DaftarEntryMetode entries={kpayEntries} tampilkanBukti={false} />
              </td>

              <TD right bold className="text-[#191c20] align-top">{formatRupiah(jumlahDisetor)}</TD>
              <TD right bold className={`align-top ${detail.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}`}>
                {detail.selisih === null ? "—" : formatRupiah(detail.selisih)}
              </TD>
            </tr>
          </tbody>
          <tfoot>
            <tr className="bg-[#f0f0ee] font-semibold border-t-2 border-[#c2c6d2]">
              <td className="px-3 py-3 text-sm border-r border-[#d9d9d6]" />
              <td colSpan={vendors.length > 0 ? vendors.length : 1} className="px-3 py-3 text-sm text-right text-[#191c20] border-r border-[#d9d9d6]">Total Transaksi</td>
              <td className="px-3 py-3 text-sm text-right font-bold text-[#191c20] border-r-2 border-[#d9d9d6]">
                {formatRupiah(detail.total_pendapatan_sopp ?? detail.jumlahTotal)}
              </td>
              <td colSpan={4} className="px-3 py-3 text-sm text-right text-[#191c20] border-r-2 border-[#d9d9d6]">
                Jumlah Disetor:{" "}
                <span className="font-bold">{formatRupiah(jumlahDisetor)}</span>
              </td>
              <td colSpan={2} className="px-3 py-3 text-sm text-right text-[#191c20]">
                Selisih:{" "}
                <span className={`font-bold ${detail.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}`}>
                  {detail.selisih === null ? "—" : formatRupiah(detail.selisih)}
                </span>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      {modalBukti && (
        <Modal title={`Bukti — ${modalBukti.label}`} onClose={() => setModalBukti(null)}>
          <img
            src={modalBukti.url}
            alt={`Bukti ${modalBukti.label}`}
            className="w-full rounded-lg object-contain max-h-[70vh]"
            onError={(e) => {
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

// ─────────────────────────────────────────────────────────────
// Info bar bentuk aktif
// ─────────────────────────────────────────────────────────────
function BentukInfo({ bentuk, loket, modePeriode, tanggal, bulan, dari, sampai, namaLoket }) {
  if (!bentuk) return null;

  const periodeLabel =
    modePeriode === "harian" ? formatTanggalIndo(tanggal)
    : modePeriode === "bulanan" ? formatBulanIndo(bulan)
    : `${formatTanggalIndo(dari)} – ${formatTanggalIndo(sampai)}`;

  const labelMap = {
    A: `Semua Loket · ${periodeLabel}`,
    B: `${namaLoket ?? loket} · ${periodeLabel}`,
    C: `${namaLoket ?? loket} · ${formatTanggalIndo(tanggal)} — Rincian Penuh`,
  };

  const colorMap = {
    A: { bg: "#e1f5ee", text: "#006c49", border: "#006c49" },
    B: { bg: "#e6f1fb", text: "#185fa5", border: "#185fa5" },
    C: { bg: "#fef3c7", text: "#92400e", border: "#f59e0b" },
  };

  const c = colorMap[bentuk];

  return (
    <div
      className="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium border"
      style={{ backgroundColor: c.bg, color: c.text, borderColor: c.border }}
    >
      <span>{labelMap[bentuk]}</span>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// KOMPONEN UTAMA: Laporan
// ─────────────────────────────────────────────────────────────
// Pesan banner untuk gagal memuat data laporan. Untuk 422 pakai alasan dari BE
// (mis. "Rentang tanggal maksimal 366 hari.") supaya tidak cuma "Gagal memuat".
function pesanErrorLaporan(err) {
  const status = err?.response?.status;
  if (status === 429) return "Tunggu beberapa detik sebelum refresh lagi.";
  if (status === 403) return "Tidak memiliki akses ke halaman laporan.";
  if (status === 422) {
    const errs = err.response.data?.errors;
    const pertama = errs ? Object.values(errs).flat()[0] : null;
    return pertama || err.response.data?.message || "Filter laporan tidak valid.";
  }
  return "Gagal memuat data laporan.";
}

export default function Laporan() {
  const navigate = useNavigate();

  const [daftarLoket, setDaftarLoket] = useState([]);
  const [modePeriode, setModePeriode] = useState("harian");
  const [tanggal, setTanggal] = useState(today);
  const [bulan, setBulan] = useState(thisMonth);
  const [dari, setDari] = useState("");
  const [sampai, setSampai] = useState("");
  const [loketId, setLoketId] = useState(null); // null = semua loket
  const [tellerId, setTellerId] = useState("");
  const [page, setPage] = useState(1);
  const [loadingDownload, setLoadingDownload] = useState(null);
  const [loadingExport, setLoadingExport] = useState(false);
  // { tipe: "error" | "peringatan", pesan } -- banner di halaman, pengganti alert()
  const [notif, setNotif] = useState(null);

  // Tanggal tidak boleh di masa depan, dan untuk rentang "Sampai" baru bisa dipilih
  // setelah "Dari" terisi (BE mode=rentang memang mewajibkan keduanya, dan
  // tanggal_akhir >= tanggal_mulai). min/max menutup date picker; guard di handler
  // menutup ketikan manual yang lolos dari min/max.
  const handleTanggal = (v) => {
    if (v && v > today) return;
    setTanggal(v);
  };
  const handleBulan = (v) => {
    if (v && v > thisMonth) return;
    setBulan(v);
  };
  const handleDari = (v) => {
    if (v && v > today) return;
    setDari(v);
    // Dari dikosongkan atau digeser melewati Sampai -> Sampai ikut dikosongkan
    if (!v || (sampai && v > sampai)) setSampai("");
  };
  const handleSampai = (v) => {
    if (v && (v > today || (dari && v < dari))) return;
    setSampai(v);
  };

  useEffect(() => {
    getLokets()
      .then((res) => {
        if (res?.status === "ok") {
          setDaftarLoket(res.data ?? []);
        } else if (Array.isArray(res)) {
          setDaftarLoket(res);
        }
      })
      .catch(() => {});
  }, []);


  const { data, loading, loadingRefresh, error, setFilters, refresh, refreshPaksa } = useLaporan();

  // Bangun params filter aktif — dipakai untuk fetch, export, download
  const buildParams = useCallback(() => {
    const params = { mode: modePeriode };
    if (modePeriode === "harian") params.tanggal = tanggal;
    else if (modePeriode === "bulanan") params.bulan = bulan;
    else { params.tanggal_mulai = dari; params.tanggal_akhir = sampai; }
    if (loketId) params.loket_id = loketId;
    if (loketId && tellerId) params.teller_id = tellerId;
    return params;
  }, [modePeriode, tanggal, bulan, dari, sampai, loketId, tellerId]);

  useEffect(() => {
    setFilters({ ...buildParams(), page });
  }, [buildParams, page, setFilters]);

  useEffect(() => {
    setPage(1);
  }, [modePeriode, tanggal, bulan, dari, sampai, loketId]);

  // Shape data dari API
  const bentuk = data?.bentuk ?? null;
  const itemsBentukA = bentuk === "A" ? (data?.items ?? []) : [];
  const itemsBentukB = bentuk === "B" ? (data?.items ?? []) : [];
  const metaBentukB  = bentuk === "B" ? (data?.meta ?? {}) : {};
  const detailBentukC = bentuk === "C" ? data : null;
  const namaLoket = data?.nama_loket ?? null;
  const bridgeGagal = data?.bridge_gagal ?? false;
  const vendors = Array.isArray(data?.vendors)
    ? data.vendors
    : data?.vendors && typeof data.vendors === "object"
    ? Object.keys(data.vendors)
    : [];
  const totalRow = data?.total_row ?? null;
  const tellerTersedia = Array.isArray(data?.teller_tersedia) ? data.teller_tersedia : [];

  // Bentuk A ditampilkan utuh (semua loket sekaligus), tanpa paginasi: BE memang
  // tidak memaginasinya dan jumlah loket sedikit. Paginasi hanya untuk Bentuk B,
  // yang dipaginasi BE (8 hari aktif per halaman).

  // Navigasi ke detail setoran
  const handleNavigateDetail = (setoranId) => {
    navigate(`/setoran/${setoranId}`);
  };

  // Klik "Detail" di Bentuk A → pindah filter ke loket itu
  // Download per loket (Bentuk A)
  const handleDownloadLoket = async (idLoket, namaLoketRow) => {
    setLoadingDownload(idLoket);
    setNotif(null);
    try {
      const params = { ...buildParams(), loket_id: idLoket };
      const res = await downloadLaporanLoket(params);

      const disposition = res.headers["content-disposition"];
      const namaFile = disposition
        ? disposition.split("filename=")[1]?.replace(/"/g, "").trim()
        : buildNamaFileLaporan(namaLoketRow, modePeriode, { tanggal, bulan, dari, sampai });

      const url = window.URL.createObjectURL(new Blob([res.data]));
      const link = document.createElement("a");
      link.href = url;
      link.setAttribute("download", namaFile);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      const status = err?.response?.status;
      if (status === 403) setNotif({ tipe: "error", pesan: "Tidak memiliki akses untuk mengunduh laporan ini." });
      else if (status === 422) setNotif({ tipe: "peringatan", pesan: await pesanDariError(err, "Parameter laporan tidak valid.") });
      else if (status === 429) setNotif({ tipe: "peringatan", pesan: "Tunggu beberapa detik sebelum mengunduh lagi." });
      else setNotif({ tipe: "error", pesan: "Gagal mengunduh laporan. Coba lagi." });
    } finally {
      setLoadingDownload(null);
    }
  };

  // Export semua (ikut filter aktif)
  const handleExport = async () => {
    setLoadingExport(true);
    setNotif(null);
    try {
      const params = buildParams();
      const res = await exportLaporan(params);

      const disposition = res.headers["content-disposition"];
      const namaFile = disposition
        ? disposition.split("filename=")[1]?.replace(/"/g, "").trim()
        : `laporan-${tanggalLokal()}.xlsx`;

      const url = window.URL.createObjectURL(new Blob([res.data]));
      const link = document.createElement("a");
      link.href = url;
      link.setAttribute("download", namaFile);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      const status = err?.response?.status;
      if (status === 422) setNotif({ tipe: "peringatan", pesan: await pesanDariError(err, "Parameter laporan tidak valid.") });
      else if (status === 429) setNotif({ tipe: "peringatan", pesan: "Tunggu beberapa detik sebelum mengekspor lagi." });
      else setNotif({ tipe: "error", pesan: "Gagal mengekspor laporan. Coba lagi." });
    } finally {
      setLoadingExport(false);
    }
  };

  const labelPeriode =
    modePeriode === "harian" ? formatTanggalIndo(tanggal)
    : modePeriode === "bulanan" ? formatBulanIndo(bulan)
    : `${formatTanggalIndo(dari)} – ${formatTanggalIndo(sampai)}`;

  return (
    <>
      {/* ── Page Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div className="flex flex-col gap-0.5">
          <h1 className="font-bold text-[#191c20] text-3xl tracking-tight">Laporan</h1>
          <p className="text-[#424751] text-sm">Rekap pendapatan & setoran seluruh loket SOPP.</p>
        </div>
        <button
          onClick={refreshPaksa}
          disabled={loadingRefresh}
          className="self-start flex items-center gap-1.5 border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          {loadingRefresh ? <IconSpinner className="size-4" /> : <IconRefresh className="size-4" />}
          {loadingRefresh ? "Memuat ulang..." : "Refresh"}
        </button>
      </div>

      {/* ── Error banner ── */}
      {error && (
        <div className="bg-[#ffdad6] border border-[#ba1a1a]/40 rounded-lg px-5 py-4 flex items-center justify-between">
          <span className="text-[#93000a] text-sm">
            {pesanErrorLaporan(error)}
          </span>
          {/* 403/422 = penolakan dari BE (akses / filter tidak valid): mengulang
              request yang sama pasti menghasilkan penolakan yang sama. */}
          {![403, 422].includes(error?.response?.status) && (
            <button
              onClick={() => refresh()}
              className="border border-[#ba1a1a]/40 bg-white px-3 py-1.5 rounded text-xs font-medium text-[#93000a] hover:bg-[#ffdad6] transition-colors"
            >
              Coba lagi
            </button>
          )}
        </div>
      )}

      {/* ── Bridge gagal banner ── */}
      {bridgeGagal && (
        <div className="bg-[#fff8e1] border border-[#f0c040] rounded-lg px-4 py-3 text-sm text-[#92400e]">
          Sebagian data dari sistem SOPP lama gagal dimuat. Kolom yang terdampak ditampilkan sebagai "—".
          Coba klik Refresh untuk memuat ulang.
        </div>
      )}

      <BannerNotif notif={notif} onTutup={() => setNotif(null)} />

      {/* ── Filter Bar ── */}
      <div className="bg-white rounded-lg border border-[#d9d9d6] p-4 w-full">
        <div className="flex flex-wrap gap-3 items-end">
          {/* Mode Periode */}
          <div className="flex flex-col gap-1">
            <label className="text-[#424751] text-xs font-medium">Mode Periode</label>
            <div className="flex rounded overflow-hidden border border-[#d9d9d6]">
              {[
                { val: "harian", label: "Harian" },
                { val: "bulanan", label: "Bulanan" },
                { val: "rentang", label: "Rentang" },
              ].map((m) => (
                <button
                  key={m.val}
                  onClick={() => setModePeriode(m.val)}
                  className={`px-4 py-2 text-sm font-medium transition-colors ${
                    modePeriode === m.val ? "bg-[#710014] text-white" : "bg-white text-[#424751] hover:bg-[#f7f7f6]"
                  }`}
                >
                  {m.label}
                </button>
              ))}
            </div>
          </div>

          {modePeriode === "harian" && (
            <div className="flex flex-col gap-1">
              <label className="text-[#424751] text-xs font-medium">Tanggal</label>
              <input
                type="date"
                value={tanggal}
                max={today}
                onChange={(e) => handleTanggal(e.target.value)}
                className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
              />
            </div>
          )}

          {modePeriode === "bulanan" && (
            <div className="flex flex-col gap-1">
              <label className="text-[#424751] text-xs font-medium">Bulan</label>
              <input
                type="month"
                value={bulan}
                max={thisMonth}
                onChange={(e) => handleBulan(e.target.value)}
                className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
              />
            </div>
          )}

          {modePeriode === "rentang" && (
            <>
              <div className="flex flex-col gap-1">
                <label className="text-[#424751] text-xs font-medium">Dari Tanggal</label>
                <input
                  type="date"
                  value={dari}
                  max={sampai || today}
                  onChange={(e) => handleDari(e.target.value)}
                  className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
                />
              </div>
              <div className="flex flex-col gap-1">
                <label className="text-[#424751] text-xs font-medium">Sampai Tanggal</label>
                <input
                  type="date"
                  value={sampai}
                  min={dari || undefined}
                  max={today}
                  disabled={!dari}
                  title={!dari ? "Isi Dari Tanggal dulu" : undefined}
                  onChange={(e) => handleSampai(e.target.value)}
                  className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30 disabled:bg-[#f7f7f6] disabled:text-[#a0a0a0] disabled:cursor-not-allowed"
                />
              </div>
            </>
          )}

          {/* Filter Loket */}
          <div className="flex flex-col gap-1 min-w-[180px]">
            <label className="text-[#424751] text-xs font-medium">Loket</label>
            <select
              value={loketId ?? "semua"}
              onChange={(e) => {
                setLoketId(e.target.value === "semua" ? null : Number(e.target.value));
                setTellerId(""); // teller milik loket lama tidak berlaku di loket baru
              }}
              className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
            >
              <option value="semua">Semua Loket</option>
              {daftarLoket.map((l) => {
                const id = l.id ?? l.loket_id;
                const nama = l.nama ?? l.nama_loket;
                return (
                  <option key={id} value={id}>
                    {nama}
                  </option>
                );
              })}
            </select>
          </div>

          {loketId && (
            <button
              onClick={() => { setLoketId(null); setTellerId(""); }}
              className="self-end border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors"
            >
              ✕ Reset Loket
            </button>
          )}

          {loketId && (
            <div className="flex flex-col gap-1 min-w-[180px]">
              <label className="text-[#424751] text-xs font-medium">Teller</label>
              <select
                value={tellerId}
                onChange={(e) => { setTellerId(e.target.value); setPage(1); }}
                className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
              >
                <option value="">Semua Teller</option>
                {tellerTersedia.map((teller) => (
                  <option key={teller.teller_id} value={teller.teller_id}>{teller.nama}</option>
                ))}
              </select>
            </div>
          )}

          {/* Ekspor */}
          <button
            onClick={handleExport}
            disabled={loadingExport}
            className="self-end ml-auto flex items-center gap-1.5 border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] disabled:opacity-50 transition-colors"
          >
            <IconDownload className="size-4" />
            {loadingExport ? "Mengekspor..." : "Ekspor Laporan (Excel)"}
          </button>
        </div>
      </div>

      {/* ── Info bentuk aktif ── */}
      <BentukInfo
        bentuk={bentuk}
        loket={loketId}
        modePeriode={modePeriode}
        tanggal={tanggal}
        bulan={bulan}
        dari={dari}
        sampai={sampai}
        namaLoket={namaLoket}
      />

      {/* ── Tabel ── */}
      <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
        <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3 flex items-center justify-between">
          <span className="font-bold text-[#191c20] text-base">
            {bentuk === "C"
              ? `Rincian Setoran — ${namaLoket} · ${formatTanggalIndo(tanggal)}`
              : bentuk === "B"
              ? `Data per Hari — ${namaLoket}`
              : `Rekap per Loket · ${labelPeriode}`}
          </span>
          {!loading && bentuk === "B" && (
            <span className="text-[#727782] text-xs font-semibold tracking-[0.5px]">
              {metaBentukB.total_items ?? 0} hari
            </span>
          )}
        </div>

        {loading ? (
          <div className="flex items-center justify-center gap-2 py-20 text-[#727782] text-sm">
            <IconSpinner className="size-5" />
            <span>Memuat data...</span>
          </div>
        ) : (
          <>
            {bentuk === "C" && detailBentukC && (
              <TabelBentukC detail={detailBentukC} vendors={vendors} />
            )}

            {bentuk === "B" && (
              <>
                <TabelBentukB
                  rows={itemsBentukB}
                  allRows={itemsBentukB}
                  totalHari={metaBentukB.total_items}
                  vendors={vendors}
                  totalRow={totalRow}
                  namaLoket={namaLoket}
                  onDetailClick={handleNavigateDetail}
                />
                <PaginasiTabel
                  page={metaBentukB.current_page ?? 1}
                  totalPages={metaBentukB.total_pages ?? 1}
                  onChange={setPage}
                />
              </>
            )}

            {bentuk === "A" && (
              <TabelBentukA
                rows={itemsBentukA}
                allRows={itemsBentukA}
                vendors={vendors}
                totalRow={totalRow}
                onDetailClick={handleNavigateDetail}
                onDownload={handleDownloadLoket}
                loadingDownload={loadingDownload}
              />
            )}

            {!bentuk && !loading && (
              <div className="flex items-center justify-center py-16 text-[#9ca3af] text-sm">
                {modePeriode === "rentang" && (!dari || !sampai)
                  ? "Pilih tanggal Dari dan Sampai untuk menampilkan laporan."
                  : "Tidak ada data untuk filter ini."}
              </div>
            )}
          </>
        )}

        {!loading && bentuk === "A" && itemsBentukA.length > 0 && (
          <div className="border-t border-[#d9d9d6] bg-[rgba(242,243,250,0.5)] px-6 py-3">
            <span className="text-[#424751] text-xs font-semibold tracking-[0.6px]">
              {itemsBentukA.length} loket · {labelPeriode}
            </span>
          </div>
        )}
      </div>
    </>
  );
}