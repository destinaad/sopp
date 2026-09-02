import { useEffect, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useAuthStore } from "@/store/authStore";
import { verifySSO } from "@/api/auth";
import { getCsrfCookie } from "@/lib/axios";
import { IconSpinner, IconShieldCheck } from "@/components/ui/Icon";
import { mapUserDariApi, DASHBOARD_PER_ROLE } from "@/utils/mapUser";

export default function SsoHandoff() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const setUser = useAuthStore((s) => s.setUser);

  const [status, setStatus] = useState("memverifikasi"); // "memverifikasi" | "gagal"
  const [pesanError, setPesanError] = useState("");

  useEffect(() => {
    const token = searchParams.get("token");

    if (!token) {
      setStatus("gagal");
      setPesanError("Link tidak valid — token tidak ditemukan di alamat website.");
      return;
    }

    let batal = false;

    async function prosesSso() {
      try {
        // WAJIB urut: csrf-cookie dulu, baru verifySSO — sesuai kontrak
        // API §6.1 poin 3. Tanpa ini, cookie sesi tidak akan ter-set
        // dengan benar walau verifySSO sendiri "berhasil".
        await getCsrfCookie();

        const res = await verifySSO(token);
        if (batal) return;

        if (res?.status !== "ok") {
          setStatus("gagal");
          setPesanError(res?.message || "Verifikasi gagal. Silakan coba lagi lewat SOPP.");
          return;
        }

        // Bentuk response dibungkus { user: {...} } sesuai kontrak §6.2,
        // BUKAN field mentah langsung di res.data.
        const user = mapUserDariApi(res.data.user);
        setUser(user);
        navigate(DASHBOARD_PER_ROLE[user.role] || "/teller/dashboard", { replace: true });
      } catch (err) {
        if (batal) return;
        setStatus("gagal");
        setPesanError(err?.message || "Terjadi kesalahan saat verifikasi. Silakan coba lagi.");
      }
    }

    prosesSso();

    return () => {
      batal = true;
    };
  }, [searchParams, setUser, navigate]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-[#f7f7f6] px-4">
      <div className="bg-white rounded-lg border border-[#d9d9d6] shadow-sm max-w-sm w-full p-8 flex flex-col items-center text-center gap-4">
        {status === "memverifikasi" ? (
          <>
            <IconSpinner className="size-8" color="#710014" />
            <div>
              <h1 className="font-semibold text-[#191c20] text-lg">Memverifikasi akses...</h1>
              <p className="text-[#727782] text-sm mt-1">
                Mohon tunggu, kamu akan diarahkan secara otomatis.
              </p>
            </div>
          </>
        ) : (
          <>
            <div className="bg-[#ffdad6] rounded-full size-14 flex items-center justify-center">
              <IconShieldCheck className="size-7" color="#93000a" />
            </div>
            <div>
              <h1 className="font-semibold text-[#191c20] text-lg">Verifikasi Gagal</h1>
              <p className="text-[#93000a] text-sm mt-1">{pesanError}</p>
            </div>
            <button
              onClick={() => navigate("/login")}
              className="bg-[#710014] text-white text-sm font-medium rounded px-6 py-2.5 hover:bg-[#5a000f] transition-colors"
            >
              Ke Halaman Login
            </button>
          </>
        )}
      </div>
    </div>
  );
}