import { NavLink } from "react-router-dom";
import { useAuthStore } from "@/store/authStore";
import { getDefaultDashboard } from "@/utils/mapUser";
import {
  IconDashboard,
  IconInput,
  IconHistory,
  IconDocumentText,
  IconAdminHakAkses,
  IconAdminLogAktivitas,
} from "@/components/ui/Icon";

/**
 * Menu navigasi kiri dinamis berbasis kapabilitas (capability-based).
 * Menampilkan item navigasi sesuai hak akses granular yang dimiliki user:
 * - Laporan: user?.bisa_akses_laporan || user?.is_superadmin
 * - Hak Akses: user?.bisa_atur_hak_akses || user?.is_superadmin
 * - Log: user?.bisa_lihat_log || user?.is_superadmin
 * - Input Setoran: /verifikator/input-setoran jika bisa_input_setoran_semua_loket,
 *   atau /teller/input jika teller loket.
 */
export default function Sidebar({ open, onClose }) {
  const user = useAuthStore((s) => s.user);

  const isSuperadmin = Boolean(user?.is_superadmin);
  const bisaAksesLaporan = Boolean(user?.bisa_akses_laporan || isSuperadmin);
  const bisaAturHakAkses = Boolean(user?.bisa_atur_hak_akses || isSuperadmin);
  const bisaLihatLog = Boolean(user?.bisa_lihat_log || isSuperadmin);
  const bisaInputSemuaLoket = Boolean(user?.bisa_input_setoran_semua_loket);
  const bisaEditSemuaLoket = Boolean(user?.bisa_edit_setoran_semua_loket);

  const navItems = [];

  // Dashboard: satu sumber kebenaran dengan getDefaultDashboard (mapUser.js).
  // Menu hanya tampil kalau tujuannya memang sebuah dashboard -- user yang
  // tujuannya /admin/hak-akses, /admin/log atau /profile sudah punya menu
  // sendiri / tidak butuh menu "Dashboard".
  const dashboardPath = getDefaultDashboard(user);
  if (dashboardPath.endsWith("/dashboard")) {
    navItems.push({ to: dashboardPath, label: "Dashboard", icon: IconDashboard });
  }

  // Input Setoran
  if (bisaInputSemuaLoket) {
    navItems.push({ to: "/verifikator/input-setoran", label: "Input Setoran", icon: IconInput });
  } else if (!isSuperadmin && user?.loket_id) {
    navItems.push({ to: "/teller/input", label: "Input Setoran", icon: IconInput });
  }

  // Riwayat Setoran: teller terikat loket (setoran miliknya sendiri) ATAU akun
  // berkapabilitas edit lintas-loket (BE: GET /riwayat?loket_id=... butuh
  // bisa_edit_setoran_semua_loket, tampil semua setoran submitted di loket itu).
  if ((!isSuperadmin && user?.loket_id) || bisaEditSemuaLoket) {
    navItems.push({ to: "/teller/riwayat", label: "Riwayat Setoran", icon: IconHistory });
  }

  // Menu berbasis kapabilitas
  if (bisaAksesLaporan) {
    navItems.push({ to: "/laporan", label: "Laporan", icon: IconDocumentText });
  }

  if (bisaAturHakAkses) {
    navItems.push({ to: "/admin/hak-akses", label: "Hak Akses", icon: IconAdminHakAkses });
  }

  if (bisaLihatLog) {
    navItems.push({ to: "/admin/log", label: "Log Aktivitas", icon: IconAdminLogAktivitas });
  }


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

      </aside>
    </>
  );
}