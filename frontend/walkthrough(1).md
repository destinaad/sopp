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

---

## Modul E — Card B (Pelunasan Setoran Lama)

Implementasi perbaikan pada **Modul E — Card B (Pelunasan Setoran Lama)** sesuai spesifikasi API contract terbaru (`POST /api/v1/teller/setoran/pelunasan` §3.1).

### Ringkasan Perubahan per File (Modul E)

#### 1. [`src/components/shared/DetailPelunasan.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/DetailPelunasan.jsx)
- **Penambahan Pilihan Metode `KPay`**: Menambahkan `<option value="KPay">KPay (Potong Saldo)</option>` pada dropdown pilihan metode.
- **Conditional Form Rendering**:
  - Saat `metode === 'KPay'`: Merender input `Nama Karyawan` (wajib ✱) dan `NIK / Identitas Karyawan` (wajib ✱). Area upload bukti digantikan dengan panel informasi bahwa file bukti tidak diperlukan (prohibited) untuk metode KPay.
  - Saat `metode !== 'KPay'` (`Transfer Bank`, `Tunai`, `QRIS`): Merender area upload bukti drag & drop / klik dengan penanda wajib `*`. Field nama & identitas karyawan disembunyikan.
- **Props Baru**: Menerima `namaKaryawan`, `onNamaKaryawanChange`, `identitasKaryawan`, dan `onIdentitasKaryawanChange`.

#### 2. [`src/components/shared/PelunasanContent.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/PelunasanContent.jsx)
- **State Management Karyawan**: Menambahkan state `namaKaryawan` dan `identitasKaryawan`.
- **Validasi Client-side Menyeluruh (`validasi`)**:
  - Untuk metode `KPay`: Memastikan `namaKaryawan.trim()` dan `identitasKaryawan.trim()` wajib terisi.
  - Untuk non-`KPay`: Memastikan file `buktiFile` wajib dilampirkan sebelum submit diizinkan.
- **Penyesuaian Payload FormData & Pemetaan Nilai API**:
  - Pemetaan eksplisit:
    - `"Transfer Bank"` → `"transfer"`
    - `"Tunai"` → `"tunai"`
    - `"QRIS"` → `"qris"`
    - `"KPay"` → `"kpay"` (memperbaiki bug kode lama yang fallthrough ke `"qris"`).
  - Untuk `kpay`: Menambahkan `metode_pembayaran[0][nama_karyawan]` dan `metode_pembayaran[0][identitas_karyawan]`. **Tidak** menyertakan field `bukti` (menghindari error 422 `prohibited field`).
  - Untuk non-`kpay`: Menambahkan `metode_pembayaran[0][bukti]` jika file dilampirkan. **Tidak** menyertakan field karyawan (menghindari error 422 `prohibited field`).
- **State Reset saat Switch Metode**:
  - Mengosongkan `buktiFile`, `namaKaryawan`, dan `identitasKaryawan` saat teller beralih metode pembayaran untuk mencegah data lama ikut terkirim secara tidak sengaja.
- **Tampilan Read-only Pasca Submit**:
  - Menampilkan ringkasan `Nama Karyawan` dan `NIK / Identitas` jika metode pelunasan adalah KPay.
  - Menampilkan nama file bukti jika metode pelunasan non-KPay.

#### 3. [`src/components/shared/RingkasanPelunasan.jsx`](file:///d:/projects/sopp-setoran/frontend/src/components/shared/RingkasanPelunasan.jsx)
- **Pemeriksaan Kompatibilitas**: Komponen ini merupakan visualisasi murni perbandingan sisa selisih vs jumlah yang dilunasi (`sisaSelisihAwal`, `jumlahPelunasan`). Komponen ini sepenuhnya kompatibel tanpa perlu modifikasi.

---

## Modul F — Dashboard Admin

Implementasi perbaikan pada **Modul F — Dashboard Admin** menangani penghapusan mock data yang memicu `ReferenceError`, pengaktifan endpoint riil `/api/v1/admin/dashboard`, adaptasi payload `items` & skema ringkasan snake_case, penanganan multi-teller per loket dengan navigasi `setoran_id` yang benar, serta banner penanganan kendala koneksi bridge SOPP (`bridge_gagal`).

