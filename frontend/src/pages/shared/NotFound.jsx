import { useNavigate } from "react-router-dom";
import { useAuthStore } from "@/store/authStore";
import { getDefaultDashboard } from "@/utils/mapUser";

export default function NotFound() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);

  // Kalau sudah login, tombol balik ke dashboard default sesuai kapabilitas.
  // Kalau belum login, balik ke halaman Login.
  function handleBack() {
    if (!user) {
      navigate("/login", { replace: true });
      return;
    }

    navigate(getDefaultDashboard(user), { replace: true });
  }


  return (
    <div className="min-h-screen bg-[#f7f7f6] flex flex-col items-center justify-center px-4">
      <div className="bg-white rounded-lg border border-[#c2c6d2] shadow-sm w-full max-w-md overflow-hidden">

        {/* Header merah khas SOPP */}
        <div className="bg-[#710014] px-6 py-5">
          <span className="font-semibold text-white text-2xl tracking-tight">SOPP Setoran</span>
        </div>

        {/* Isi */}
        <div className="flex flex-col items-center gap-4 px-6 py-10 text-center">
          {/* Angka 404 besar */}
          <span className="font-bold text-[#710014] text-7xl tracking-tight select-none">
            404
          </span>

          <div className="flex flex-col gap-1.5">
            <h1 className="font-semibold text-[#191c20] text-xl">
              Halaman tidak ditemukan
            </h1>
            <p className="text-[#727782] text-sm leading-relaxed">
              Alamat yang kamu ketik tidak ada di sistem ini.
              <br />
              Cek kembali URL-nya, atau klik tombol di bawah untuk kembali.
            </p>
          </div>

          <button
            onClick={handleBack}
            className="mt-2 bg-[#710014] text-white text-sm font-medium rounded px-6 py-2.5 hover:bg-[#5a000f] transition-colors"
          >
            {user ? "Kembali ke Dashboard" : "Kembali ke Login"}
          </button>
        </div>

        {/* Footer */}
        <div className="flex items-center gap-2 px-6 py-4 bg-[#f7f7f6] border-t border-[#efefed]">
          <span className="font-semibold text-[#727782] text-xs">
            Internal Access
          </span>
        </div>
      </div>

      <p className="text-[#9ca3af] text-xs mt-6 text-center">
        Sistem internal Kopegtel · © SOPP. All rights reserved.
      </p>
    </div>
  );
}