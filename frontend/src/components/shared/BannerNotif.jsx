import { useEffect, useRef } from "react";

/**
 * Banner notifikasi di dalam halaman -- pengganti alert() bawaan browser.
 *
 * notif: null | { tipe: "error" | "peringatan", pesan: string }
 *   - "error"      -> merah (aksi ditolak / gagal)
 *   - "peringatan" -> kuning (aksi tidak jalan, tapi user bisa langsung
 *                     mengulang: batas rentang, terlalu cepat, data basi)
 * onTutup: dipanggil saat tombol × diklik; page yang mengosongkan state-nya.
 *
 * Banner bisa muncul jauh dari tombol yang memicunya (mis. tombol Excel di
 * baris tabel paling bawah, banner di atas halaman), jadi digulir ke layar
 * otomatis -- tanpa itu pesannya gampang terlewat, lebih buruk dari alert().
 */
export default function BannerNotif({ notif, onTutup }) {
  const ref = useRef(null);

  useEffect(() => {
    if (notif) ref.current?.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }, [notif]);

  if (!notif) return null;

  const gaya =
    notif.tipe === "peringatan"
      ? "bg-[#faeeda] border-[#854f0b]/30 text-[#854f0b]"
      : "bg-[#ffdad6] border-[#ba1a1a]/30 text-[#93000a]";

  return (
    <div
      ref={ref}
      role="alert"
      className={`scroll-mt-24 border rounded-lg px-4 py-3 flex items-start justify-between gap-3 text-sm ${gaya}`}
    >
      <span>{notif.pesan}</span>
      <button
        onClick={onTutup}
        aria-label="Tutup pemberitahuan"
        className="shrink-0 font-bold text-base leading-none px-1 hover:opacity-70 transition-opacity"
      >
        ×
      </button>
    </div>
  );
}