### Ringkasan Perubahan per File (Modul F)

#### 1. [`src/api/admin.js`](file:///d:/projects/sopp-setoran/frontend/src/api/admin.js)
- **Hapus Mock & ReferenceError**: Menghapus `loketStatusList`, `getRingkasanDashboardAdmin()`, dan `totalLoketTerdaftar` yang tidak pernah diimpor/didefinisikan.
- **Aktifkan Endpoint Riil**: Menggunakan request `api.get("/api/v1/admin/dashboard", { params: { tanggal, refresh: refresh ? "true" : undefined } })`.
- **Parameter `refresh`**: Mendukung boolean `refresh` yang dikonversi menjadi query param `"true"` jika aktif, dan me-return `res.data`.

#### 2. [`src/hooks/useDashboardAdmin.js`](file:///d:/projects/sopp-setoran/frontend/src/hooks/useDashboardAdmin.js)
- **Dukungan Parameter Refresh**: Menyesuaikan `fetch` callback agar menerima `refreshBool` (`false` saat initial render / perubahan tanggal, `true` saat fungsi `refresh()` dipanggil).

#### 3. [`src/pages/admin/Dashboard.jsx`](file:///d:/projects/sopp-setoran/frontend/src/pages/admin/Dashboard.jsx)
- **Sumber Data Daftar Loket**: Menggunakan `data?.items ?? []` menggantikan `data?.loket_status`.
- **Ringkasan Snake-Case**: Membaca `ringkasan.total_loket`, `ringkasan.sudah_submit`, `ringkasan.belum_setor`, dan `ringkasan.ada_selisih`.
- **Banner Peringatan Bridge Gagal**: Menampilkan alert amber informatif jika `data?.bridge_gagal === true`.
- **Pemetaan Kolom Tabel Sesuai Kontrak API**:
  - Nama Loket: `row.nama_loket`
  - Teller: Gabungan nama teller dari array `row.teller`
  - Total Pendapatan: `row.total_pendapatan_sopp` (dengan penanganan fallback `null` jika bridge gagal)
  - Total Setor: `row.total_setor`
  - Selisih: `row.selisih`
  - Status Workflow: `row.status`
- **Navigasi Detail Setoran Akurat**:
  - Menggunakan `dt.setoran_id` dari array `row.detail_teller`, bukan `row.id` atau `loket_id`.
  - Mendukung multi-teller: jika satu loket memiliki lebih dari satu teller, tombol detail merender entri spesifik per teller (`Lihat setoran [Nama Teller]`).
  - Menampilkan status teks informatif (*"Belum setor"*) jika teller pada loket belum memiliki entri setoran (`setoran_id` null).

---

## Modul G — Hak Akses

Implementasi perbaikan pada **Modul G — Hak Akses** memastikan pemenuhan API contract `GET /api/v1/admin/hak-akses` dan `PUT /api/v1/admin/hak-akses` §2: whitelist 5 string snake_case untuk field `tipe`, peniadaan total kolom/elemen `role` dan `aktif`, serta integrasi paginasi berbasis query parameter API (`page`).

### Ringkasan Perubahan per File (Modul G)

#### 1. [`src/mocks/hakAkses.js`](file:///d:/projects/sopp-setoran/frontend/src/mocks/hakAkses.js)
- **Whitelist Snake-Case Permission**: Mengubah array `PERM_COLS` menjadi key snake_case:
  - `bisa_akses_laporan`
  - `bisa_atur_hak_akses`
  - `bisa_lihat_log`
  - `bisa_input_setoran_semua_loket`
  - `bisa_edit_setoran_semua_loket`
- **Pembersihan Field Obsolete**: Menghapus field `role` dan `aktif` dari `penggunaDummy` serta mengadopsi key permission snake_case pada seluruh objek dummy.

