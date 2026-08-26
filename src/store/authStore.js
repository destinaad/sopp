import { create } from "zustand";

/**
 * Global store untuk data user yang sedang login.
 * Mendukung role: "teller" | "verifikator" | "admin"
 */
export const useAuthStore = create((set) => ({
  user: {
    name: "Budi Santoso",
    role: "admin", // ganti ke "verifikator" atau "admin" untuk test role lain
    loket: "SOPP Blimbing",
  },
  setUser: (user) => set({ user }),
  logout: () => set({ user: null }),
}));