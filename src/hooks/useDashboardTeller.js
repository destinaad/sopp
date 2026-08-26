import { useState, useEffect, useCallback } from "react";
import { getDashboard } from "@/api/teller";

export default function useDashboardTeller() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const fetch = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await getDashboard();
      if (res?.status === "ok") setData(res.data);
      else setError(new Error(res?.message || "Unknown response"));
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetch();
  }, [fetch]);

  return { data, loading, error, refresh: fetch };
}
