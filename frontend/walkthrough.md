# Walkthrough: Modul A, Modul B, Modul C & Modul D

---

## Modul C — Riwayat Setoran & Export

Implementasi perbaikan pada **Modul C — Riwayat Setoran & Export** menangani validasi enum status keuangan, perbaikan download Blob file Excel (.xlsx), pengembalian parameter filter export dari hook, dan penyesuaian properti ringkasan sesuai API contract (`GET /api/v1/teller/setoran/riwayat` & `GET /api/v1/teller/setoran/riwayat/export`).

### Ringkasan Perubahan per File

#### 1. [`src/components/shared/FilterBar.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/FilterBar.jsx)
* **Koreksi Enum `status_keuangan`**: Mengubah nilai `<option value="belum-lunas">` menjadi `<option value="belum_lunas">` (menggunakan underscore, bukan tanda hubung).
* **Penambahan Opsi `gagal_dimuat`**: Menambahkan `<option value="gagal_dimuat">Gagal Dimuat</option>` sesuai spesifikasi API contract agar teller dapat memfilter data dengan kendala koneksi bridge.

#### 2. [`src/hooks/useRiwayatSetoran.js`](file:///d:/projects/sopp-setoran/frontend/src/hooks/useRiwayatSetoran.js)
* **Pengembalian `filtersUntukExport`**: Mengembalikan objek `filtersUntukExport` pada return hook `{ data, loading, error, refresh, setFilters, filtersUntukExport }`.
* **Struktur Parameter Export**: Berisi `{ tanggal_mulai, tanggal_akhir, status_keuangan, status_workflow }` tanpa pagination `page`, memastikan proses export mengunduh seluruh rentang data yang difilter.

#### 3. [`src/pages/teller/RiwayatSetoran.jsx`](file:///d:/projects/sopp-setoran/frontend/src/pages/teller/RiwayatSetoran.jsx)
* **Perbaikan Konstruksi Blob Excel**: Mengubah pembuatan Blob dari `new Blob([res])` menjadi:
  ```javascript
  new Blob([res.data], {
    type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  })
  ```
  Ini mencegah penyelubungan objek metadata Axios (`res`) ke dalam file biner, sehingga file spreadsheet `.xlsx` yang diunduh valid dan dapat dibuka normal di Microsoft Excel / spreadsheet viewer.

#### 4. [`src/components/shared/RiwayatSummaryCards.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/RiwayatSummaryCards.jsx)
* **Dukungan Format Snake-case & Camel-case**: Menyelaraskan pembacaan properti objek `ringkasan` dari API terbaru:
  * `totalLunas`: `total_setoran_lunas ?? totalTerverifikasi ?? 0`
  * `persen`: `persentase_vs_bulan_lalu ?? persenVsBulanLalu ?? 0`
  * `pending`: `pending_selisih ?? pendingSelisih ?? 0`
  * `totalEntry`: `total_entry_bulan_ini ?? totalEntryBulanIni ?? 0`
  * `rataRata`: `rata_rata_entry_per_hari ?? rataRataEntryPerHari ?? 0`
* Nilai pada kartu ringkasan tidak lagi bernilai Rp 0 atau undefined saat menerima response API terbaru.

---

## Hasil Pengujian & Verifikasi Modul C

Dijalankan 19 pengujian unit otomatis untuk Modul C:
1. **Ekstraksi Properti Ringkasan API**:
   * `total_setoran_lunas` berhasil terbaca ✅
   * `persentase_vs_bulan_lalu` berhasil terbaca ✅
   * `pending_selisih` berhasil terbaca ✅
   * `total_entry_bulan_ini` dan `rata_rata_entry_per_hari` berhasil terbaca ✅
   * Fallback aman ke format legacy camelCase maupun default 0 saat ringkasan kosong ✅
2. **Filter & Parameter Export**:
   * `filtersUntukExport` mengembalikan format underscore `belum_lunas` tanpa menyertakan `page` ✅
3. **Konstruksi Blob Spreadsheet**:
   * MIME type terkonfirmasi `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` dengan ukuran byte biner yang akurat ✅

---

## Modul B — Dashboard Teller

Implementasi perbaikan pada **Modul B — Dashboard Teller** mencakup penyelarasan nama properti antara pemetaan data di halaman dan komponen presentasional sesuai API contract (`GET /api/v1/teller/dashboard`).

