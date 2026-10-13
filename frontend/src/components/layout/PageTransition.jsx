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

    // Satu RAF sudah cukup untuk memastikan browser sempat commit state
    // opacity:0 sebelum kita balikin ke 100 — asal durasinya pendek dan
    // easing-nya "keluar cepat, masuk pelan" (easeOut), gerakannya tetap
    // terasa nyambung/mengalir, bukan nge-blank. Delay lebih lama justru
    // yang bikin terasa "patah" karena ada jeda diam di tengah.
    const raf = requestAnimationFrame(() => setTampil(true));

    return () => cancelAnimationFrame(raf);
  }, [location.pathname]);

  return (
    <div
      className={`transition-[opacity,transform] duration-200 ease-[cubic-bezier(0.22,1,0.36,1)] will-change-transform ${
        tampil ? "opacity-100 translate-y-0" : "opacity-0 translate-y-1.5"
      } ${className}`}
    >
      {children}
    </div>
  );
}