import { NavLink } from "react-router-dom";
import { useAuthStore } from "@/store/authStore";
import {
  IconDashboard,
  IconInput,
  IconHistory,
  IconDocumentText,
  IconAdminHakAkses,
  IconAdminLogAktivitas,
} from "@/components/ui/Icon";

/**
 * Menu navigasi kiri. Isinya BERBEDA tergantung `user.role` — ini yang
 * membuat Sidebar "role-aware": backend cuma kasih tahu role user (mis.
 * "teller"/"verifikator"/"admin") lewat data login, SEMUA logika "role apa
 * dapat menu apa" ada di sini (frontend), bukan di backend.
 *
 * Kalau nanti nambah role atau menu baru, cukup ubah object
 * `navItemsPerRole` di bawah — tidak perlu ubah bagian lain file ini.
 */
const navItemsPerRole = {
  teller: [
    { to: "/teller/dashboard",  label: "Dashboard",       icon: IconDashboard },
    { to: "/teller/input",      label: "Input Setoran",   icon: IconInput },
    { to: "/teller/riwayat",    label: "Riwayat Setoran", icon: IconHistory },
  ],
  verifikator: [
    { to: "/verifikator/dashboard",     label: "Dashboard",     icon: IconDashboard },
    { to: "/verifikator/input-setoran", label: "Input Setoran", icon: IconInput },
    { to: "/laporan",                   label: "Laporan",        icon: IconDocumentText },
    { to: "/admin/hak-akses",           label: "Hak Akses",      icon: IconAdminHakAkses },
    { to: "/admin/log",                 label: "Log Aktivitas",  icon: IconAdminLogAktivitas },
  ],
  admin: [
    { to: "/admin/dashboard",  label: "Dashboard",     icon: IconDashboard },
    { to: "/laporan",          label: "Laporan",        icon: IconDocumentText },
    { to: "/admin/hak-akses",  label: "Hak Akses",      icon: IconAdminHakAkses },
    { to: "/admin/log",        label: "Log Aktivitas",  icon: IconAdminLogAktivitas },
  ],
};

export default function Sidebar({ open, onClose }) {
  const user = useAuthStore((s) => s.user);
  const navItems = navItemsPerRole[user?.role] ?? navItemsPerRole.teller;

  const linkBase =
    "flex gap-3 items-center px-4 py-2.5 rounded-lg w-full text-sm font-medium transition-colors";

  return (
    <>
      {/* Overlay, cuma muncul di mobile saat sidebar dibuka */}
      {open && <div className="fixed inset-0 bg-black/30 z-10 md:hidden" onClick={onClose} />}

      <aside
        className={`
          fixed md:static top-0 left-0 h-screen md:h-[calc(100vh-64px)] w-64 bg-primary
          flex flex-col z-30 transition-transform duration-200 shrink-0
          ${open ? "translate-x-0" : "-translate-x-full md:translate-x-0"}
        `}
      >
        {/* Brand, hanya perlu tampil di mobile karena di desktop Navbar sudah menutupi tinggi ini */}
        <div className="flex items-center h-16 px-6 shrink-0 md:hidden">
          <span className="font-semibold text-white text-2xl tracking-tight">SOPP Setoran</span>
        </div>

        <nav className="flex flex-col gap-2 px-3 pt-4 flex-1 overflow-y-auto">
          {navItems.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              onClick={onClose}
              className={({ isActive }) =>
                `${linkBase} ${
                  isActive
                    ? "bg-white text-primary shadow-sm"
                    : "text-white/80 hover:bg-white/10 hover:text-white"
                }`
              }
            >
              <Icon />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="p-4 shrink-0">
          <div className="bg-white/10 rounded-lg p-4 flex flex-col gap-1">
            <span className="font-semibold text-white text-xs tracking-[0.6px]">Internal Settlement System</span>
            <span className="font-medium text-white/60 text-xs tracking-[0.6px]">v2.4.0-stable</span>
          </div>
        </div>
      </aside>
    </>
  );
}