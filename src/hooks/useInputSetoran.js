import { useState, useCallback } from "react";
import {
  getSetoranHariIni,
  createSetoranEntry,
  updateSetoranEntry,
  deleteSetoranEntry,
  saveDraftSetoran,
  submitSetoran,
  getSisaPelunasan,
  createPelunasan,
} from "@/api/teller";

export default function useInputSetoran() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const fetchHariIni = useCallback(async (options = {}) => {
    setLoading(true);
    setError(null);
    try {
      const res = await getSetoranHariIni(options);
      if (res?.status === "ok") setData(res.data);
      else setError(new Error(res?.message || "Unknown response"));
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, []);

  const addEntry = useCallback(async (entry) => {
    setLoading(true);
    try {
      const res = await createSetoranEntry(entry);
      if (res?.status === "ok") {
        setData((prev) => ({ ...prev, pendapatan: prev?.pendapatan ? [...prev.pendapatan, res.data] : [res.data] }));
        return res.data;
      }
      throw new Error(res?.message || "Create failed");
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const editEntry = useCallback(async (id, updates) => {
    setLoading(true);
    try {
      const res = await updateSetoranEntry(id, updates);
      if (res?.status === "ok") {
        setData((prev) => ({
          ...prev,
          pendapatan: prev?.pendapatan?.map((p) => (p.id === id ? { ...p, ...res.data } : p)),
        }));
        return res.data;
      }
      throw new Error(res?.message || "Update failed");
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const removeEntry = useCallback(async (id) => {
    setLoading(true);
    try {
      const res = await deleteSetoranEntry(id);
      if (res?.status === "ok") {
        setData((prev) => ({ ...prev, pendapatan: prev?.pendapatan?.filter((p) => p.id !== id) }));
        return res.data;
      }
      throw new Error(res?.message || "Delete failed");
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const saveDraft = useCallback(async (payload) => {
    setLoading(true);
    try {
      const res = await saveDraftSetoran(payload);
      if (res?.status === "ok") return res.data;
      throw new Error(res?.message || "Save draft failed");
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const submit = useCallback(async (payload) => {
    setLoading(true);
    try {
      const res = await submitSetoran(payload);
      if (res?.status === "ok") return res.data;
      throw new Error(res?.message || "Submit failed");
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchSisaPelunasan = useCallback(async (tanggal) => {
    setLoading(true);
    try {
      const res = await getSisaPelunasan(tanggal);
      if (res?.status === "ok") return res.data;
      throw new Error(res?.message || "Fetch sisa pelunasan failed");
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const pelunasan = useCallback(async (tanggal, nominal) => {
    setLoading(true);
    try {
      const res = await createPelunasan(tanggal, nominal);
      if (res?.status === "ok") return res.data;
      throw new Error(res?.message || "Pelunasan failed");
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  return {
    data,
    loading,
    error,
    fetchHariIni,
    addEntry,
    editEntry,
    removeEntry,
    saveDraft,
    submit,
    fetchSisaPelunasan,
    pelunasan,
  };
}
