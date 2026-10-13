import { useEffect, useRef, useState } from "react";
import { BrowserRouter, Routes, Route, Navigate, Outlet, useNavigate } from "react-router-dom";
import PageLayout from "@/components/layout/PageLayout";
import { useAuthStore } from "@/store/authStore";
import { getMe } from "@/api/auth";
import { mapUserDariApi, getDefaultDashboard } from "@/utils/mapUser";

// Auth
import Login from "./pages/auth/Login";
import SsoHandoff from "./pages/auth/SsoHandoff";

// Teller
import TellerDashboard from "./pages/teller/Dashboard";
import InputSetoran from "./pages/teller/InputSetoran";
import RiwayatSetoran from "./pages/teller/RiwayatSetoran";

// Verifikator
import InputSetoranKeuangan from "./pages/verifikator/InputSetoran";

// Shared
import Laporan from "./pages/shared/Laporan";
import DetailSetoran from "./pages/shared/DetailSetoran";
import Profile from "./pages/shared/Profile";
import NotFound from "./pages/shared/NotFound";

// Admin
import AdminDashboard from "./pages/admin/Dashboard";
import HakAkses from "./pages/admin/HakAkses";
import LogAktivitas from "./pages/admin/LogAktivitas";

// Komponen ini "mendengarkan" alarm auth:unauthorized dari axios.js.
// Harus di dalam <BrowserRouter> supaya bisa pakai useNavigate.
function SessionGuard() {
  const navigate = useNavigate();
  const logout = useAuthStore((state) => state.logout);

  // useRef supaya listener tidak dibuat ulang tiap render
  const handlerRef = useRef(null);

  useEffect(() => {
    handlerRef.current = () => {
      logout();                               // bersihkan data user di store
      navigate("/login", { replace: true });  // arahkan ke halaman login
    };

    window.addEventListener("auth:unauthorized", handlerRef.current);

    // Cleanup: cabut listener kalau komponen dilepas dari DOM
    return () => {
      window.removeEventListener("auth:unauthorized", handlerRef.current);
    };
  }, [logout, navigate]);

  return null; // komponen ini tidak tampil apapun di layar
}

// Halaman yang butuh Navbar + Sidebar dibungkus lewat sini,
// jadi PageLayout cukup ditulis sekali (tidak perlu ulang di tiap <Route>).
function AppLayout() {
  return (
    <PageLayout>
      <Outlet />
    </PageLayout>
  );
}

// "Satpam" route — cek otorisasi berbasis kapabilitas (capability-based).
// Kalau belum login → ke /login.
// Kalau sudah login tapi tidak punya kapabilitas yang diminta → ke dashboard default user.
// Kalau boleh → lanjut render halaman (<Outlet />).
function RoleGuard({ requiredCapability }) {
  const user = useAuthStore((s) => s.user);

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (requiredCapability) {
    const capabilities = Array.isArray(requiredCapability)
      ? requiredCapability
      : [requiredCapability];
    const hasCapability =
      user.is_superadmin || capabilities.some((cap) => Boolean(user[cap]));
    if (!hasCapability) {
      return <Navigate to={getDefaultDashboard(user)} replace />;
    }
  }

  return <Outlet />;
}

export default function App() {
  const setUser = useAuthStore((s) => s.setUser);

  // Penanda "sudah selesai cek sesi ke server apa belum". Selama masih
  // false, kita TIDAK BOLEH render <Routes> dulu — supaya RoleGuard tidak
  // sempat melempar user ke /login duluan padahal sesinya (cookie) masih
  // valid, cuma authStore di memori yang kosong karena baru refresh.
  const [cekSesiSelesai, setCekSesiSelesai] = useState(false);

  // Jalan SEKALI setiap kali aplikasi pertama kali dibuka/direfresh —
  // menanyakan ke BE "cookie sesi yang nempel di browser ini punya siapa?"
  // lewat GET /api/v1/me. Ini yang membuat sesi login tetap "nyantol"
  // walau halaman di-refresh atau tab baru dibuka.
  useEffect(() => {
    async function cekSesi() {
      try {
        const res = await getMe();
        if (res?.status === "ok") {
          setUser(mapUserDariApi(res.data.user));
        }
      } catch (err) {
        // Wajar terjadi kalau memang belum login / cookie sudah kadaluarsa.
        // Tidak perlu ditangani di sini — biarkan authStore tetap null,
        // nanti RoleGuard yang otomatis mengarahkan ke /login.
      } finally {
        setCekSesiSelesai(true);
      }
    }

    cekSesi();
  }, [setUser]);

  // Selama proses cek sesi masih berjalan, tampilkan layar tunggu supaya
  // routing di bawah tidak sempat jalan dengan data user yang belum pasti.
  if (!cekSesiSelesai) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[#f7f7f6]">
        <span className="text-[#727782] text-sm">Memuat...</span>
      </div>
    );
  }

  return (
    <BrowserRouter>
      {/* SessionGuard dipasang di sini supaya aktif di seluruh aplikasi */}
      <SessionGuard />
      <Routes>
        {/* Public - tanpa Navbar/Sidebar */}
        <Route path="/login" element={<Login />} />
        {/* Path WAJIB persis "/setoran/masuk", sesuai kontrak API §6.1 poin 2 */}
        <Route path="/setoran/masuk" element={<SsoHandoff />} />

        {/* Semua route di bawah ini otomatis dibungkus Navbar + Sidebar */}
        <Route element={<AppLayout />}>

          {/* Teller */}
          <Route element={<RoleGuard />}>
            <Route path="/teller/dashboard" element={<TellerDashboard />} />
            <Route path="/teller/input" element={<InputSetoran />} />
            <Route path="/teller/riwayat" element={<RiwayatSetoran />} />
          </Route>

          {/* Verifikator - Input Setoran Semua Loket */}
          <Route element={<RoleGuard requiredCapability="bisa_input_setoran_semua_loket" />}>
            <Route path="/verifikator/input-setoran" element={<InputSetoranKeuangan />} />
          </Route>

          {/* Dashboard Admin / Verifikator */}
          <Route
            element={
              <RoleGuard
                requiredCapability={[
                  "bisa_akses_laporan",
                  "bisa_edit_setoran_semua_loket",
                ]}
              />
            }
          >
            <Route path="/verifikator/dashboard" element={<AdminDashboard />} />
            <Route path="/admin/dashboard" element={<AdminDashboard />} />
          </Route>

          {/* Khusus Hak Akses */}
          <Route element={<RoleGuard requiredCapability="bisa_atur_hak_akses" />}>
            <Route path="/admin/hak-akses" element={<HakAkses />} />
          </Route>

          {/* Khusus Log Aktivitas */}
          <Route element={<RoleGuard requiredCapability="bisa_lihat_log" />}>
            <Route path="/admin/log" element={<LogAktivitas />} />
          </Route>

          {/* Khusus Laporan */}
          <Route element={<RoleGuard requiredCapability="bisa_akses_laporan" />}>
            <Route path="/laporan" element={<Laporan />} />
          </Route>

          {/* Shared - semua yang sudah login boleh akses */}
          <Route element={<RoleGuard />}>
            <Route path="/setoran/:id" element={<DetailSetoran />} />
            <Route path="/profile" element={<Profile />} />
          </Route>

        </Route>

        {/* Default */}
        <Route path="/" element={<Navigate to="/login" replace />} />
        <Route path="*" element={<NotFound />} />
      </Routes>
    </BrowserRouter>
  );
}