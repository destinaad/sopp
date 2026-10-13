import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuthStore } from "@/store/authStore";
import { IconLock, IconEye, IconEyeOff, IconSpinner } from "@/components/ui/Icon";
import { login as loginApi } from "@/api/auth";
import { getCsrfCookie } from "@/lib/axios";
import { mapUserDariApi, getDefaultDashboard } from "@/utils/mapUser";

export default function Login() {
  const navigate = useNavigate();
  const setUser = useAuthStore((s) => s.setUser);

  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");

    if (!username || !password) {
      setError("Username dan password wajib diisi.");
      return;
    }

    setLoading(true);
    try {
      await getCsrfCookie();
      const res = await loginApi(username, password);
      if (res?.status !== "ok") {
        setError(res?.message || "Username atau password salah.");
        return;
      }

      const user = mapUserDariApi(res.data.user);
      setUser(user);
      navigate(getDefaultDashboard(user), { replace: true });
    }  catch (err) {
      const pesanBe = err?.response?.data?.message;
      setError(pesanBe || "Gagal masuk. Periksa username/password atau koneksi Anda.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-[#f7f7f6] px-4 py-10">
      <div className="flex flex-col items-center gap-1 mb-6">
        <span className="font-bold text-[#710014] text-3xl tracking-tight">SOPP Setoran</span>
        <span className="text-[#424751] text-sm">Sistem Pencatatan Setoran Loket</span>
      </div>

      <div className="bg-white rounded-lg border border-[#d9d9d6] w-full max-w-sm overflow-hidden">
        <div className="p-6 border-b border-[#efefed]">
          <h1 className="font-semibold text-[#191c20] text-lg">Masuk ke Sistem</h1>
        </div>

        <form onSubmit={handleSubmit} className="flex flex-col gap-4 p-6">
          <div className="flex flex-col gap-1.5">
            <label className="font-semibold text-[#424751] text-sm">Username</label>
            <input
              type="text"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              placeholder="Masukkan username"
              className="w-full border border-[#d9d9d6] rounded px-3 py-2.5 text-sm text-[#191c20] placeholder-[#9ca3af] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="font-semibold text-[#424751] text-sm">Password</label>
            <div className="relative">
              <IconLock className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-[#9ca3af]" />
              <input
                type={showPassword ? "text" : "password"}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                className="w-full border border-[#d9d9d6] rounded px-9 py-2.5 text-sm text-[#191c20] placeholder-[#9ca3af] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
              />
              <button
                type="button"
                onClick={() => setShowPassword((s) => !s)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9ca3af] hover:text-[#424751]"
                aria-label={showPassword ? "Sembunyikan password" : "Tampilkan password"}
              >
                {showPassword ? <IconEyeOff className="size-4" /> : <IconEye className="size-4" />}
              </button>
            </div>
          </div>

          {error && (
            <div className="bg-[#ffdad6] border border-[#ba1a1a]/30 rounded px-4 py-2.5">
              <span className="text-[#93000a] text-sm">{error}</span>
            </div>
          )}

          <button
            type="submit"
            disabled={loading}
            className="bg-[#710014] text-white text-sm font-medium rounded px-6 py-3 mt-1 hover:bg-[#5a000f] disabled:opacity-70 transition-colors flex items-center justify-center gap-2"
          >
            {loading ? (
              <>
                <IconSpinner className="size-4" color="white" />
                <span>Memproses...</span>
              </>
            ) : (
              "Masuk"
            )}
          </button>
        </form>
      </div>

      <p className="text-[#9ca3af] text-xs mt-6 text-center">
        Sistem internal Kopegtel
      </p>
    </div>
  );
}