import { useState, useRef, useEffect } from "react";
import { IconCalendar, IconChevronLeft, IconChevronRight, IconLock } from "@/components/ui/Icon";
import { formatDateWithDay } from "@/utils/formatDate";

const NAMA_BULAN = [
  "Januari", "Februari", "Maret", "April", "Mei", "Juni",
  "Juli", "Agustus", "September", "Oktober", "November", "Desember",
];
const NAMA_HARI_PENDEK = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];

/** "2026-07-24" (local, bukan UTC — supaya tidak geser 1 hari di WIB) */
function toISODate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function isSameDay(a, b) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

/** Grid 6x7 (42 sel) mengikuti kalender bulan berjalan, termasuk padding
 * tanggal bulan sebelum/sesudahnya supaya barisnya rapi. */
function buildCalendarGrid(year, month) {
  const firstOfMonth = new Date(year, month, 1);
  const startOffset = firstOfMonth.getDay(); // 0=Minggu
  const gridStart = new Date(year, month, 1 - startOffset);

  return Array.from({ length: 42 }, (_, i) => {
    const d = new Date(gridStart);
    d.setDate(gridStart.getDate() + i);
    return d;
  });
}

export default function TanggalTransaksiPicker({
  value,
  onChange,
  statusTanggal = null,
  kalenderMap = {},      // { "2026-08-15": { status, bisa_diinput }, ... }
  onViewChange,          // (bulanStr: "2026-08") => void, dipanggil saat bulan ditampilkan berubah
}) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef(null);

  const selectedDate = value ? new Date(`${value}T00:00:00`) : new Date();
  const [viewYear, setViewYear] = useState(selectedDate.getFullYear());
  const [viewMonth, setViewMonth] = useState(selectedDate.getMonth());

  // Beri tahu parent setiap kali bulan yang ditampilkan berganti, supaya
  // parent bisa fetch data kalender bulan itu (Card A §4: dipanggil per
  // bulan, bukan per-tanggal).
  useEffect(() => {
    if (!onViewChange) return;
    const bulanStr = `${viewYear}-${String(viewMonth + 1).padStart(2, "0")}`;
    onViewChange(bulanStr);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewYear, viewMonth]);
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  // Klik di luar popup -> tutup
  useEffect(() => {
    if (!open) return;
    function handleClickOutside(e) {
      if (containerRef.current && !containerRef.current.contains(e.target)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, [open]);

  // Buka popup sekaligus reset grid ke bulan dari tanggal yang lagi aktif.
  // Dilakukan langsung di event handler (bukan useEffect) supaya tidak ada
  // setState di dalam body efek.
  const handleToggleOpen = () => {
    setOpen((prevOpen) => {
      const willOpen = !prevOpen;
      if (willOpen) {
        setViewYear(selectedDate.getFullYear());
        setViewMonth(selectedDate.getMonth());
      }
      return willOpen;
    });
  };

  const grid = buildCalendarGrid(viewYear, viewMonth);

  const gotoPrevMonth = () => {
    const d = new Date(viewYear, viewMonth - 1, 1);
    setViewYear(d.getFullYear());
    setViewMonth(d.getMonth());
  };
  const gotoNextMonth = () => {
    const d = new Date(viewYear, viewMonth + 1, 1);
    setViewYear(d.getFullYear());
    setViewMonth(d.getMonth());
  };

  const handlePilihTanggal = (d) => {
    if (d > today) return; // jaga-jaga, walau tombolnya sudah disabled
    onChange(toISODate(d));
    setOpen(false);
  };

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        onClick={handleToggleOpen}
        className="flex items-center gap-2 border border-[#d9d9d6] rounded px-3 py-2 bg-white hover:bg-[#f7f7f6] transition-colors text-left"
      >
        <IconCalendar className="shrink-0 size-4" />
        <span className="font-semibold text-black text-sm">{formatDateWithDay(selectedDate)}</span>
        {statusTanggal === "terkunci" && (
          <IconLock className="shrink-0 size-3.5 text-[#854F0B]" />
        )}
      </button>

      {open && (
        <div className="absolute z-40 top-full left-0 mt-2 bg-white border border-[#d9d9d6] rounded-lg shadow-lg p-4 w-[300px]">
          {/* Header navigasi bulan */}
          <div className="flex items-center justify-between mb-3">
            <button
              type="button"
              onClick={gotoPrevMonth}
              aria-label="Bulan sebelumnya"
              className="p-1.5 rounded hover:bg-[#f7f7f6] text-[#424751] transition-colors"
            >
              <IconChevronLeft />
            </button>
            <span className="font-semibold text-sm text-[#191c20]">
              {NAMA_BULAN[viewMonth]} {viewYear}
            </span>
            <button
              type="button"
              onClick={gotoNextMonth}
              aria-label="Bulan berikutnya"
              className="p-1.5 rounded hover:bg-[#f7f7f6] text-[#424751] transition-colors"
            >
              <IconChevronRight />
            </button>
          </div>

          {/* Nama hari */}
          <div className="grid grid-cols-7 mb-1">
            {NAMA_HARI_PENDEK.map((h) => (
              <span key={h} className="text-center text-[10px] font-semibold text-[#9C9A92] tracking-[0.4px] py-1">
                {h}
              </span>
            ))}
          </div>

          {/* Grid tanggal */}
          <div className="grid grid-cols-7 gap-y-1">
                        {grid.map((d) => {
              const inCurrentMonth = d.getMonth() === viewMonth;
              const isFuture = d > today;
              const isSelected = isSameDay(d, selectedDate);
              const isToday = isSameDay(d, today);
              const iso = toISODate(d);
              const infoTanggal = kalenderMap[iso];
              // bisa_diinput dari server jadi sumber kebenaran tambahan —
              // kalau server bilang false (mis. sudah submitted), tetap
              // disable walau bukan tanggal masa depan (Card A §4).
              const isDisabled = isFuture || infoTanggal?.bisa_diinput === false;

              return (
                <button
                  type="button"
                  key={d.toISOString()}
                  disabled={isDisabled}
                  onClick={() => handlePilihTanggal(d)}
                  className={`
                    relative mx-auto size-8 rounded-full text-xs font-medium transition-colors
                    ${!inCurrentMonth ? "text-[#c2c6d2]" : ""}
                    ${isDisabled ? "text-[#d9d9d6] cursor-not-allowed" : "cursor-pointer"}
                    ${isSelected ? "bg-[#710014] text-white font-semibold" : ""}
                    ${!isSelected && !isDisabled && inCurrentMonth ? "text-[#191c20] hover:bg-[#f7f7f6]" : ""}
                    ${!isSelected && isToday ? "ring-1 ring-[#710014]" : ""}
                  `}
                >
                  {d.getDate()}
                  {infoTanggal?.status === "draft" && (
                    <span className="absolute bottom-0.5 left-1/2 -translate-x-1/2 size-1 rounded-full bg-[#f0c040]" />
                  )}
                  {infoTanggal?.status === "submitted" && (
                    <span className="absolute bottom-0.5 left-1/2 -translate-x-1/2 size-1 rounded-full bg-[#4CAF50]" />
                  )}
                </button>
              );
            })}
          </div>

          <p className="text-[10px] text-[#9C9A92] mt-3 leading-relaxed">
            Tanggal setelah hari ini tidak dapat dipilih.
          </p>
        </div>
      )}
    </div>
  );
}