### Ringkasan Perubahan per File (Modul B)
1. **[`src/components/shared/ActivityTable.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/ActivityTable.jsx)**: `status={row.status ?? row.statusWorkflow}` agar selaras dengan mapping `Dashboard.jsx`.
2. **[`src/components/shared/SummaryCards.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/SummaryCards.jsx)**: Koreksi typo `summary.dataTerknisi` menjadi `summary.dataTerkini`.
3. **[`src/components/shared/StatusBadge.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/StatusBadge.jsx)**: Dikonfirmasi menangani seluruh status workflow dan keuangan.

---

## Modul A — Otentikasi, Otorisasi, & Sesi

Implementasi perubahan Modul A telah selesai sesuai hasil audit dan API contract terbaru (`POST /api/v1/auth/login` & `GET /api/v1/me`).

### Ringkasan Perubahan per File (Modul A)
1. **[`src/utils/mapUser.js`](file:///d:/projects/sopp-setoran/frontend/src/utils/mapUser.js)**: Menyimpan 6 kapabilitas boolean mentah, menghapus kalkulasi role kaku, menambahkan helper `getDefaultDashboard(user)`.
2. **[`src/store/authStore.js`](file:///d:/projects/sopp-setoran/frontend/src/store/authStore.js)**: Inisialisasi awal default menjadi `user: null`.
3. **[`src/App.jsx`](file:///d:/projects/sopp-setoran/frontend/src/App.jsx)**: Memperbarui `RoleGuard` dengan `requiredCapability`.
4. **[`src/components/layout/Sidebar.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/layout/Sidebar.jsx)**: Filter navigasi dinamis berbasis kapabilitas boolean mentah.
5. **[`src/pages/auth/Login.jsx`](file:///d:/projects/sopp-setoran/frontend/src/pages/auth/Login.jsx)**, **[`src/pages/auth/SsoHandoff.jsx`](file:///d:/projects/sopp-setoran/frontend/src/pages/auth/SsoHandoff.jsx)**, **[`src/pages/shared/NotFound.jsx`](file:///d:/projects/sopp-setoran/frontend/src/pages/shared/NotFound.jsx)**: Menggunakan `getDefaultDashboard(user)`.
6. **[`src/components/layout/Navbar.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/layout/Navbar.jsx)**: Kompatibel tanpa modifikasi.

---

## Modul D — Detail Setoran

Implementasi perbaikan pada **Modul D — Detail Setoran** sesuai API contract revisi (`GET /api/v1/teller/setoran/{id}` & `PUT /api/v1/teller/setoran/{id}`), mencakup dua item audit:

### Ringkasan Perubahan per File (Modul D)

#### 1. [`src/components/shared/RingkasanSetoranCard.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/RingkasanSetoranCard.jsx)
- **Hapus kalkulasi selisih lokal**: Sebelumnya komponen menghitung `selisih = totalPendapatan - totalSetoran` sendiri. Sekarang menerima `selisihSendiri` langsung dari API (personal, live-fetch ke bridge SOPP) — FE tidak boleh recalculate logic finansial ini per kontrak §1.
- **Prop baru `selisihSendiri`** — nilai `selisih_sendiri` dari API, bisa `null` saat `gagal_dimuat`.
- **Prop baru `statusKeuangan`** — `'lunas' | 'belum_lunas' | 'gagal_dimuat'`, menentukan tampilan authoritative tanpa re-derive.
- **Handling `gagal_dimuat`**: Saat `status_keuangan === 'gagal_dimuat'`, tampil blok peringatan amber (bukan Rp 0 yang menyesatkan) karena `total_pendapatan` dan `selisih_sendiri` keduanya `null` — fetch ke bridge SOPP gagal atau teller tidak punya identitas legacy (`user_id_sopp` null).

#### 2. [`src/components/shared/DetailSetoranHeader.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/DetailSetoranHeader.jsx)
- **Ganti prop `petugas` dari string ke array**: Sebelumnya menerima `detail.nama_teller` (string tunggal), sekarang menerima `detail.petugas` (array `[{ nama, aksi, waktu }]`) — audit trail penuh sesuai kontrak.
- **Render daftar audit trail**: Menampilkan setiap entri petugas dengan nama, label aksi (Input/Edit/Hapus/Pelunasan), dan waktu dalam format `HH:mm WIB`.
- **Label header adaptif**: Judul berubah dari "Petugas" (1 entri) menjadi "Riwayat Petugas" (>1 entri), dengan ikon berbeda (`IconUser` vs `IconClockHistory`).
- **Backward-compatible**: Jika prop masih berupa string, dibungkus otomatis ke array satu elemen agar tidak crash.

#### 3. [`src/pages/shared/DetailSetoran.jsx`](file:///d:/projects/sopp-setoran/frontend/src/pages/shared/DetailSetoran.jsx)
- **Prop `selisihSendiri={detail.selisih_sendiri}`**: Menggantikan `selisih={detail.selisih}` (field sudah dihapus total dari API per revisi 2 September 2026). `detail.selisih` selalu `undefined` → nilai selisih di UI sebelumnya selalu salah.
- **Prop `petugas={detail.petugas}`**: Menggantikan `petugas={detail.nama_teller}` agar riwayat petugas (audit trail array) diteruskan ke `DetailSetoranHeader`.

---

### Temuan di Luar Scope Modul D (Perlu Perhatian)

> [!WARNING]
> **`src/pages/shared/Laporan.jsx`** (baris 522–541) masih menggunakan `detail.selisih` (field lama yang sudah dihapus dari API). File ini **tidak masuk scope Modul D** dan belum diubah sesuai instruksi — laporkan dulu sebelum mengubah.
