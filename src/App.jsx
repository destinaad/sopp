import { useEffect, useRef } from "react";
import { BrowserRouter, Routes, Route, Navigate, Outlet, useNavigate } from "react-router-dom";
import PageLayout from "@/components/layout/PageLayout";
import { useAuthStore } from "@/store/authStore";

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

// "Satpam" route — cek apakah role user termasuk dalam daftar allowedRoles.
// Kalau belum login → ke /login.
// Kalau sudah login tapi role-nya tidak boleh → ke dashboard miliknya sendiri.
// Kalau boleh → lanjut render halaman (<Outlet />).
const dashboardPerRole = {
  teller:      "/teller/dashboard",
  verifikator: "/verifikator/dashboard",
  admin:       "/admin/dashboard",
};

function RoleGuard({ allowedRoles }) {
  const user = useAuthStore((s) => s.user);

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (!allowedRoles.includes(user.role)) {
    // User sudah login tapi bukan haknya — kembalikan ke dashboard mereka
    return <Navigate to={dashboardPerRole[user.role] ?? "/login"} replace />;
  }

  return <Outlet />;
}

export default function App() {
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

          {/* Khusus Teller */}
          <Route element={<RoleGuard allowedRoles={["teller"]} />}>
            <Route path="/teller/dashboard" element={<TellerDashboard />} />
            <Route path="/teller/input" element={<InputSetoran />} />
            <Route path="/teller/riwayat" element={<RiwayatSetoran />} />
          </Route>

          {/* Khusus Verifikator */}
          <Route element={<RoleGuard allowedRoles={["verifikator"]} />}>
            <Route path="/verifikator/input-setoran" element={<InputSetoranKeuangan />} />
            <Route path="/verifikator/dashboard" element={<AdminDashboard />} />
          </Route>

          {/* Khusus Admin */}
          <Route element={<RoleGuard allowedRoles={["admin"]} />}>
            <Route path="/admin/dashboard" element={<AdminDashboard />} />
            <Route path="/admin/hak-akses" element={<HakAkses />} />
            <Route path="/admin/log" element={<LogAktivitas />} />
          </Route>

          {/* Shared - semua role boleh akses asal sudah login */}
          <Route element={<RoleGuard allowedRoles={["teller", "verifikator", "admin"]} />}>
            <Route path="/laporan" element={<Laporan />} />
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