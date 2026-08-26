import { useNavigate } from "react-router-dom";
import { IconUser, IconLogout, IconMenu } from "@/components/ui/Icon";
import { useAuthStore } from "@/store/authStore";
import { logout as logoutApi } from "@/api/auth";

/**
 * Bar atas aplikasi: logo/judul, tombol menu (mobile), search, profil, logout.
 * onMenuToggle dipanggil dari PageLayout untuk buka/tutup Sidebar di mobile.
 */
export default function Navbar({ onMenuToggle }) {
  const clearUser = useAuthStore((s) => s.logout);
  const navigate = useNavigate();

  async function handleLogout() {
    try {
      await logoutApi(); // 1. beritahu BE → matiin sesi di server
    } catch {
      // Tetap lanjut logout di FE walau BE error
      // (misal: sesi sudah expired duluan di server)
    } finally {
      clearUser();                            // 2. bersihkan data user di store
      navigate("/login", { replace: true }); // 3. arahkan ke halaman login
    }
  }

  return (
    <header className="bg-white border-b border-[#c2c6d2] flex h-16 items-center justify-between px-6 shrink-0 w-full z-20">
      <div className="flex items-center gap-3">
        <button
          className="md:hidden p-1 text-[#424751]"
          onClick={onMenuToggle}
          aria-label="Toggle menu"
        >
          <IconMenu />
        </button>
        <span className="font-semibold text-[#710014] text-2xl tracking-tight">SOPP Setoran</span>
      </div>
      <div className="flex gap-4 items-center">
        <button aria-label="Profil" onClick={() => navigate("/profile")}>
          <IconUser />
        </button>
        <button aria-label="Keluar" onClick={handleLogout}>
          <IconLogout />
        </button>
      </div>
    </header>
  );
}