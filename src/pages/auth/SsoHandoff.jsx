import { useEffect, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useAuthStore } from "@/store/authStore";
import { verifySSO } from "@/api/auth";
import { getCsrfCookie } from "@/lib/axios";
import { IconSpinner, IconShieldCheck } from "@/components/ui/Icon";

/**
 * Halaman SSO Handoff — pintu masuk untuk Teller & Bu Wiwik (verifikator),
 * datang dari sistem SOPP lama lewat link berisi token, BUKAN dari form
 * Login manual (itu khusus Super Admin, lihat pages/auth/Login.jsx).
 *
 * Route (di App.jsx), publik (tanpa Navbar/Sidebar, sama seperti /login).
 * Path WAJIB persis "/setoran/masuk" (bukan nama bebas), sesuai kontrak
 * API §6.1 poin 2: "Browser redirect ke React: https://[domain-react]/setoran/masuk?token=xxx"
 *   <Route path="/setoran/masuk" element={<SsoHandoff />} />
 *
 * Alur (kontrak API §6.1, WAJIB urutan ini):
 *  1. Baca token dari URL (?token=xxxxx)
 *  2. getCsrfCookie() dulu (GET /sanctum/csrf-cookie) — WAJIB sebelum
 *     langkah 3, supaya cookie sesi bisa ter-set dengan benar.
 *  3. Kirim token ke verifySSO(token) — di baliknya nanti POST
 *     /api/sso/verify dengan token di BODY request (BUKAN GET, token
 *     TIDAK PERNAH dikirim sebagai parameter URL ke server — cuma dibaca
 *     dari URL React-nya sendiri di langkah 1).
 *  4. Kalau valid: TERJEMAHKAN bentuk data API (nama, loket_id,
 *     is_superadmin, bisa_*) jadi bentuk yang dipahami seluruh komponen
 *     aplikasi ({name, role, loket}) — lihat mapUserDariApi() di bawah.
 *     Simpan ke authStore, lalu navigate ke dashboard sesuai role dengan
 *     {replace: true} (supaya /setoran/masuk?token=... tidak nyangkut di
 *     riwayat browser).
 *  5. Kalau gagal/token tidak ada: tampilkan pesan error + tombol ke
 *     halaman Login manual (buat jaga-jaga kalau memang bukan lewat SSO).
 */

/**
 * Terjemahkan field mentah dari API (snake_case, is_superadmin +
 * beberapa bisa_*) jadi bentuk {name, role, loket} yang dipakai
 * authStore & seluruh komponen (Sidebar, Dashboard, dll).
 *
 * Aturan penentuan role (API tidak mengirim field "role" langsung).
 * CATATAN PENTING (kontrak API §6.2): backend SUDAH meng-OR-kan
 * bisa_akses_laporan / bisa_atur_hak_akses / bisa_lihat_log dengan
 * is_superadmin sebelum dikirim — jadi kalau is_superadmin true, 3 field
 * itu OTOMATIS true juga dari backend (bukan FE yang perlu OR ulang).
 * Dua field terakhir (bisa_input_setoran_semua_loket,
 * bisa_edit_setoran_semua_loket) TIDAK di-OR — bisa saja false walau
 * is_superadmin true, kalau memang belum di-grant lewat Hak Akses.
 *  - is_superadmin true            -> "admin"
 *  - salah satu kapabilitas admin  -> "verifikator" (Bu Wiwik)
 *    (bisa_akses_laporan / bisa_atur_hak_akses / bisa_lihat_log /
 *     bisa_input_setoran_semua_loket / bisa_edit_setoran_semua_loket)
 *  - selain itu (tidak ada kapabilitas admin sama sekali) -> "teller"
 */
function mapUserDariApi(apiUser) {
  const adaKapabilitasAdmin =
    apiUser.bisa_akses_laporan ||
    apiUser.bisa_atur_hak_akses ||
    apiUser.bisa_lihat_log ||
    apiUser.bisa_input_setoran_semua_loket ||
    apiUser.bisa_edit_setoran_semua_loket;

  let role = "teller";
  if (apiUser.is_superadmin) role = "admin";
  else if (adaKapabilitasAdmin) role = "verifikator";

  return {
    name: apiUser.nama,
    role,
    // loket_id dari API itu angka ID, bukan nama loket yang siap tampil.
    // Sementara belum ada daftar mapping id -> nama loket dari backend,
    // tampilkan apa adanya sebagai fallback supaya tidak kosong total.
    // TODO: ganti jadi nama loket asli begitu backend sediakan datanya
    // (mis. ikut dikirim di response verifySSO, atau lookup terpisah).
    loket: apiUser.loket_id ? `Loket #${apiUser.loket_id}` : "-",
  };
}

// Dashboard tujuan redirect, berbeda per role (samakan dengan App.jsx)
const DASHBOARD_PER_ROLE = {
  teller: "/teller/dashboard",
  verifikator: "/verifikator/dashboard",
  admin: "/admin/dashboard",
};

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