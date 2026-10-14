import { useEffect, useRef, useState } from "react";
import { BrowserRouter, Routes, Route, Navigate, Outlet, useNavigate } from "react-router-dom";
import PageLayout from "@/components/layout/PageLayout";
import { useAuthStore } from "@/store/authStore";
import { getMe } from "@/api/auth";
import { mapUserDariApi, getDefaultDashboard } from "@/utils/mapUser";

import Login from "./pages/auth/Login";
import SsoHandoff from "./pages/auth/SsoHandoff";
import TellerDashboard from "./pages/teller/Dashboard";
import InputSetoran from "./pages/teller/InputSetoran";
import RiwayatSetoran from "./pages/teller/RiwayatSetoran";
import InputSetoranKeuangan from "./pages/verifikator/InputSetoran";
import Laporan from "./pages/shared/Laporan";
import DetailSetoran from "./pages/shared/DetailSetoran";
import Profile from "./pages/shared/Profile";
import NotFound from "./pages/shared/NotFound";
import AdminDashboard from "./pages/admin/Dashboard";
import HakAkses from "./pages/admin/HakAkses";
import LogAktivitas from "./pages/admin/LogAktivitas";

function SessionGuard() {
  const navigate = useNavigate();
  const logout = useAuthStore((state) => state.logout);
  const handlerRef = useRef(null);

  useEffect(() => {
    handlerRef.current = () => {
      logout();
      navigate("/login", { replace: true });
    };

    window.addEventListener("auth:unauthorized", handlerRef.current);

    return () => {
      window.removeEventListener("auth:unauthorized", handlerRef.current);
    };
  }, [logout, navigate]);

  return null;
}

function AppLayout() {
  return (
    <PageLayout>
      <Outlet />
    </PageLayout>
  );
}

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
  const [cekSesiSelesai, setCekSesiSelesai] = useState(false);

  useEffect(() => {
    async function cekSesi() {
      try {
        const res = await getMe();
        if (res?.status === "ok") {
          setUser(mapUserDariApi(res.data.user));
        }
      } catch (err) {
      } finally {
        setCekSesiSelesai(true);
      }
    }

    cekSesi();
  }, [setUser]);

  if (!cekSesiSelesai) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[#f7f7f6]">
        <span className="text-[#727782] text-sm">Memuat...</span>
      </div>
    );
  }

  return (
    <BrowserRouter>
      <SessionGuard />
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/setoran/masuk" element={<SsoHandoff />} />

        <Route element={<AppLayout />}>
          <Route element={<RoleGuard />}>
            <Route path="/teller/dashboard" element={<TellerDashboard />} />
            <Route path="/teller/input" element={<InputSetoran />} />
            <Route path="/teller/riwayat" element={<RiwayatSetoran />} />
          </Route>

          <Route element={<RoleGuard requiredCapability="bisa_input_setoran_semua_loket" />}>
            <Route path="/verifikator/input-setoran" element={<InputSetoranKeuangan />} />
          </Route>

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

          <Route element={<RoleGuard requiredCapability="bisa_atur_hak_akses" />}>
            <Route path="/admin/hak-akses" element={<HakAkses />} />
          </Route>

          <Route element={<RoleGuard requiredCapability="bisa_lihat_log" />}>
            <Route path="/admin/log" element={<LogAktivitas />} />
          </Route>

          <Route element={<RoleGuard requiredCapability="bisa_akses_laporan" />}>
            <Route path="/laporan" element={<Laporan />} />
          </Route>

          <Route element={<RoleGuard />}>
            <Route path="/setoran/:id" element={<DetailSetoran />} />
            <Route path="/profile" element={<Profile />} />
          </Route>
        </Route>

        <Route path="/" element={<Navigate to="/login" replace />} />
        <Route path="*" element={<NotFound />} />
      </Routes>
    </BrowserRouter>
  );
}