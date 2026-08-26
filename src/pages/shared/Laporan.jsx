import { useState, useMemo, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import Badge from "@/components/ui/Badge";
import Modal from "@/components/ui/Modal";
import {
  IconDownload,
  IconEye,
  IconSpinner,
  IconCalendar,
  IconRefresh,
  IconChevronLeftOutline,
  IconChevronRightOutline,
} from "@/components/ui/Icon";
import { formatRupiah } from "@/utils/formatRupiah";
import { DAFTAR_LOKET } from "@/mocks/laporanMocks";
import useLaporan from "@/hooks/useLaporan";

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

const today = new Date().toISOString().slice(0, 10);
const thisMonth = new Date().toISOString().slice(0, 7);

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

function sumRows(rows, key) {
  return rows.reduce((acc, r) => acc + (r[key] ?? 0), 0);
}

// ─────────────────────────────────────────────────────────────
// StatusBadge lokal (lunas / belum-lunas)
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
// TH header style
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

// ─────────────────────────────────────────────────────────────
// Baris total (footer tabel)
// ─────────────────────────────────────────────────────────────
function TotalRow({ rows, showLoketCol = false }) {
  const cols = ["finnet", "puskud", "jne", "feeLain", "jumlahTotal", "transfer", "qris", "tunai", "kpay", "jumlahSetor", "selisih"];
  const totals = Object.fromEntries(cols.map((c) => [c, sumRows(rows, c)]));

  return (
    <tr className="bg-[#f0f0ee] font-semibold border-t-2 border-[#c2c6d2]">
      {showLoketCol && (
        <TD bold className="text-[#191c20]">TOTAL SEMUA</TD>
      )}
      {!showLoketCol && (
        <TD bold className="text-[#191c20]">TOTAL</TD>
      )}
      <TD right bold className="text-[#191c20]">{formatRupiah(totals.finnet)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(totals.puskud)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(totals.jne)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(totals.feeLain)}</TD>
      <TD right bold className="text-[#710014]">{formatRupiah(totals.jumlahTotal)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(totals.transfer)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(totals.qris)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(totals.tunai)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(totals.kpay)}</TD>
      <TD right bold className="text-[#191c20]">{formatRupiah(totals.jumlahSetor)}</TD>
      <TD right bold className={totals.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}>
        {formatRupiah(totals.selisih)}
      </TD>
      <td />
      <td />
    </tr>
  );
}

// ─────────────────────────────────────────────────────────────
// Kontrol paginasi (Sebelumnya / Selanjutnya) — dipakai di Tabel Bentuk A & B.
// Skenario C (1 loket + harian) tidak pernah butuh ini karena selalu 1 baris.
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
// TABEL BENTUK A  — Skenario 1, 3, 5: Semua Loket (1 baris/loket)
// rows yang diterima di sini SUDAH terpotong per halaman (lihat komponen Laporan()).
// allRows dipakai khusus untuk baris TOTAL, supaya total tetap akurat
// menjumlah SEMUA data, bukan cuma yang tampil di halaman itu.
// ─────────────────────────────────────────────────────────────
function TabelBentukA({ rows, allRows, modePeriode, onDetailClick }) {
  const navigate = useNavigate();

  const handleDetail = (row) => {
    if (modePeriode === "harian") {
      navigate(`/setoran/${encodeURIComponent(row.loket)}`);
    } else {
      onDetailClick(row.loket);
    }
  };

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[1100px]">
        <thead className="bg-[#f7f7f6]">
          <tr>
            <TH>Loket</TH>
            <TH colSpan={4} center>TRANSAKSI (Rp)</TH>
            <TH right>Jumlah Total</TH>
            <TH colSpan={4} center>DISETOR (Rp)</TH>
            <TH right>Jumlah Disetor</TH>
            <TH right>Selisih</TH>
            <TH center>Detail</TH>
            <TH center>Download</TH>
          </tr>
          <tr className="bg-[#fafafa] text-[11px]">
            <td />
            <TH>Finnet</TH>
            <TH>PUSKUD</TH>
            <TH>JNE</TH>
            <TH>Fee Lainnya</TH>
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
              key={row.loket}
              className={`border-b border-[#f0f0ee] ${i % 2 === 1 ? "bg-[#fafafa]" : "bg-white"} hover:bg-[#f7f3f3] transition-colors`}
            >
              <TD bold className="text-[#191c20]">{row.loket}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.finnet)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.puskud)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.jne)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.feeLain)}</TD>
              <TD right bold className="text-[#191c20]">{formatRupiah(row.jumlahTotal)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.transfer)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.qris)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.tunai)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.kpay)}</TD>
              <TD right bold className="text-[#191c20]">{formatRupiah(row.jumlahSetor)}</TD>
              <TD right bold className={row.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}>
                {formatRupiah(row.selisih)}
              </TD>
              <td className="px-3 py-3 text-center">
                <button
                  onClick={() => handleDetail(row)}
                  className="inline-flex items-center gap-1 text-[#710014] text-xs font-semibold hover:opacity-75 transition-opacity"
                >
                  <IconEye className="size-3.5" />
                  Detail
                </button>
              </td>
              <td className="px-3 py-3 text-center">
                <button
                  title={`Unduh Excel — ${row.loket}`}
                  className="inline-flex items-center gap-1 text-[#424751] text-xs font-medium hover:text-[#191c20] transition-colors"
                >
                  <IconDownload className="size-3.5" />
                  Excel
                </button>
              </td>
            </tr>
          ))}
          <TotalRow rows={allRows} showLoketCol />
        </tbody>
      </table>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// TABEL BENTUK B  — Skenario 2, 6: 1 Loket, per Tanggal
