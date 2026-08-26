import { useState, useEffect, useCallback } from "react";
import { getDashboardAdmin } from "@/api/admin";

/**
 * Hook Dashboard Admin/Verifikator.
 *
 * Menerima `tanggal` (opsional, format "YYYY-MM-DD") supaya data yang
 * diambil mengikuti tanggal yang dipilih user di halaman — diteruskan
 * langsung ke getDashboardAdmin(tanggal) (api/admin.js), yang juga sudah
 * menerima parameter ini.
 *
 * `tanggal` masuk dependency array useCallback/useEffect: setiap kali
 * halaman mengirim `tanggal` baru (misal lewat re-render dengan prop beda),
 * hook otomatis fetch ulang. Untuk trigger fetch ulang dari tombol "Muat
 * Data" tanpa ganti tanggal, tetap pakai refresh() seperti biasa.
 */
export default function useDashboardAdmin(tanggal) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await getDashboardAdmin(tanggal);
      if (res?.status === "ok") setData(res.data);
      else setError(new Error(res?.message || "Unknown response"));
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [tanggal]);

  useEffect(() => {
    fetch();
  }, [fetch]);

  return { data, loading, error, refresh: fetch };
}