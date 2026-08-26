import { useState } from "react";
import Navbar from "@/components/layout/Navbar";
import Sidebar from "@/components/layout/Sidebar";
import PageTransition from "@/components/layout/PageTransition";

export default function PageLayout({ children }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div
      className="flex flex-col h-screen w-screen overflow-hidden"
      style={{ background: "linear-gradient(90deg, #f7f7f6 0%, #f7f7f6 100%)" }}
    >
      <Navbar onMenuToggle={() => setSidebarOpen((o) => !o)} />
      <div className="flex flex-1 overflow-hidden relative">
        <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
        <main className="flex-1 overflow-y-auto min-w-0">
          <PageTransition className="flex flex-col gap-6 max-w-5xl mx-auto p-6 pb-20">
            {children}
          </PageTransition>
        </main>
      </div>
    </div>
  );
}
