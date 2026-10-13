import { create } from "zustand";

/**
 * Global store untuk data user yang sedang login.
 * Menggunakan model kapabilitas granular berdasarkan API contract terbaru.
 */
export const useAuthStore = create((set) => ({
  user: null,
  setUser: (user) => set({ user }),
  logout: () => set({ user: null }),
}));