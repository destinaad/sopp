import { IconAlert, IconArrowRight } from "@/components/ui/Icon"; // import dua komponen ikon

/**
 * Banner kuning peringatan "belum input setoran hari ini".
 * Khusus konteks teller, hanya dipakai di pages/teller/Dashboard.jsx.
 * onInputClick dikirim dari page supaya navigasinya diatur di page, bukan di sini.
 */
export default function AlertBanner({ onInputClick }) {
  return (
    <div className="relative bg-[#faeeda] rounded-lg w-full">
      <div aria-hidden className="absolute border border-[#854f0b] border-solid inset-0 pointer-events-none rounded-lg" />
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-6">
        <div className="flex gap-4 items-center">
          <IconAlert />
          <div className="flex flex-col">
            <span className="font-bold text-[#854f0b] text-sm">Peringatan Input Harian</span>
            <span className="font-normal text-[#854f0b] text-sm">
              Anda belum menginput setoran untuk hari ini. Segera input sebelum pukul 17.00.
            </span>
          </div>
        </div>
        <button
          className="bg-[#710014] drop-shadow-sm flex gap-2 items-center px-6 py-2 rounded shrink-0 text-white text-sm font-medium hover:bg-[#5a000f] transition-colors"
          onClick={onInputClick}
        >
          <span>Input Sekarang</span>
          <IconArrowRight />
        </button>
      </div>
    </div>
  );
}