// rows = potongan halaman aktif. allRows = seluruh data (dipakai untuk
// teks ringkasan "Menampilkan X hari aktif" & baris TOTAL, supaya tetap
// menyebut total keseluruhan, bukan cuma yang sedang tampil).
// ─────────────────────────────────────────────────────────────
function TabelBentukB({ rows, allRows, loketTerpilih }) {
  const navigate = useNavigate();

  return (
    <div className="overflow-x-auto">
      {/* Info ringkasan hari aktif */}
      <div className="px-4 py-3 border-b border-[#d9d9d6] bg-[#f7f7f6]">
        <span className="text-sm text-[#424751]">
          Menampilkan <strong>{allRows.length}</strong> hari aktif untuk{" "}
          <strong className="text-[#191c20]">{loketTerpilih}</strong>
        </span>
      </div>

      <table className="w-full min-w-[1100px]">
        <thead className="bg-[#f7f7f6]">
          <tr>
            <TH>Tanggal</TH>
            <TH colSpan={4} center>TRANSAKSI (Rp)</TH>
            <TH right>Jumlah Total</TH>
            <TH colSpan={4} center>DISETOR (Rp)</TH>
            <TH right>Jumlah Disetor</TH>
            <TH right>Selisih</TH>
            <TH center>Detail</TH>
            <TH center>Download</TH>
          </tr>
          <tr className="bg-[#fafafa] text-[11px]">
            <td />
            <TH>Finnet</TH>
            <TH>PUSKUD</TH>
            <TH>JNE</TH>
            <TH>Fee Lainnya</TH>
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
              key={row.tanggal}
              className={`border-b border-[#f0f0ee] ${i % 2 === 1 ? "bg-[#fafafa]" : "bg-white"} hover:bg-[#f7f3f3] transition-colors`}
            >
              <TD bold className="text-[#191c20]">{formatTanggalIndo(row.tanggal)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.finnet)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.puskud)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.jne)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.feeLain)}</TD>
              <TD right bold className="text-[#191c20]">{formatRupiah(row.jumlahTotal)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.transfer)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.qris)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.tunai)}</TD>
              <TD right className="text-[#424751]">{formatRupiah(row.kpay)}</TD>
              <TD right bold className="text-[#191c20]">{formatRupiah(row.jumlahSetor)}</TD>
              <TD right bold className={row.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}>
                {formatRupiah(row.selisih)}
              </TD>
              <td className="px-3 py-3 text-center">
                <button
                  onClick={() =>
                    navigate(`/setoran/${encodeURIComponent(loketTerpilih)}_${row.tanggal}`)
                  }
                  className="inline-flex items-center gap-1 text-[#710014] text-xs font-semibold hover:opacity-75 transition-opacity"
                >
                  <IconEye className="size-3.5" />
                  Detail
                </button>
              </td>
              <td className="px-3 py-3 text-center">
                <button
                  title={`Unduh Excel — ${formatTanggalIndo(row.tanggal)}`}
                  className="inline-flex items-center gap-1 text-[#424751] text-xs font-medium hover:text-[#191c20] transition-colors"
                >
                  <IconDownload className="size-3.5" />
                  Excel
                </button>
              </td>
            </tr>
          ))}
          <TotalRow rows={allRows} />
        </tbody>
      </table>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// Sub-header grup kolom (baris pertama thead) — warna netral, konsisten dgn tabel lain
