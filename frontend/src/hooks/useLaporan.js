import { useState, useEffect, useCallback } from "react";
import { getLaporan } from "@/api/admin";

// Filter dianggap siap dikirim hanya kalau field wajib per mode sudah terisi.
// Tanpa ini, memilih mode "Rentang" (tanggal masih kosong) langsung memicu
// request 422 + banner error sebelum user sempat mengisi apa pun.
function filterLengkap(f) {
  if (f.mode === "harian") return Boolean(f.tanggal);
  if (f.mode === "bulanan") return Boolean(f.bulan);
  if (f.mode === "rentang") return Boolean(f.tanggal_mulai && f.tanggal_akhir);
  return false;
}

export default function useLaporan(initialFilters = {}) {
  // state awal kosong - bentuk diisi setelah response BE masuk
  // (bisa Bentuk A, B, atau C - shape berbeda)
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [loadingRefresh, setLoadingRefresh] = useState(false);
  const [error, setError] = useState(null);
  const [filters, setFilters] = useState(initialFilters);

  const fetch = useCallback(
    async (override = {}) => {
      if (!filters.mode) return;
      if (!filterLengkap(filters)) {
        // jangan biarkan data/label mode sebelumnya menggantung di layar
        setData(null);
        setError(null);
        return;
      }
      const isRefresh = override.refresh === true;
      if (isRefresh) setLoadingRefresh(true);
      else setLoading(true);
      setError(null);
      try {
        const merged = { ...filters, ...override };
        if (!merged.loket_id) delete merged.teller_id;
        const res = await getLaporan(merged);
        if (res?.status === "ok") setData(res.data);
        else setError(new Error(res?.message || "Unknown response"));
      } catch (err) {
        setError(err);
      } finally {
        setLoading(false);
        setLoadingRefresh(false);
      }
    }, 
    [filters]
  );

  useEffect(() => {
    let aktif = true;
    queueMicrotask(() => {
      if (aktif) fetch();
    });
    return () => { aktif = false; };
  }, [fetch]);

  // refresh() - fetch biasa (pakai cache BE)
  // refreshPaksa() - kirim refresh=true untuk bypass cache bridge
  const refreshPaksa = useCallback(() => {
    fetch({ refresh: true });
  }, [fetch]);

  return {
    data,
    loading,
    loadingRefresh,
    error,
    refresh: fetch,
    refreshPaksa,
    setFilters,
  };
}