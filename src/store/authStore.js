import { create } from "zustand";

/**
 * Global store untuk data user yang sedang login.
 * Mendukung role: "teller" | "verifikator" | "admin"
 */
export const useAuthStore = create((set) => ({
  user: {
    name: "Budi Santoso",
    role: "teller",
    loket: "SOPP Blimbing",
  },
  setUser: (user)=> set({ user }),
  logout: () => set({ user:null }),
}));