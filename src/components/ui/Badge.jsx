/**
 * Badge generik berbentuk pill. Ini komponen "polos" di level ui/ —
 * tidak tahu soal "Sudah Submit" / "Selisih" dsb, hanya terima warna & style.
 * Logika "status apa -> warna apa" ada di components/shared/StatusBadge.jsx
 *
 * variant:
 *  - "dot"      -> badge dengan titik bulat di kiri teks (contoh: Belum Disubmit)
 *  - "outline"  -> badge dengan border + background solid tipis (contoh: Sudah Submit, Selisih, Draft)
 */
export default function Badge({ children, variant = "outline", dotColor, bgColor, textColor, borderColor }) {
  if (variant === "dot") {
    return (
      <div
        className="flex gap-2 items-center px-4 py-1 rounded-xl shrink-0"
        style={{ backgroundColor: bgColor }}
      >
        <div className="rounded-xl shrink-0 size-2" style={{ backgroundColor: dotColor }} />
        <span className="font-medium text-sm whitespace-nowrap" style={{ color: textColor }}>
          {children}
        </span>
      </div>
    );
  }

  return (
    <div
      className="relative flex items-center px-[9px] py-[5px] rounded-xl shrink-0"
      style={{ backgroundColor: bgColor }}
    >
      <div
        aria-hidden
        className="absolute border border-solid inset-0 pointer-events-none rounded-xl"
        style={{ borderColor }}
      />
      <span className="font-semibold text-xs tracking-[0.6px] whitespace-nowrap" style={{ color: textColor }}>
        {children}
      </span>
    </div>
  );
}
