export function buildSetoranFormData(rincian, opsi = {}) {
  const formData = new FormData();

  if (opsi.loketId) formData.append("loket_id", opsi.loketId);
  if (opsi.tanggal) formData.append("tanggal", opsi.tanggal);

  const semuaBaris = [
    ...rincian.transfer.map((r) => ({ ...r, metode_pembayaran: "transfer" })),
    ...rincian.qris.map((r) => ({ ...r, metode_pembayaran: "qris" })),
    ...rincian.tunai.map((r) => ({ ...r, metode_pembayaran: "tunai" })),
    ...rincian.kpay.map((r) => ({ ...r, metode_pembayaran: "kpay" })),
  ];

  const barisTerisi = semuaBaris.filter((r) => {
    if (r.metode_pembayaran === "kpay") {
      return r.nominal || r.nama || r.nik;
    }
    return r.nominal || r.bukti;
  });

  let index = 0;
  for (const baris of barisTerisi) {
    const prefix = `entries[${index}]`;

    if (baris.entryId) {
      formData.append(`${prefix}[id]`, baris.entryId);
    }

    formData.append(`${prefix}[metode_pembayaran]`, baris.metode_pembayaran);
    formData.append(`${prefix}[nominal]`, baris.nominal || 0);

    if (baris.keterangan) {
      formData.append(`${prefix}[keterangan]`, baris.keterangan);
    }

    formData.append(`${prefix}[is_konfirmasi]`, baris.verified ? "1" : "0");

    if (baris.metode_pembayaran !== "kpay" && baris.bukti instanceof File) {
      formData.append(`${prefix}[foto_bukti]`, baris.bukti);
    }

    if (baris.metode_pembayaran === "kpay") {
      formData.append(`${prefix}[nama_karyawan]`, baris.nama || "");
      formData.append(`${prefix}[identitas_karyawan]`, baris.nik || "");
    }

    index += 1;
  }

  if (barisTerisi.length === 0) {
    formData.append("entries", "[]");
  }

  return formData;
}