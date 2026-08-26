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

  const linkBase = "flex gap-4 items-center px-4 py-2 rounded w-full text-sm font-medium transition-colors";

  return (
    <>
      {/* Overlay, cuma muncul di mobile saat sidebar dibuka */}
      {open && <div className="fixed inset-0 bg-black/30 z-10 md:hidden" onClick={onClose} />}

      <aside
        className={`
          fixed md:static top-16 left-0 h-[calc(100vh-64px)] w-60 bg-white border-r border-[#c2c6d2]
          flex flex-col z-20 transition-transform duration-200
          ${open ? "translate-x-0" : "-translate-x-full md:translate-x-0"}
        `}
      >
        <nav className="flex flex-col gap-1 p-2 flex-1">
          {navItems.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              onClick={onClose}
              className={({ isActive }) =>
                `${linkBase} ${isActive ? "bg-[#710014] text-[#f7f5f5]" : "text-[#424751] hover:bg-[#f7f7f6]"}`
              }
            >
              <Icon />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="border-t border-[#c2c6d2] p-4">
          <div className="bg-[#ededf4] rounded p-4 flex flex-col gap-1">
            <span className="font-semibold text-[#424751] text-xs tracking-[0.6px]">Internal Settlement System</span>
            <span className="font-semibold text-[#727782] text-xs tracking-[0.6px]">v2.4.0-stable</span>
          </div>
        </div>
      </aside>
    </>
  );
}