import { useState, useCallback } from "react";
import {
  getFormSetoran,
  saveDraftSetoran,
  submitSetoran,
  getKalenderSetoran,
} from "@/api/teller";

export default function useInputSetoran() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const fetchForm = useCallback(async (params = {}) => {
    setLoading(true);
    setError(null);
    try {
      const res = await getFormSetoran(params);
      if (res?.status === "ok") setData(res.data);
      else setError(new Error(res?.message || "Unknown response"));
      return res.data;
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const saveDraft = useCallback(async (formData) => {
    setLoading(true);
    try {
      const res = await saveDraftSetoran(formData);
      if (res?.status === "ok") return res.data;
      throw new Error(res?.message || "Save draft failed");
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const submit = useCallback(async (formData) => {
    setLoading(true);
    try {
      const res = await submitSetoran(formData);
      if (res?.status === "ok") return res.data;
      throw new Error(res?.message || "Submit failed");
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchKalender = useCallback(async (params) => {
    try {
      const res = await getKalenderSetoran(params);
      if (res?.status === "ok") return res.data;
      throw new Error(res?.message || "Fetch kalender failed");
    } catch (err) {
      setError(err);
      throw err;
    }
  }, []);

  return {
    data,
    loading,
    error,
    fetchForm,
    saveDraft,
    submit,
    fetchKalender,
  };
}