import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { IconUser, IconLogout, IconMenu } from "@/components/ui/Icon";
import Modal from "@/components/ui/Modal";
import { useAuthStore } from "@/store/authStore";
import { logout as logoutApi } from "@/api/auth";

export default function Navbar({ onMenuToggle }) {
  const clearUser = useAuthStore((s) => s.logout);
  const isSuperadmin = useAuthStore((s) => Boolean(s.user?.is_superadmin));
  const navigate = useNavigate();

  const [konfirmasiKeluar, setKonfirmasiKeluar] = useState(false);
  const [sedangKeluar, setSedangKeluar] = useState(false);

  async function handleLogout() {
    if (sedangKeluar) return; 
    setSedangKeluar(true);
    try {
      await logoutApi(); 
    } finally {
      clearUser();                            
      navigate("/login", { replace: true });
    }
  }

  return (
    <>
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
          {!isSuperadmin && (
            <button aria-label="Profil" onClick={() => navigate("/profile")}>
              <IconUser />
            </button>
          )}
          <button aria-label="Keluar" onClick={() => setKonfirmasiKeluar(true)}>
            <IconLogout />
          </button>
        </div>
      </header>

      {konfirmasiKeluar && (
        <Modal title="Keluar dari akun?" onClose={() => setKonfirmasiKeluar(false)}>
          <p className="text-sm text-[#424751] mb-5">
            Kamu akan keluar dari SOPP Setoran. Pastikan pekerjaanmu sudah disimpan.
          </p>
          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={() => setKonfirmasiKeluar(false)}
              disabled={sedangKeluar}
              className="px-4 py-2 rounded-md border border-[#c2c6d2] text-sm text-[#191c20] hover:bg-[#f2f3fa] disabled:opacity-50"
            >
              Batal
            </button>
            <button
              type="button"
              onClick={handleLogout}
              disabled={sedangKeluar}
              className="px-4 py-2 rounded-md bg-[#710014] text-sm text-white hover:opacity-90 disabled:opacity-50"
            >
              {sedangKeluar ? "Keluar..." : "Ya, Keluar"}
            </button>
          </div>
        </Modal>
      )}
    </>
  );
}