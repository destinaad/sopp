/**
 * Bar kecil sticky di bagian bawah halaman form, isinya status sistem
 * (bukan konten SOPP-spesifik), makanya ditaruh di layout/ bukan shared/.
 * Dipakai di InputSetoran.jsx, dan berpotensi dipakai lagi di PelunasanSelisih.jsx.
 *
 * CATATAN PENTING soal posisi "sticky":
 * PageLayout.jsx membungkus semua children di dalam <main class="overflow-y-auto">
 * dengan wrapper <div class="max-w-5xl mx-auto p-6">. Supaya StatusBar ini
 * menempel di BAWAH area konten (bukan cuma ikut lewat pas discroll), dia dibuat
 * "sticky bottom-0" DAN pakai margin negatif (-mx-6) untuk "membatalkan" padding
 * kiri-kanan dari wrapper tadi, supaya lebar bar-nya pas selebar area konten.
 * Ini beda sedikit dari desain Figma aslinya (yang full-width layar), tapi secara
 * visual hasilnya tetap terlihat seperti status bar yang nempel di bawah.
 */
export default function StatusBar() {
  return (
    <div className="sticky bottom-0 -mx-6 px-6 bg-white border-t border-[#c2c6d2] flex items-center justify-between py-2 text-xs font-semibold text-[#424751] tracking-[0.6px] z-10">
      <div className="flex gap-4 items-center">
        <div className="flex gap-1.5 items-center">
          <div className="bg-[#22c55e] rounded-xl size-2" />
          <span className="uppercase">System Synchronized</span>
        </div>
        <div className="w-px h-4 bg-[#c2c6d2]" />
        <span>Last auto-save: 2 mins ago</span>
      </div>
      <span>Version 2.4.1</span>
    </div>
  );
}
