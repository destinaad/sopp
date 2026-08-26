import { useState, useEffect, useCallback } from "react";
import { getRiwayat } from "@/api/teller";

export default function useRiwayatSetoran(params = {}) {
  const [data, setData] = useState({ items: [], meta: null, ringkasan: {} });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [filters, setFilters] = useState(params);

  const fetch = useCallback(
    async (override = {}) => {
      setLoading(true);
      setError(null);
      try {
        const merged = { ...filters, ...override };
        const res = await getRiwayat(merged);
        if (res?.status === "ok") setData(res.data);
        else setError(new Error(res?.message || "Unknown response"));
      } catch (err) {
        setError(err);
      } finally {
        setLoading(false);
      }
    },
    [filters]
  );

  useEffect(() => {
    fetch();
  }, [fetch]);

  return {
    data,
    loading,
    error,
    refresh: fetch,
    setFilters,
  };
}
