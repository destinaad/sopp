import { useState, useRef, useEffect } from "react";
import { useLocation } from "react-router-dom";
import Navbar from "@/components/layout/Navbar";
import Sidebar from "@/components/layout/Sidebar";
import PageTransition from "@/components/layout/PageTransition";

export default function PageLayout({ children }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const mainRef = useRef(null);
  const location = useLocation();

  // <main> punya scroll sendiri (bukan window), jadi setiap pindah halaman
  // posisi scroll-nya harus direset manual ke atas. Tanpa ini, kalau user
  // lagi scroll ke bawah di halaman A lalu pindah ke halaman B, halaman B
  // langsung "nongol" di tengah-tengah — itu salah satu penyebab kesan
  // "patah" saat pindah menu.
  useEffect(() => {
    mainRef.current?.scrollTo({ top: 0, behavior: "smooth" });
  }, [location.pathname]);

  return (
    <div
      className="flex flex-col h-screen w-screen overflow-hidden"
      style={{ background: "linear-gradient(90deg, #f7f7f6 0%, #f7f7f6 100%)" }}
    >
      <Navbar onMenuToggle={() => setSidebarOpen((o) => !o)} />
      <div className="flex flex-1 overflow-hidden relative">
        <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
        <main ref={mainRef} className="flex-1 overflow-y-auto min-w-0 scrollbar-thin">
          <PageTransition className="flex flex-col gap-6 max-w-5xl mx-auto p-6 pb-20">
            {children}
          </PageTransition>
        </main>
      </div>
    </div>
  );
}