// ─────────────────────────────────────────────────────────────
const THGroup = ({ children, colSpan }) => (
  <th
    colSpan={colSpan}
    className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-3 py-3 text-center border-b border-r border-[#d9d9d6] last:border-r-0 bg-[#f0f0ee]"
  >
    {children}
  </th>
);

// Sel Bukti berupa tombol ikon mata — klik untuk membuka modal preview bukti.
// Dipakai untuk Transfer, QRIS, dan Setoran Tunai (KPay tidak punya bukti per-transaksi).
const BuktiCell = ({ ada, onLihat }) => (
  <td className="px-3 py-3 text-center border-r-2 border-[#d9d9d6]">
    {ada ? (
      <button
        onClick={onLihat}
        className="inline-flex items-center gap-1 text-[#710014] text-xs font-semibold hover:opacity-75 transition-opacity"
      >
        <IconEye className="size-3.5" />
        Lihat
      </button>
    ) : (
      <span className="text-[#93000a] text-xs font-medium">Tidak ada</span>
    )}
  </td>
);

// ─────────────────────────────────────────────────────────────
// TABEL BENTUK C  — Skenario 4: Harian + 1 Loket (expand penuh)
// Selalu 1 baris (1 loket, 1 hari) — TIDAK PERNAH butuh paginasi.
// ─────────────────────────────────────────────────────────────
function TabelBentukC({ detail }) {
  const { transfer, qris, tunai, kpay } = detail.metodeSetoran;
  const jumlahDisetor = transfer.nominal + qris.nominal + tunai.nominal + kpay.nominal;

  // State modal preview bukti — menyimpan label metode yang sedang dilihat (null = modal tertutup)
  const [modalBukti, setModalBukti] = useState(null);

  return (
    <>
    <div className="overflow-x-auto">
      <table className="w-full min-w-[1200px] border-collapse">
        <thead>
          {/* Baris grup kolom */}
          <tr>
            <th
              rowSpan={2}
              className="font-semibold text-[#3d3d3a] text-xs tracking-[0.6px] uppercase px-3 py-3 text-left align-middle border-b border-r border-[#d9d9d6] bg-[#f7f7f6]"
            >
              Loket
            </th>
            <THGroup colSpan={4}>Transaksi (Rp)</THGroup>
            <THGroup colSpan={2}>Transfer</THGroup>
            <THGroup colSpan={2}>QRIS</THGroup>
            <THGroup colSpan={2}>Setoran Tunai</THGroup>
            <THGroup colSpan={1}>KPay</THGroup>
            <THGroup colSpan={2}>Ringkasan</THGroup>
          </tr>
          {/* Baris sub-kolom */}
          <tr className="bg-[#f7f7f6] text-[11px]">
            <TH>Finnet</TH>
            <TH>PUSKUD</TH>
            <TH>JNE</TH>
            <TH right>Jml Total</TH>

            <TH right>Nominal (Rp)</TH>
            <TH center>Bukti</TH>

            <TH right>Nominal (Rp)</TH>
            <TH center>Bukti</TH>

            <TH right>Nominal (Rp)</TH>
            <TH center>Bukti</TH>

            <TH right>Nominal (Rp)</TH>

            <TH right>Selisih (Rp)</TH>
          </tr>
        </thead>
        <tbody>
          <tr className="bg-white hover:bg-[#f7f3f3] transition-colors">
            {/* Loket */}
            <TD bold className="text-[#191c20] border-r border-[#f0f0ee]">{detail.loket}</TD>

            {/* Transaksi */}
            <TD right className="text-[#424751] border-r border-[#f0f0ee]">{formatRupiah(detail.finnet)}</TD>
            <TD right className="text-[#424751] border-r border-[#f0f0ee]">{formatRupiah(detail.puskud)}</TD>
            <TD right className="text-[#424751] border-r border-[#f0f0ee]">{formatRupiah(detail.jne)}</TD>
            <TD right bold className="text-[#191c20] border-r-2 border-[#d9d9d6]">{formatRupiah(detail.jumlahTotal)}</TD>

            {/* Transfer */}
            <TD right bold className="text-[#191c20]">{formatRupiah(transfer.nominal)}</TD>
            <BuktiCell ada={transfer.bukti} onLihat={() => setModalBukti("Transfer")} />

            {/* QRIS */}
            <TD right bold className="text-[#191c20]">{formatRupiah(qris.nominal)}</TD>
            <BuktiCell ada={qris.bukti} onLihat={() => setModalBukti("QRIS")} />

            {/* Setoran Tunai */}
            <TD right bold className="text-[#191c20]">{formatRupiah(tunai.nominal)}</TD>
            <BuktiCell ada={tunai.bukti} onLihat={() => setModalBukti("Setoran Tunai")} />

            {/* KPay — TIDAK PERNAH punya bukti foto (sesuai kontrak API), makanya
                tidak ada kolom Bukti sama sekali di grup ini, beda dari 3 metode lain */}
            <TD right bold className="text-[#191c20] border-r-2 border-[#d9d9d6]">{formatRupiah(kpay.nominal)}</TD>

            {/* Ringkasan */}
            <TD right bold className={detail.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}>
              {formatRupiah(detail.selisih)}
            </TD>
          </tr>
        </tbody>
        <tfoot>
          <tr className="bg-[#f0f0ee] font-semibold border-t-2 border-[#c2c6d2]">
            <td className="px-3 py-3 text-sm border-r border-[#d9d9d6]" />
            <td colSpan={3} className="px-3 py-3 text-sm text-right text-[#191c20] border-r border-[#d9d9d6]">
              Total Transaksi
            </td>
            <td className="px-3 py-3 text-sm text-right font-bold text-[#191c20] border-r-2 border-[#d9d9d6]">
              {formatRupiah(detail.jumlahTotal)}
            </td>
            <td colSpan={7} className="px-3 py-3 text-sm text-right text-[#191c20] border-r-2 border-[#d9d9d6]">
              Jumlah Disetor (Transfer + QRIS + Tunai + KPay):{" "}
              <span className="font-bold">{formatRupiah(jumlahDisetor)}</span>
            </td>
            <td colSpan={2} className="px-3 py-3 text-sm text-right text-[#191c20]">
              Selisih:{" "}
              <span className={`font-bold ${detail.selisih === 0 ? "text-[#006c49]" : "text-[#93000a]"}`}>
                {formatRupiah(detail.selisih)}
              </span>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>

    {/* Modal preview bukti — placeholder karena upload file sungguhan belum ada */}
    {modalBukti && (
      <Modal title={`Bukti — ${modalBukti}`} onClose={() => setModalBukti(null)}>
        <div className="bg-[#f3f4f6] rounded-lg h-52 flex items-center justify-center text-[#9ca3af] text-sm">
          Preview bukti tidak tersedia
        </div>
      </Modal>
    )}
    </>
  );
}

// ─────────────────────────────────────────────────────────────
// Chip label skenario (info bar)
// ─────────────────────────────────────────────────────────────
function SkenarioInfo({ skenario, loket, modePeriode, tanggal, bulan, dari, sampai }) {
  const labels = {
    1: `Bulanan · ${formatBulanIndo(bulan)} · Semua Loket`,
    2: `Bulanan · ${formatBulanIndo(bulan)} · ${loket}`,
    3: `Harian · ${formatTanggalIndo(tanggal)} · Semua Loket (Default)`,
    4: `Harian · ${formatTanggalIndo(tanggal)} · ${loket} — Rincian Penuh`,
    5: `Rentang · ${formatTanggalIndo(dari)} – ${formatTanggalIndo(sampai)} · Semua Loket`,
    6: `Rentang · ${formatTanggalIndo(dari)} – ${formatTanggalIndo(sampai)} · ${loket}`,
  };

  const colorMap = {
    1: { bg: "#e6f1fb", text: "#185fa5", border: "#185fa5" },
    2: { bg: "#e6f1fb", text: "#185fa5", border: "#185fa5" },
    3: { bg: "#e1f5ee", text: "#006c49", border: "#006c49" },
    4: { bg: "#fef3c7", text: "#92400e", border: "#f59e0b" },
    5: { bg: "#ededf4", text: "#424751", border: "#c2c6d2" },
    6: { bg: "#ededf4", text: "#424751", border: "#c2c6d2" },
  };

  const c = colorMap[skenario];

  return (
    <div
      className="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium border"
      style={{ backgroundColor: c.bg, color: c.text, borderColor: c.border }}
    >
      <span className="font-bold">Skenario {skenario}</span>
      <span className="opacity-60">·</span>
      <span>{labels[skenario]}</span>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// KOMPONEN UTAMA: Laporan
// ─────────────────────────────────────────────────────────────
export default function Laporan() {
  // ── state filter ──
  const [modePeriode, setModePeriode] = useState("harian"); // "harian" | "bulanan" | "rentang"
  const [tanggal, setTanggal] = useState(today);
  const [bulan, setBulan] = useState(thisMonth);
  const [dari, setDari] = useState("2026-07-01");
  const [sampai, setSampai] = useState("2026-07-15");
  const [loket, setLoket] = useState("semua");

  // ── state paginasi tabel (khusus Bentuk A & B; Bentuk C selalu 1 baris) ──
  const [page, setPage] = useState(1);
  const PAGE_SIZE = 8;

  // ── resolve data ──
  // useLaporan defaultnya {items: [], meta: null} (bentuk generik hook),
  // TAPI getLaporan() di api/admin.js sebenarnya mengembalikan bentuk
  // {skenario, rows, detailExpand} (hasil getMockLaporanData() apa adanya)
  // — bukan {items, meta}. Jadi "data.items" dari hook TIDAK DIPAKAI di
  // sini; kita ambil field yang sesuai bentuk laporan yang sebenarnya,
  // dengan nilai default supaya aman kalau data belum sempat datang.
  const { data, loading, error, setFilters, refresh } = useLaporan();

  useEffect(() => {
    setFilters({ modePeriode, tanggal, bulan, dari, sampai, loket });
  }, [modePeriode, tanggal, bulan, dari, sampai, loket, setFilters]);

  const skenario = data?.skenario ?? 3;
  const rows = data?.rows ?? [];
  const detailExpand = data?.detailExpand ?? null;

  // Reset ke halaman 1 setiap kali filter berubah — supaya tidak "nyangkut"
  // di halaman yang sudah tidak ada lagi setelah data baru datang.
  useEffect(() => {
    setPage(1);
  }, [modePeriode, tanggal, bulan, dari, sampai, loket]);

  // Potong rows jadi 8 baris per halaman. rows tetap dipakai utuh (allRows)
  // untuk teks ringkasan & baris TOTAL, supaya nilainya tetap akurat
  // menjumlah SEMUA data, bukan cuma yang sedang tampil di halaman itu.
  const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  const rowsHalaman = useMemo(
    () => rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE),
    [rows, page]
  );

  // Saat klik "Detail →" dari Bentuk A (skenario 1 & 5) → ganti filter loket + tetap di halaman
  const handleDetailLoket = (namaLoket) => {
    setLoket(namaLoket);
  };

  const simulasiRefresh = () => {
    refresh();
  };

  // Label periode untuk header
  const labelPeriode =
    modePeriode === "harian"
      ? formatTanggalIndo(tanggal)
      : modePeriode === "bulanan"
      ? formatBulanIndo(bulan)
      : `${formatTanggalIndo(dari)} – ${formatTanggalIndo(sampai)}`;

  return (
    <>
      {/* ── Page Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div className="flex flex-col gap-0.5">
          <h1 className="font-bold text-[#191c20] text-3xl tracking-tight">Laporan</h1>
          <p className="text-[#424751] text-sm">
            Rekap pendapatan & setoran seluruh loket SOPP.
          </p>
        </div>
        <button
          onClick={simulasiRefresh}
          className="self-start flex items-center gap-1.5 border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors"
        >
          <IconRefresh className="size-4" />
          Refresh
        </button>
      </div>

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
                    modePeriode === m.val
                      ? "bg-[#710014] text-white"
                      : "bg-white text-[#424751] hover:bg-[#f7f7f6]"
                  }`}
                >
                  {m.label}
                </button>
              ))}
            </div>
          </div>

          {/* Input periode sesuai mode */}
          {modePeriode === "harian" && (
            <div className="flex flex-col gap-1">
              <label className="text-[#424751] text-xs font-medium">Tanggal</label>
              <input
                type="date"
                value={tanggal}
                onChange={(e) => setTanggal(e.target.value)}
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
                onChange={(e) => setBulan(e.target.value)}
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
                  onChange={(e) => setDari(e.target.value)}
                  className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
                />
              </div>
              <div className="flex flex-col gap-1">
                <label className="text-[#424751] text-xs font-medium">Sampai Tanggal</label>
                <input
                  type="date"
                  value={sampai}
                  onChange={(e) => setSampai(e.target.value)}
                  className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
                />
              </div>
            </>
          )}

          {/* Filter Loket */}
          <div className="flex flex-col gap-1 min-w-[180px]">
            <label className="text-[#424751] text-xs font-medium">Loket</label>
            <select
              value={loket}
              onChange={(e) => setLoket(e.target.value)}
              className="border border-[#d9d9d6] rounded px-3 py-2 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
            >
              <option value="semua">Semua Loket</option>
              {DAFTAR_LOKET.map((l) => (
                <option key={l} value={l}>{l}</option>
              ))}
            </select>
          </div>

          {/* Reset loket (tampil jika loket tertentu dipilih) */}
          {loket !== "semua" && (
            <button
              onClick={() => setLoket("semua")}
              className="self-end border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors"
            >
              ✕ Reset Loket
            </button>
          )}

          {/* Ekspor — selalu tampil di semua skenario, fungsinya mengikuti filter aktif:
               - semua loket  → download ringkasan per loket (Bentuk A)
               - loket tertentu → download detail per hari untuk loket itu (Bentuk B/C) */}
          <button className="self-end ml-auto flex items-center gap-1.5 border border-[#d9d9d6] bg-white px-4 py-2 rounded text-sm text-[#424751] hover:bg-[#f7f7f6] transition-colors">
            <IconDownload className="size-4" />
            Ekspor Laporan (Excel)
          </button>
        </div>
      </div>

      {/* ── Skenario Info Chip ── */}
      <SkenarioInfo
        skenario={skenario}
        loket={loket}
        modePeriode={modePeriode}
        tanggal={tanggal}
        bulan={bulan}
        dari={dari}
        sampai={sampai}
      />

      {/* ── Tabel ── */}
      <div className="bg-white rounded-lg border border-[#d9d9d6] w-full overflow-hidden">
        {/* Header tabel */}
        <div className="bg-[#efefed] border-b border-[#d9d9d6] px-6 py-3 flex items-center justify-between">
          <span className="font-bold text-[#191c20] text-base">
            {skenario === 4
              ? `Rincian Setoran — ${detailExpand?.loket} · ${formatTanggalIndo(detailExpand?.tanggal)}`
              : skenario === 2 || skenario === 6
              ? `Data per Hari — ${loket}`
              : `Rekap per Loket · ${labelPeriode}`}
          </span>
          {!loading && rows.length > 0 && (
            <span className="text-[#727782] text-xs font-semibold tracking-[0.5px]">
              {rowsHalaman.length} dari {rows.length} baris
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
            {/* Bentuk C: Harian + 1 Loket — selalu 1 baris, tidak perlu paginasi */}
            {skenario === 4 && detailExpand && (
              <TabelBentukC detail={detailExpand} />
            )}

            {/* Bentuk B: Bulanan/Rentang + 1 Loket */}
            {(skenario === 2 || skenario === 6) && (
              <>
                <TabelBentukB rows={rowsHalaman} allRows={rows} loketTerpilih={loket} />
                <PaginasiTabel page={page} totalPages={totalPages} onChange={setPage} />
              </>
            )}

            {/* Bentuk A: Semua Loket */}
            {(skenario === 1 || skenario === 3 || skenario === 5) && (
              <>
                <TabelBentukA
                  rows={rowsHalaman}
                  allRows={rows}
                  modePeriode={modePeriode}
                  onDetailClick={handleDetailLoket}
                />
                <PaginasiTabel page={page} totalPages={totalPages} onChange={setPage} />
              </>
            )}
          </>
        )}

        {/* Footer */}
        {!loading && skenario !== 4 && rows.length > 0 && (
          <div className="border-t border-[#d9d9d6] bg-[rgba(242,243,250,0.5)] px-6 py-3">
            <span className="text-[#424751] text-xs font-semibold tracking-[0.6px]">
              Menampilkan {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, rows.length)} dari {rows.length} baris data
              {loket !== "semua" ? ` · ${loket}` : " · Semua Loket"}
              {" · "}
              {modePeriode === "harian" && formatTanggalIndo(tanggal)}
              {modePeriode === "bulanan" && formatBulanIndo(bulan)}
              {modePeriode === "rentang" && `${formatTanggalIndo(dari)} – ${formatTanggalIndo(sampai)}`}
            </span>
          </div>
        )}
      </div>
    </>
  );
}