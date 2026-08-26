import { useState, useEffect, useCallback } from "react";
import { getLaporan } from "@/api/admin";

export default function useLaporan(initialFilters = {}) {
  const [data, setData] = useState({ items: [], meta: null });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [filters, setFilters] = useState(initialFilters);

  const fetch = useCallback(
    async (override = {}) => {
      setLoading(true);
      setError(null);
      try {
        const merged = { ...filters, ...override };
        const res = await getLaporan(merged);
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

  return { data, loading, error, refresh: fetch, setFilters };
}
