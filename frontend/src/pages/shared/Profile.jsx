import { useState } from "react";
import { Navigate } from "react-router-dom";
import { useAuthStore } from "@/store/authStore";
import Toast from "@/components/ui/Toast";
import Card from "@/components/ui/Card";
import { IconLock, IconEye, IconEyeOff, IconUser } from "@/components/ui/Icon";
import { gantiPassword } from "@/api/teller";
import { getDefaultDashboard } from "@/utils/mapUser";

/**
 * Halaman "Ubah Password" — diakses lewat tombol Profil di Navbar.
 * Route (di App.jsx): <Route path="/profile" element={<Profile />} />
 *
 * Field: password baru, konfirmasi password baru — TIDAK ADA field password
 * lama, sesuai kontrak API §7.6 (POST /api/user/password cuma butuh 1 field
 * password baru, karena password lama akun teller selalu null / tidak
 * pernah diset dari awal).
 *
 * Validasi client-side dulu (panjang minimal, konfirmasi cocok) sebelum
 * kirim ke gantiPassword() (api/teller.js) — supaya teller dapat feedback
 * instan tanpa nunggu round-trip ke server buat hal yang bisa dicek di FE.
 * Validasi "password lama benar/salah" TIDAK relevan di sini (memang tidak
 * ada field-nya), tapi validasi lain (panjang minimal versi server) tetap
 * bisa berbeda dari validasi FE — makanya tetap ada penanganan error dari
 * server di handleSubmit, bukan cuma andalkan validasi client-side.
 *
 * Sukses submit -> tampilkan Toast hijau "Password berhasil diubah" (auto
 * hilang 3 detik) + reset form.
 */
export default function Profile() {
  const user = useAuthStore((s) => s.user);

  const [passwordBaru, setPasswordBaru] = useState("");
  const [konfirmasiPassword, setKonfirmasiPassword] = useState("");
  const [showBaru, setShowBaru] = useState(false);
  const [showKonfirmasi, setShowKonfirmasi] = useState(false);
  const [error, setError] = useState("");
  const [showToast, setShowToast] = useState(false);
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");

    if (!passwordBaru || !konfirmasiPassword) {
      setError("Semua field wajib diisi.");
      return;
    }
    if (passwordBaru.length < 8) {
      setError("Password baru minimal 8 karakter.");
      return;
    }
    if (passwordBaru !== konfirmasiPassword) {
      setError("Konfirmasi password tidak cocok.");
      return;
    }

    setLoading(true);
    try {
      await gantiPassword(passwordBaru, konfirmasiPassword);
      setPasswordBaru("");
      setKonfirmasiPassword("");
      setShowToast(true);
    } catch (err) {
      const status = err?.response?.status;
      const data = err?.response?.data;

      if (status === 403) {
        setError(data?.message || "Akun ini tidak bisa mengubah password lewat halaman ini.");
      } else if (status === 429) {
        setError(data?.message || "Tunggu sebentar sebelum menyimpan lagi.");
      } else {
        const pesanServer = data?.errors?.password?.[0];
        setError(pesanServer || "Gagal mengubah password. Coba lagi.");
      }
    } finally {
      setLoading(false);
    }
  };

  // Superadmin tidak punya halaman ini (BE menolak ganti password superadmin).
  // Dipasang SETELAH semua hook di atas supaya urutan hook tetap konsisten.
  if (user?.is_superadmin) return <Navigate to={getDefaultDashboard(user)} replace />;

  return (
    <>
      <div className="flex flex-col gap-0.5">
        <h1 className="font-bold text-[#191c20] text-3xl tracking-tight">Profil Saya</h1>
        <p className="text-[#424751] text-sm">Kelola informasi akun dan keamanan password kamu.</p>
      </div>

      <Card className="max-w-lg">
        <div className="flex items-center gap-3 p-6 border-b border-[#efefed]">
          <div className="bg-[rgba(113,0,20,0.1)] rounded-full size-12 flex items-center justify-center shrink-0">
            <IconUser className="size-6" color="#710014" />
          </div>
          <div className="flex flex-col">
            <span className="font-semibold text-[#191c20] text-base">{user.name}</span>
            <span className="text-[#727782] text-xs">{user.loket}</span>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="flex flex-col gap-4 p-6">
          <h2 className="font-semibold text-[#191c20] text-lg -mt-1">Ubah Password</h2>

          <PasswordField
            label="Password Baru"
            value={passwordBaru}
            onChange={setPasswordBaru}
            show={showBaru}
            onToggleShow={() => setShowBaru((s) => !s)}
            hint="Minimal 8 karakter"
          />
          <PasswordField
            label="Konfirmasi Password"
            value={konfirmasiPassword}
            onChange={setKonfirmasiPassword}
            show={showKonfirmasi}
            onToggleShow={() => setShowKonfirmasi((s) => !s)}
          />

          {error && (
            <div className="bg-[#ffdad6] border border-[#ba1a1a]/30 rounded px-4 py-2.5">
              <span className="text-[#93000a] text-sm">{error}</span>
            </div>
          )}

          <button
            type="submit"
            disabled={loading}
            className="bg-[#710014] text-white text-sm font-medium rounded px-6 py-3 mt-2 hover:bg-[#5a000f] disabled:opacity-50 disabled:cursor-not-allowed transition-colors self-start"
          >
            {loading ? "Menyimpan..." : "Simpan Password"}
          </button>
        </form>
      </Card>

      {showToast && (
        <Toast message="Password berhasil diubah" onClose={() => setShowToast(false)} />
      )}
    </>
  );
}

function PasswordField({ label, value, onChange, show, onToggleShow, hint }) {
  return (
    <div className="flex flex-col gap-1.5">
      <label className="font-semibold text-[#424751] text-sm">{label}</label>
      <div className="relative">
        <IconLock className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-[#9ca3af]" />
        <input
          type={show ? "text" : "password"}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder="••••••••"
          className="w-full border border-[#d9d9d6] rounded px-9 py-2.5 text-sm text-[#191c20] placeholder-[#9ca3af] focus:outline-none focus:ring-2 focus:ring-[#710014]/30"
        />
        <button
          type="button"
          onClick={onToggleShow}
          className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9ca3af] hover:text-[#424751]"
          aria-label={show ? "Sembunyikan password" : "Tampilkan password"}
        >
          {show ? <IconEyeOff className="size-4" /> : <IconEye className="size-4" />}
        </button>
      </div>
      {hint && <span className="text-[#9ca3af] text-xs">{hint}</span>}
    </div>
  );
}