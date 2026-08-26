import { useState } from "react";
import OptionCards from "@/components/shared/OptionCards";
import InfoTransaksi from "@/components/shared/InfoTransaksi";
import VendorTable from "@/components/shared/VendorTable";
import RincianSetoran from "@/components/shared/RincianSetoran";
import RingkasanOtomatis from "@/components/shared/RingkasanOtomatis";
import PelunasanContent from "@/components/shared/PelunasanContent";
import StatusBar from "@/components/layout/StatusBar";
import { IconSave, IconSend } from "@/components/ui/Icon";
import { getTotalPendapatanSOPP } from "@/mocks/setoran";
import { DAFTAR_LOKET } from "@/mocks/laporanMocks";

/**
 * Halaman Input Setoran khusus Bu Wiwik (role "keuangan"). Route (di App.jsx):
 *   <Route path="/keuangan/input-setoran" element={<InputSetoranKeuangan />} />
 *
 * SAMA PERSIS dengan pages/teller/InputSetoran.jsx (reuse semua komponen
 * shared yang sama: OptionCards, VendorTable, RincianSetoran,
 * RingkasanOtomatis, PelunasanContent) — satu-satunya tambahan adalah
 * dropdown "Pilih Cabang" di paling atas, karena Bu Wiwik TIDAK terikat ke
 * 1 loket seperti teller biasa (yang otomatis kebaca dari sesi login).
 *
 * Sebelum cabang dipilih, sisa form disembunyikan — tidak masuk akal
 * menampilkan data SOPP/rincian setoran sebelum tahu ini punya loket mana.
 *
 * Hak "edit setoran teller manapun, kapan saja" TIDAK ada di halaman ini —
 * itu tetap lewat halaman Detail Setoran yang sudah ada tombol Edit-nya
 * (sesuai keputusan: satu tempat buat satu jenis aksi, tidak duplikat).
 */
export default function InputSetoranKeuangan() {
  const [cabang, setCabang] = useState("");
  const [mode, setMode] = useState("hari-ini");
  const [totalSetor, setTotalSetor] = useState(0);

  const totalPendapatanSOPP = getTotalPendapatanSOPP();

  return (
    <>
      <div className="flex flex-col gap-0.5">
        <h1 className="font-semibold text-[#191c20] text-2xl tracking-tight">Input Setoran</h1>
        <p className="text-[#424751] text-sm">Pilih cabang, lalu jenis transaksi yang akan dicatat</p>
      </div>

      <div className="bg-white rounded border border-[#d9d9d6] w-full p-4">
        <label className="font-semibold text-[#424751] text-xs tracking-[0.6px] uppercase block mb-1.5">
          Pilih Cabang
        </label>
        <select
          value={cabang}
          onChange={(e) => setCabang(e.target.value)}
          className="w-full sm:w-80 border border-[#d9d9d6] rounded px-3 py-2.5 text-sm text-[#191c20] bg-white focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
        >
          <option value="">— Pilih SOPP Cabang —</option>
          {DAFTAR_LOKET.map((loket) => (
            <option key={loket} value={loket}>{loket}</option>
          ))}
        </select>
      </div>

      {!cabang ? (
        <div className="bg-[#f7f7f6] border border-dashed border-[#d9d9d6] rounded-lg py-12 text-center">
          <p className="text-[#727782] text-sm">Pilih cabang terlebih dahulu untuk mulai input setoran.</p>
        </div>
      ) : (
        <>
          <OptionCards mode={mode} onModeChange={setMode} />

          {mode === "hari-ini" ? (
            <>
              <InfoTransaksi loket={cabang} />
              <VendorTable />
              <RincianSetoran onTotalChange={setTotalSetor} />
              <RingkasanOtomatis totalSetor={totalSetor} totalPendapatanSOPP={totalPendapatanSOPP} />

              <div className="flex gap-4 items-center justify-end pt-2 pb-4">
                <button className="relative bg-[#efefed] border border-[#d9d9d6] flex gap-2 items-center px-8 py-4 rounded hover:bg-[#e5e5e3] transition-colors text-[#424751] text-base">
                  <IconSave />
                  <span>Simpan Draft</span>
                </button>
                <button className="bg-[#710014] flex gap-2 items-center px-8 py-4 rounded shadow-lg hover:bg-[#5a000f] transition-colors text-white text-base">
                  <IconSend />
                  <span>Submit</span>
                </button>
              </div>
            </>
          ) : (
            <PelunasanContent onBatal={() => setMode("hari-ini")} />
          )}
        </>
      )}

      <StatusBar />
    </>
  );
}