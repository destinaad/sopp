/**
 * Modal/popup overlay generik. Komponen "polos" di level ui/ — tidak tahu
 * konteks bisnis, cuma terima title + children + onClose.
 * Klik area gelap di luar kotak akan menutup modal.
 */
import { useState, useEffect } from "react";

export default function Modal({ title, onClose, children }) {
  const [tampil, setTampil] = useState(false);

  useEffect(() => {
    const t = requestAnimationFrame(() => setTampil(true));
    return () => cancelAnimationFrame(t);
  }, []);

  const handleClose = () => {
    setTampil(false);
    setTimeout(onClose, 150);
  };

  return (
    <div
      className={`fixed inset-0 z-50 flex items-center justify-center p-5 transition-opacity duration-200
        ${tampil ? "bg-black/50 opacity-100" : "bg-black/0 opacity-0"}`}
      onClick={handleClose}
    >
      <div
        className={`bg-white rounded-lg p-6 max-w-md w-full shadow-lg transition-all duration-200 ease-out
          ${tampil ? "opacity-100 scale-100" : "opacity-0 scale-95"}`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between mb-4">
          <span className="font-bold text-[#191c20] text-base">{title}</span>
          <button onClick={handleClose} className="text-[#727782] text-2xl leading-none hover:text-[#191c20] transition-colors" aria-label="Tutup">
            ×
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}