#### 2. [`src/pages/admin/HakAkses.jsx`](file:///d:/projects/sopp-setoran/frontend/src/pages/admin/HakAkses.jsx)
- **Payload `tipe` Snake-Case**: Seluruh toggle permission mencatat perubahan dengan key snake_case yang di-whitelist backend.
- **Peniadaan Elemen `role` dan `aktif`**:
  - Menghapus komponen `StatusAktifBadge`.
  - Menghapus kolom header dan baris untuk "Aktif" dan "Role".
  - Menghapus rendering perbedaan label role/aktif pada `ModalKonfirmasi`.
  - Memperbarui total hitungan kolom tabel (`4 + PERM_COLS.length`).
- **Paginasi Berbasis API**:
  - Menghapus pemotongan client-side lokal (`slice((page - 1) * PAGE_SIZE)`).
  - Mengimplementasikan `muatData(targetPage)` yang memanggil `getHakAkses({ page: targetPage })`.
  - Navigasi tombol Previous (`page - 1`) dan Next (`page + 1`) memicu pengambilan data langsung dari API.
  - Membaca `meta.total_pages`, `meta.total_items`, `meta.per_page` dari API response.
- **Penanganan Error & Validasi**:
  - Mengoreksi typo `Object.key` menjadi `Object.keys` pada penanganan respons error 422.

#### 3. [`src/api/admin.js`](file:///d:/projects/sopp-setoran/frontend/src/api/admin.js)
- **Parameter `page` pada `getHakAkses`**: `getHakAkses(params = {})` meneruskan parameter query `{ params }` (termasuk `page`) ke `GET /api/v1/admin/hak-akses`.
- **Standarisasi Body `simpanHakAkses`**: Memastikan payload dikirimkan sebagai objek `{ perubahan: [...] }` ke `PUT /api/v1/admin/hak-akses`.

---

## Modul H — Log Aktivitas

Implementasi perbaikan pada **Modul H — Log Aktivitas** sesuai API contract `GET /api/v1/admin/log-aktivitas` §2 & §7: menghapus mock `logsDummy`, mengaktifkan real endpoint Axios `/api/v1/admin/log-aktivitas`, menerapkan 19 enum `aksi` resmi huruf kecil (snake_case), parsing field waktu ISO 8601 (`log.waktu`), penyelarasan role badge (`superadmin`, `verifikator`, `teller`), serta penambahan filter rentang `tanggal_mulai` dan `tanggal_akhir`.

### Ringkasan Perubahan per File (Modul H)

#### 1. [`src/api/admin.js`](file:///d:/projects/sopp-setoran/frontend/src/api/admin.js)
- **Hapus Mock & ReferenceError**: Menghapus `logsDummy` dan logika filter tiruan di front-end.
- **Aktifkan Endpoint Riil**: Menggunakan `api.get("/api/v1/admin/log-aktivitas", { params })` dan mengembalikan `res.data`.

#### 2. [`src/pages/admin/LogAktivitas.jsx`](file:///d:/projects/sopp-setoran/frontend/src/pages/admin/LogAktivitas.jsx)
- **19 Enum Aksi Resmi (Snake-case)**: Mengganti enum uppercase lama dengan 19 enum valid:
  - `login_manual`, `login_gagal`, `logout_manual`, `buat_setoran`, `edit_setoran`, `hapus_setoran`, `submit_setoran`, `setoran_dihapus`, `buat_pelunasan`, `riwayat_lintas_loket_dilihat`, `riwayat_lintas_loket_diekspor`, `auto_provisioning`, `mutasi_loket_terdeteksi`, `vendor_baru_terdeteksi`, `ubah_kapabilitas`, `atur_password`, `laporan_dilihat`, `laporan_diekspor`, `laporan_diunduh`.
