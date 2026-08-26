import { useState, useEffect, useRef } from "react";
import { useLocation } from "react-router-dom";

export default function PageTransition({ children, className = "" }) {
  const location = useLocation();
  const [tampil, setTampil] = useState(true);
  const prevPath = useRef(location.pathname);

  useEffect(() => {
    if (prevPath.current === location.pathname) return;
    prevPath.current = location.pathname;
    setTampil(false);

    // Double RAF — WAJIB dua, bukan satu. RAF pertama nunggu browser
    // benar-benar commit state opacity:0 ke layar dulu, baru RAF kedua
    // (dijadwalkan DI DALAM callback RAF pertama) boleh balikin ke
    // opacity:100. Kalau cuma 1 RAF, browser sering nge-gabungin
    // dua perubahan itu jadi 1 frame yang sama, jadi transisinya
    // nggak sempat "kelihatan" mulai dari 0 — itu penyebab kenapa
    // kemarin kelihatannya masih patah/instan.
    let raf2;
    const raf1 = requestAnimationFrame(() => {
      raf2 = requestAnimationFrame(() => setTampil(true));
    });

    return () => {
      cancelAnimationFrame(raf1);
      if (raf2) cancelAnimationFrame(raf2);
    };
  }, [location.pathname]);

  return (
    <div
      className={`transition-all duration-300 ease-out ${
        tampil ? "opacity-100 translate-y-0" : "opacity-0 translate-y-1"
      } ${className}`}
    >
      {children}
    </div>
  );
}