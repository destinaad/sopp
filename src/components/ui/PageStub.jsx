/**
 * Placeholder sementara untuk halaman yang belum selesai di-develop,
 * supaya routing tidak error karena import gagal.
 * Nanti tinggal hapus pemakaian ini kalau halaman aslinya sudah jadi.
 */
export default function PageStub({ title }) {
  return (
    <div className="flex flex-col gap-2">
      <h1 className="font-bold text-[#191c20] text-3xl tracking-tight">{title}</h1>
      <p className="text-[#424751] text-base">Halaman ini masih dalam pengembangan.</p>
    </div>
  );
}