- **Parsing Kolom Waktu ISO 8601**: Menggunakan helper `formatTimestamp(log.waktu)` yang memecah string ISO 8601 menjadi tanggal (`09 Jul 2026`) dan jam (`14.32 WIB`). Menghapus pembacaan `log.tanggal` dan `log.jam`.
- **Penyelarasan Role Badge**: Mengubah opsi role menjadi `superadmin`, `verifikator`, dan `teller` (menghapus role `keuangan` / `admin` lama).
- **Filter Tanggal Rentang**: Menambahkan input `Tanggal Mulai` (`tanggal_mulai`) dan `Tanggal Akhir` (`tanggal_akhir`) pada filter bar, dikirim sebagai query parameter ke API saat tombol Terapkan diklik.
- **Pembersihan Parameter API**: Mengirim query params bersih (`page`, `per_page`, `user`, `aksi`, `tanggal_mulai`, `tanggal_akhir`) ke backend.

---

## Modul I — Laporan SOPP

Implementasi perbaikan pada **Modul I — Laporan SOPP** menangani perbaikan syntax error & ReferenceError pada hook `useLaporan`, penghapusan duplikasi fungsi `downloadLaporanLoket`, integrasi daftar loket dinamis via API `getLokets()`, render kolom vendor secara dinamis berdasarkan `data.vendors`, dan penggunaan nilai bawaan `data.total_row` dari API untuk footer tabel.

### Ringkasan Perubahan per File (Modul I)

#### 1. [`src/hooks/useLaporan.js`](file:///d:/projects/sopp-setoran/frontend/src/hooks/useLaporan.js)
- **Koreksi Typo `newError`**: Mengubah `newError(...)` menjadi `new Error(...)` untuk mencegah `ReferenceError` saat menerima respons non-ok dari server.
- **Koreksi Syntax Error**: Mengubah sintaks objek yang keliru `fetch([ refresh: true ])` menjadi objek valid `fetch({ refresh: true })` pada callback `refreshPaksa`.

#### 2. [`src/api/admin.js`](file:///d:/projects/sopp-setoran/frontend/src/api/admin.js)
- **Penghapusan Duplikasi `downloadLaporanLoket`**: Menghapus deklarasi ganda `downloadLaporanLoket` yang sebelumnya ada di bagian bawah file (dengan endpoint usang tanpa `/v1`).
- **Endpoint Download Resmi**: Memastikan hanya ada satu definisi `downloadLaporanLoket` yang menggunakan endpoint `/api/v1/admin/laporan/download` dengan `responseType: "blob"`.

#### 3. [`src/pages/shared/Laporan.jsx`](file:///d:/projects/sopp-setoran/frontend/src/pages/shared/Laporan.jsx)
- **Pengambilan Loket Dinamis**: Menghapus impor `DAFTAR_LOKET` dari mock statis `src/mocks/laporanMocks.js`. Mengambil daftar loket secara dinamis dari endpoint riil menggunakan `getLokets()` dari `src/api/teller.js` (`GET /api/v1/lokets`), dan merender options dropdown loket berdasarkan ID dan nama loket riil (`l.id ?? l.loket_id` dan `l.nama ?? l.nama_loket`).
- **Render Kolom Vendor Dinamis**:
  - Mengambil array vendor dari `data.vendors` (dengan fallback aman jika belum termuat).
  - Menghapus kolom vendor hardcoded (`finnet`, `puskud`, `jne`, `feeLain`).
  - Merender header dan sub-header `TRANSAKSI (Rp)` pada `TabelBentukA`, `TabelBentukB`, dan `TabelBentukC` dengan `colSpan={vendors.length}` dan loop `{vendors.map((v) => <TH key={v}>{v}</TH>)}`.
  - Merender cell nilai per vendor secara dinamis: `{vendors.map((v) => <TD key={v} right ...>{formatRupiah(row.vendors?.[v] ?? row[v])}</TD>)}`.
- **Penggunaan `data.total_row` untuk Footer Tabel**:
  - Memperbarui komponen `TotalRow` agar menerima prop `vendors`, `totalRow = data?.total_row`, dan `rows`.
  - Menampilkan total vendor langsung dari `totalRow.vendors[v]` (server-calculated), total pendapatan dari `totalRow.total_pendapatan_sopp`, metode setoran dari `totalRow.metode_setoran`, jumlah setor dari `totalRow.total_setor`, dan selisih dari `totalRow.selisih`.
  - Mengurangi / meniadakan ketergantungan penghitungan finansial manual di client-side.
