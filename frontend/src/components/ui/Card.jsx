/**
 * Kotak kartu putih dengan border tipis + rounded corner.
 * Ini komponen "polos" - hanya wadah, tidak tahu isinya apa.
 */
export default function Card({ children, className = "" }) {
  return (
    <div className={`relative bg-white rounded-lg transition-shadow duration-200 hover:shadow-md ${className}`}>
      <div aria-hidden className="absolute border border-[#d9d9d6] border-solid inset-0 pointer-events-none rounded-lg" />
      {children}
    </div>
  );
}
