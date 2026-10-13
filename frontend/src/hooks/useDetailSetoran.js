import { useState, useEffect, useCallback } from "react";
import { getDetailSetoran } from "@/api/teller";

export default function useDetailSetoran(id) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const fetch = useCallback(
    async (identifier) => {
      if (!identifier) return;
      setLoading(true);
      setError(null);
      try {
        const res = await getDetailSetoran(identifier);
        if (res?.status === "ok") setData(res.data);
        else setError(new Error(res?.message || "Unknown response"));
      } catch (err) {
        setError(err);
      } finally {
        setLoading(false);
      }
    },
    []
  );

  useEffect(() => {
    if (id) {
      setData(null); // jangan tampilkan detail setoran sebelumnya saat id berganti
      fetch(id);
    }
  }, [id, fetch]);

  return { data, loading, error, refresh: () => fetch(id) };
}