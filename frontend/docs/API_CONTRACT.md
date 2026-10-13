# All in

[**sopp nyoba.zip**](https://drive.google.com/file/d/1wb9UckFp6EMG7vSec1Prf-E41L5RZ3Ie/view?usp=sharing) 

**Struktur yang mungkin kepake** buat tiap chunk yang FE juga bisa baca (bukan konten-nya, cuma kerangkanya):

* Endpoint \+ method \+ auth requirement  
* Request contract (field wajib, validasi)  
* Response contract per skenario (sukses, gagal tiap jenis, termasuk kode status)  
* Business rule inti dalam bahasa manusia (bukan cuma kode) — misal "selisih dihitung dari gabungan semua teller yang submitted di loket+tanggal yang sama, draft gak ikut, termasuk draft punya sendiri"  
* Known limitations / catatan non-bug (kayak riwayatTerakhir yang gak scope loket\_id)

# Auth

# **Kontrak API — Login Manual & Sesi**

Base path: `/api/v1`. Auth: cookie-session lewat Laravel Sanctum SPA mode (bukan Bearer token) — artinya browser FE otomatis kirim cookie di tiap request, tapi FE **wajib** melakukan handshake CSRF dulu sebelum request pertama yang mengubah state (POST/PUT/PATCH/DELETE).

---

## **0\. Prasyarat wajib: CSRF cookie handshake**

**GET** `/sanctum/csrf-cookie` (catatan: path ini **di luar** prefix `/api/v1`, panggil apa adanya)

* Bukan endpoint bisnis — cuma set cookie `XSRF-TOKEN` di browser.  
* **Wajib dipanggil sekali sebelum request POST/PUT/PATCH/DELETE pertama** ke API ini (termasuk `login`). GET (`/me`) tidak butuh CSRF, tapi tetap butuh cookie sesi valid dari login sebelumnya.  
* Kalau dilewati dan FE langsung `POST /api/v1/auth/login`: request akan gagal dengan **419** (lihat tabel error di bawah).   
* Response sukses: `204 No Content`, tanpa body.

---

## **1\. POST `/api/v1/auth/login`**

### **Mekanis**

* **Method**: POST  
* **Auth**: tidak perlu (justru endpoint buat dapat sesi)  
* **Middleware**: `throttle:5,1` — **rate limit 5 percobaan per menit, dikunci per-IP** (bukan per-akun/identifier), karena request belum authenticated saat throttle dievaluasi. Artinya kalau 2 user beda coba login dari IP publik yang sama (mis. kantor di belakang NAT), mereka **berbagi kuota 5x/menit** yang sama.

**Request body**

{  
  "identifier": "string, required",  
  "password": "string, required"  
}

* `identifier` menampung **dua kemungkinan kolom** di sisi backend (`user_id_sopp` ATAU `username`) — FE tidak perlu tahu/pilih user itu tipe apa, cukup kirim apa yang diketik user, backend yang cari ke dua kolom itu (`WHERE user_id_sopp = ? OR username = ?`). 

**Response sukses — 200**

{  
  "status": "ok",  
  "data": {  
    "user": {  
      "nama": "string",  
      "loket\_id": "number | null",  
      "nama\_loket": "string | null",  
      "is\_superadmin": "boolean",  
      "bisa\_akses\_laporan": "boolean",  
      "bisa\_atur\_hak\_akses": "boolean",  
      "bisa\_lihat\_log": "boolean",  
      "bisa\_input\_setoran\_semua\_loket": "boolean",  
      "bisa\_edit\_setoran\_semua\_loket": "boolean"  
    }  
  }  
}

Bentuk `data.user` ini **identik** dengan response `GET /me` (dijamin oleh test `test_login_sukses_bentuk_response_identik_me`) — FE boleh pakai satu shape/type yang sama buat handle keduanya.

### **Notes bisnis-logic (yang gak keliatan dari tipe data doang)**

1. `nama_loket` **null** itu cuma kejadian di kasus **superadmin** (`loket_id`\-nya emang di-seed `null` dari awal, `Loket::find(null)` → `null`) — bukan soal "belum sinkron".  
2. Kalau `loket_id` **ada isinya** (non-null), `nama_loket` **hampir gak pernah null** — paling jelek dapat string placeholder `"Loket #{id} (belum disinkron)"`, bukan `null`.

3. **Pola kapabilitas boolean itu TIDAK seragam** — ini bagian paling penting buat dipahami FE, jangan disamaratakan:

   * `bisa_akses_laporan`, `bisa_atur_hak_akses`, `bisa_lihat_log` → nilainya `is_superadmin OR kolom_asli`. Artinya **superadmin otomatis dapat true** di 3 field ini walau kolomnya sendiri di DB `false`.  
   * `bisa_input_setoran_semua_loket`, `bisa_edit_setoran_semua_loket` → nilainya **kolom asli apa adanya, TANPA OR sama sekali**. Superadmin **TIDAK otomatis** dapat true di 2 field ini — harus digrant eksplisit lewat fitur Hak Akses (chunk lain). Ini keputusan sengaja: "superadmin gak ada urusannya sama hal keuangan secara default". Kalau FE melihat user `is_superadmin: true` tapi `bisa_input_setoran_semua_loket: false`, itu **bukan bug**, itu memang perilaku yang diharapkan.  
4. **Sesi baru selalu di-regenerate saat login sukses** (`session()->regenerate()`) — mencegah session fixation. Efek buat FE: tidak ada yang perlu dilakukan manual, cookie session yang lama otomatis tidak berlaku lagi begitu login sukses.

5. **Login sukses dicatat ke activity log** (`jenis_aksi: login_manual`) — informasi ini cuma relevan kalau FE nanti menampilkan Log Aktivitas (chunk lain), disebut di sini cuma buat konteks kenapa ada baris log baru tiap kali user login.

6. **Anti user-enumeration by design**: 3 skenario gagal yang beda kondisinya (identifier gak ketemu / user ketemu tapi belum pernah set password / user ketemu tapi password salah) — **semua menghasilkan status code, message, dan bentuk error yang identik** (lihat tabel error). FE **tidak bisa dan tidak boleh mencoba membedakan** ketiganya dari response — itu memang sengaja disamakan supaya orang gak bisa menebak identifier mana yang valid cuma dari beda-tidaknya pesan error. Yang beda cuma di sisi server: 2 dari 3 skenario itu bikin baris di activity log (`login_gagal`), skenario identifier-tidak-ditemukan cuma masuk ke log server biasa (bukan `activity_logs`) — tapi ini gak pernah kelihatan/berpengaruh ke FE.

7. **Ada dummy password-hash check** yang dijalankan bahkan waktu identifier gak ketemu — ini murni mitigasi timing-attack di sisi server (biar waktu respons gak beda signifikan antara "user gak ada" vs "user ada tapi password salah"), tidak ada efek apapun ke kontrak/response yang FE terima, disebut di sini cuma buat konteks kalau FE penasaran kenapa response time-nya konsisten.

### **Kode error per skenario**

| Status | Kapan muncul | `message` | Catatan |
| :---- | :---- | :---- | :---- |
| **422** | `identifier` dan/atau `password` tidak dikirim / kosong | `"Validasi gagal."` (envelope global) dengan `errors.identifier` / `errors.password` berisi pesan spesifik ("Username wajib diisi." / "Password wajib diisi.") | Validasi cuma `required` \+ `string`, tidak ada aturan format/panjang tambahan |
| **401** | `identifier` tidak match baris manapun di `users` (baik kolom `user_id_sopp` maupun `username`) | `"Username atau password salah."` | Tidak tercatat di `activity_logs`, cuma di log server |
| **401** | `identifier` ketemu, tapi kolom `password` user itu `null` di DB (user SSO-only yang belum pernah set password manual lewat Settings) | `"Username atau password salah."` | Tercatat di `activity_logs` sebagai `login_gagal` |
| **401** | `identifier` ketemu, `password` ada, tapi tidak cocok (`Hash::check` gagal) | `"Username atau password salah."` | Tercatat di `activity_logs` sebagai `login_gagal` |
| **429** | Percobaan ke-6 dan seterusnya dalam window 1 menit **dari IP yang sama** (gabungan semua percobaan gagal ATAU sukses dari IP itu ke endpoint ini) | `"Terlalu banyak percobaan. Coba lagi sebentar lagi."` | Ini global exception-handler envelope, sama persis dipakai di endpoint lain yang di-throttle |
| **419** | Cookie CSRF (`XSRF-TOKEN`) belum ada / tidak valid — FE lupa panggil `GET /sanctum/csrf-cookie` dulu, atau cookie sudah kedaluwarsa | `"Request tidak dapat diproses."` (fallback message generic karena exception aslinya tidak bawa pesan spesifik) | Berlaku untuk semua request state-changing (POST/PUT/PATCH/DELETE) ke domain ini, bukan cuma login |

---

## **2\. POST `/api/v1/auth/logout`**

### **Mekanis**

* **Method**: POST  
* **Auth**: **wajib** — di belakang middleware `auth:sanctum` (beda dari `login`, yang publik)  
* Tidak ada rate limit khusus di endpoint ini.

**Request body**: tidak ada (kosong).

**Response sukses — 200**

{  
  "status": "ok",  
  "data": null  
}

### **Notes bisnis-logic**

1. Logout melakukan **invalidasi sesi penuh**: `session()->invalidate()` \+ `session()->regenerateToken()` \+ `Auth::forgetGuards()`. Setelah logout sukses, cookie sesi lama sudah tidak valid sama sekali — panggilan `GET /me` berikutnya dengan cookie lama akan otomatis 401, bukan cuma "kelihatan logout di FE tapi cookie masih hidup di backend".  
2. Logout sukses juga dicatat ke `activity_logs` (`jenis_aksi: logout_manual`).  
3. **Logout dua kali berturut-turut**: panggilan pertama 200 sukses, panggilan kedua (pakai cookie yang sama, yang sudah diinvalidasi) otomatis kena **401** — bukan 200 idempoten. FE harus anggap logout sebagai aksi sekali-jalan, jangan retry otomatis kalau gagal di percobaan kedua tanpa login ulang dulu.  
4. Karena endpoint ini butuh sesi valid, memanggilnya tanpa pernah login sama sekali juga langsung 401 (lihat tabel error).

### **Kode error per skenario**

| Status | Kapan muncul | `message` |
| :---- | :---- | :---- |
| **401** | Belum pernah login / cookie sesi tidak ada / sesi sudah invalid (termasuk logout kedua kali berturut-turut) | `"Belum login atau sesi berakhir."` |
| **419** | CSRF cookie belum di-handshake / tidak valid | `"Request tidak dapat diproses."` |

Tidak ada skenario 422/429/403 khusus di endpoint ini.

---

## **3\. GET `/api/v1/me`**

### **Mekanis**

* **Method**: GET  
* **Auth**: **wajib** — di belakang `auth:sanctum`  
* Tidak butuh CSRF (GET tidak divalidasi CSRF), tapi tetap butuh cookie sesi valid dari login sebelumnya.

**Request**: tidak ada body/query param.

**Response sukses — 200**

{  
  "status": "ok",  
  "data": {  
    "user": {  
      "nama": "string",  
      "loket\_id": "number | null",  
      "nama\_loket": "string | null",  
      "is\_superadmin": "boolean",  
      "bisa\_akses\_laporan": "boolean",  
      "bisa\_atur\_hak\_akses": "boolean",  
      "bisa\_lihat\_log": "boolean",  
      "bisa\_input\_setoran\_semua\_loket": "boolean",  
      "bisa\_edit\_setoran\_semua\_loket": "boolean"  
    }  
  }  
}

Bentuknya **identik** dengan `data.user` di response login sukses — reuse type/interface yang sama di FE.

### **Notes bisnis-logic**

1. Ini endpoint **sumber kebenaran kapabilitas terkini** buat FE — dipakai lagi setelah refresh halaman (cookie sesi masih ada tapi FE kehilangan state React-nya, perlu tanya ulang "siapa saya & boleh apa"). Aturan OR-pattern kapabilitas persis sama seperti dijelaskan di bagian login (poin 2\) — endpoint ini dan login **wajib selalu sinkron** karena dua-duanya manggil method terpusat yang sama di backend (`User::kapabilitasUntukApi()`), jadi FE tidak akan pernah menemukan kondisi di mana kapabilitas versi login beda dengan versi `/me` untuk user yang sama.  
2. Endpoint ini **read-only**, tidak trigger efek samping apapun (tidak ada activity log yang ditulis di sini).  
3. Dipakai FE sebagai cara termudah cek "apakah sesi saya masih hidup" — 200 berarti valid, 401 berarti harus redirect ke halaman login.

### **Kode error per skenario**

| Status | Kapan muncul | `message` |
| :---- | :---- | :---- |
| **401** | Belum pernah login, sesi sudah habis (idle timeout), atau sesi sudah di-invalidate lewat logout | `"Belum login atau sesi berakhir."` |

Tidak ada skenario 422/429/403/419 di endpoint ini (GET, tidak divalidasi CSRF, tidak ada body untuk divalidasi).

---

## **Catatan tambahan — konfigurasi sesi (relevan buat FE walau bukan endpoint)**

* Session lifetime: **120 menit idle** (`SESSION_LIFETIME`, default Laravel) — kalau user diam \>120 menit, sesi expired, request berikutnya (GET `/me` atau apapun) akan 401 walau user tidak pernah klik logout.  
* Session driver: `database` — sesi tersimpan di server (bukan cookie-only), cookie browser cuma menyimpan session-ID.  
* Sanctum guard: `web` (session/cookie based) — **bukan** token Bearer, jadi tidak ada `Authorization` header yang perlu FE kirim manual; semuanya jalan lewat cookie otomatis selama request dikirim dengan `credentials: include` / `withCredentials: true` dan origin FE terdaftar di `SANCTUM_STATEFUL_DOMAINS`.  
* CORS: `supports_credentials: true`, origin diambil dari env `FRONTEND_URLS` (bisa lebih dari satu, dipisah koma) — kalau domain FE production belum dimasukkan ke env ini, semua request (termasuk yang sudah lolos CSRF) akan diblok browser di level CORS, bukan dapat error JSON dari backend.

## **Catatan risiko yang sudah diketahui (bukan bug FE, tapi konteks penting)**

Invariant "`user_id_sopp` dan `username` tidak akan pernah terisi bersamaan di baris yang sama" itu dijaga secara disiplin di proses provisioning user (SSO vs seeder manual), **bukan** lewat constraint database. Kalau suatu saat invariant ini dilanggar (mis. karena bug provisioning di masa depan), lookup `orWhere()` di endpoint login akan selalu mengambil baris pertama yang match — efeknya silent lockout buat user kedua yang identifier-nya kebetulan sama di kolom lain, bukan celah keamanan yang bisa dieksploitasi. Ini sudah ditandai sebagai follow-up terpisah di kode (bukan sesuatu yang perlu ditangani FE sekarang), dicatat di sini semata supaya FE paham kalau suatu saat menemukan gejala "user X gak bisa login padahal password benar", ini salah satu kemungkinan akar masalahnya.

# Loket

# **Kontrak API — Loket Setoran**

## **`GET /api/v1/lokets`**

### **Auth & gate — dari `routes/api.php` \+ body `LoketController::index()`**

Route ini didaftarkan di dalam `Route::middleware('auth:sanctum')->group(...)` bersama route-route lain (`/me`, `/auth/logout`, dll), **tanpa middleware tambahan apapun** (tidak ada `throttle:`, tidak ada middleware capability) — beda dengan route tetangganya seperti `/me/password` yang eksplisit ditempel `throttle:5,1`.

Isi method `index()`:

public function index(): JsonResponse  
{  
    return response()-\>json(\[  
        'status' \=\> 'ok',  
        'data' \=\> Loket::orderBy('nama\_loket')-\>get(\['loket\_id', 'nama\_loket'\]),  
    \]);  
}

→ **Fakta:** satu-satunya syarat akses endpoint ini adalah sudah login (`auth:sanctum`). Tidak ada gate kapabilitas.

### **Request**

Tidak ada input yang dibaca sama sekali di method ini — tidak ada `$request->...`, tidak ada `validate()`. Endpoint tidak menerima query string atau body apapun.

### **Response sukses — `200 OK`**

Struktur dari kode: `['status' => 'ok', 'data' => Loket::orderBy('nama_loket')->get(['loket_id', 'nama_loket'])]`.

{  
  "status": "ok",  
  "data": \[  
    { "loket\_id": 1, "nama\_loket": "Alpha" },  
    { "loket\_id": 2, "nama\_loket": "Zebra" }  
  \]  
}

* `data` selalu array (hasil `->get()` Eloquent, tidak pernah `null`).  
* `->get(['loket_id', 'nama_loket'])` — cuma 2 kolom ini yang di-select, meski tabel `lokets` punya kolom lain (`synced_at`, `created_at`, `updated_at` — lihat migration). Dikonfirmasi test `test_response_cuma_berisi_loket_id_dan_nama_loket` (`assertJsonStructure(['status','data'=>[['loket_id','nama_loket']]])`).  
* `orderBy('nama_loket')` — urut alfabetis nama, **bukan** `loket_id`. Dikonfirmasi test `test_daftar_terurut_berdasarkan_nama_bukan_id`: loket dibuat dengan urutan insert `id=2 "Zebra"` lalu `id=1 "Alpha"`, response tetap `data.0.nama_loket = "Alpha"`, `data.1.nama_loket = "Zebra"`.  
* Kalau tabel `lokets` kosong, `->get()` balikin collection kosong → `data: []`, tetap `200` (tidak ada percabangan kode yang mengubah status jadi error kalau kosong). Dikonfirmasi test `test_tidak_ada_loket_balikin_array_kosong_bukan_error`.

### **Response gagal**

| Status | Kondisi (dari kode) |
| :---- | :---- |
| `401` | Request tanpa sesi/token valid — ditolak oleh middleware `auth:sanctum` sebelum masuk ke method `index()` sama sekali (method-nya sendiri tidak punya logika yang menghasilkan 401). Dikonfirmasi test `test_belum_login_ditolak_401`. |

Tidak ada baris kode di `index()` yang bisa menghasilkan `403` atau `422` — tidak ada input untuk divalidasi, tidak ada pengecekan otorisasi tambahan setelah `auth:sanctum`.

---

## **Notes — perilaku yang gak keliatan dari tipe data doang**

### **1\. Endpoint ini bukan sinyal otorisasi**

Karena `index()` tidak mengecek kapabilitas apapun (lihat di atas), FE **tidak bisa** memakai "berhasil 200 dari `/api/v1/lokets`" sebagai bukti user boleh input/edit setoran loket lain. Dua endpoint lain memang membutuhkan isi tabel `lokets` untuk validasi `loket_id` yang dikirim user — ini kode, bukan komentar:

* `SetoranEntryController::resolveLoketId()` (Card A), baris validasi:  
   'loket\_id' \=\> \['integer', 'exists:lokets,loket\_id'\],  
* `PelunasanController::resolveLoketId()` — pola validasi persis sama: `'loket_id' => ['integer', 'exists:lokets,loket_id']`.

Kedua method ini baru mengizinkan `loket_id` mengarah ke loket lain (bukan `loket_id` milik user sendiri) kalau user punya kapabilitas `bisa_input_setoran_semua_loket` — pengecekan kapabilitas itu ada di kode masing-masing controller tsb, **bukan** di `LoketController`. Jadi: `GET /api/v1/lokets` cuma menyuplai *pilihan* nama+id loket untuk ditampilkan; keputusan siapa yang boleh benar-benar mengirim `loket_id` orang lain tetap divalidasi ulang di endpoint Card A / Pelunasan itu sendiri.

Detail Setoran memang memakai tabel `lokets` juga, tapi lewat jalur yang beda: `DetailSetoranController::show()` eager-load relasi `->with(['loket', ...])` dan menampilkan `$row->loket?->nama_loket` (baca nama loket dari row yang sudah ada). Ini **bukan** pemakaian endpoint `GET /api/v1/lokets` sebagai dropdown — tidak ada input `loket_id` dari user yang divalidasi `exists:lokets,loket_id` di controller ini, jadi tidak ada kebutuhan FE manggil endpoint ini untuk keperluan Detail Setoran. 

### **2\. Tabel `lokets` diisi lewat DUA jalur kode berbeda, bukan cuma satu**

**Jalur A — command `sopp:sync-loket` (`SyncLoket.php`):** memanggil `BridgeService::fetchMasterLoket()`, lalu `Loket::upsert($rows, ['loket_id'], ['nama_loket', 'synced_at', 'updated_at'])`. Dijalankan lewat CLI. Saya cek `routes/console.php` (cuma berisi command `inspire` bawaan Laravel) dan tidak ada `app/Console/Kernel.php` di project ini — jadi **tidak ada satupun kode yang mendaftarkan command ini ke scheduler**. Berdasarkan kode yang ada, command ini cuma bisa jalan kalau dipanggil manual dari CLI.

**Jalur B — auto-provisioning saat login SSO (`SsoController::provisionOrRefreshUser()`):** setiap kali ada login, method ini memanggil `ensureLoketExists($payload['loket_id'])` **tanpa syarat**, yang isinya:

Loket::firstOrCreate(  
    \['loket\_id' \=\> $loketId\],  
    \['nama\_loket' \=\> "Loket \#{$loketId} (belum disinkron)"\],  
);

Artinya: **kalau seorang user login dengan `loket_id` yang belum ada di tabel `lokets`, baris baru langsung dibuat otomatis saat itu juga**, dengan `nama_loket` berupa placeholder literal `"Loket #{id} (belum disinkron)"` — bukan nama asli dari sistem lama.

→ **Konsekuensi buat FE:** `GET /api/v1/lokets` bisa saja mengembalikan entri dengan `nama_loket` berbentuk `"Loket #7 (belum disinkron)"` — ini bukan bug, ini hasil dari `firstOrCreate` placeholder di atas, terjadi kalau ada teller di loket baru yang sudah login duluan sebelum admin sempat jalankan `sopp:sync-loket`. Nama itu akan tetap seperti itu di response sampai command sync manual dijalankan dan meng-upsert `nama_loket` yang benar (`upsert(..., ['nama_loket', 'synced_at', 'updated_at'])` di `SyncLoket.php` meng-update kolom itu berdasarkan `loket_id` yang cocok).

### **3\. Tidak ada caching**

Tidak ada pemanggilan `Cache::` atau helper `cache()` di `LoketController.php`. Tiap request → query langsung ke database. Tidak ada logika invalidation untuk FE tunggu — kalau FE nyimpen hasil ini di state, refetch manual perlu dipicu sendiri oleh FE.

### **4\. `loket_id` bukan auto-increment**

Dari `Loket.php`: `$incrementing = false`, dan dari migration: `loket_id` didefinisikan sebagai `unsignedInteger('loket_id')->primary()` (bukan `id()`/`increments()`). Nilainya adalah ID loket dari sistem lama itu sendiri (terlihat dari `SyncLoket.php` yang mengambil `loket_id` langsung dari payload bridge, dan dari `SsoController` yang memakai `$payload['loket_id']` yang sama sebagai primary key saat auto-provisioning). Tidak ada mapping/translasi ID di tengah — `loket_id` yang dibalikin endpoint ini adalah nilai yang sama yang harus dikirim balik ke Card A / Pelunasan.

---

## **Ringkasan untuk FE**

`GET /api/v1/lokets` cuma butuh login (tidak ada gate kapabilitas apapun secara kode), tanpa parameter, selalu `200` dengan array `{loket_id, nama_loket}` terurut nama (bisa kosong, tidak pernah error kecuali `401` belum login). Dua hal yang kode buktikan penting buat FE:

1. Endpoint ini **bukan** sinyal otorisasi — Card A dan Pelunasan tetap validasi ulang `loket_id` (`exists:lokets,loket_id`) dan cek kapabilitas sendiri-sendiri di kode masing-masing.  
2. Isi tabelnya bisa berupa **placeholder auto-generated saat login** (`"Loket #{id} (belum disinkron)"`) untuk loket yang belum pernah di-sync manual — bukan cuma dari command sync. FE sebaiknya siap menampilkan nama placeholder ini apa adanya kalau muncul di response.

# Bukti Setoran

# **API Contract — Bukti Setoran (Serve Foto)**

---

## **1\. Apa fungsi endpoint ini**

Endpoint ini **satu-satunya jalan** untuk menampilkan foto bukti setoran (foto struk transfer/QRIS/tunai yang diupload teller). Ini BUKAN endpoint yang mengembalikan JSON — ini mengembalikan **file gambar mentah** (binary stream), dipakai langsung sebagai `src` sebuah `<img>` di FE.

File fisiknya disimpan di disk `local` yang root-nya `storage/app/private` (dikonfirmasi dari `config/filesystems.php`) — **bukan** `storage/app/public`, jadi **tidak ada symlink publik** ke folder ini. Tidak ada cara lain untuk mengakses foto selain lewat endpoint ini (tidak bisa ditembak langsung sebagai static URL).

---

## **2\. Mekanisme (endpoint murni)**

| Method | `GET` |
| :---- | :---- |
| **Path** | `/api/v1/bukti-setoran/{path}` |
| **Auth** | Wajib login (`auth:sanctum`, cookie-based session) — di dalam grup middleware yang sama dengan semua endpoint teller lain |
| **Path param `{path}`** | String, **boleh mengandung slash** (`Route::where('path', '.*')` — bukan segment tunggal). Formatnya `"{tanggal}/{nama_file}"`, contoh: `2026-08-10/1_tunai_1755000000_ab12cd.jpg` |
| **Query/body** | Tidak ada. Endpoint ini tidak menerima query param maupun body apapun. |
| **Response sukses** | **Bukan JSON.** Response berupa file stream (`StreamedResponse`) dengan header `Content-Type` sesuai ekstensi file asli (mis. `image/jpeg`), `Content-Length`, dan `Content-Disposition: inline` (jadi browser menampilkan gambar, bukan memaksa download) |
| **Status code sukses** | `200` |
| **Rate limit** | **Tidak ada** throttle middleware terpasang di route ini (beda dari `/riwayat/export` yang dibatasi `throttle:10,1`). FE bebas panggil berkali-kali, termasuk untuk render banyak thumbnail sekaligus. |

### **Cara FE mendapatkan `{path}`**

FE **tidak pernah menyusun path ini sendiri**. Path selalu didapat dari field `foto_bukti_url` yang sudah jadi (bentuk URL lengkap, `.../api/v1/bukti-setoran/{path}`) di response endpoint-endpoint lain yang menampilkan entry setoran (Card A, Detail Setoran, Laporan). FE tinggal pakai `foto_bukti_url` itu apa adanya sebagai `src`.

---

## **3\. Notes bisnis-logic (kondisi yang gak keliihatan dari tipe data)**

### **3.1 Siapa yang boleh lihat foto — aturan akses (4 jalur, salah satu cukup)**

Boleh akses foto ini kalau **salah satu** dari kondisi berikut benar:

1. **Superadmin** (`is_superadmin = true`) — bebas akses foto siapa saja, status apapun (draft/submitted).  
2. **Punya kapabilitas akses laporan** (`bisa_akses_laporan = true`) — bebas akses foto siapa saja, status apapun.  
3. **Pemilik entry itu sendiri** (`setoranHarian.user_id === user yang login`) — boleh lihat foto miliknya sendiri **kapanpun, apapun statusnya** (draft maupun submitted).  
4. **Punya kapabilitas edit setoran lintas-loket** (`bisa_edit_setoran_semua_loket = true`) — **TAPI hanya kalau entry itu statusnya `submitted`**. Kalau entry-nya masih `draft` milik orang lain, kapabilitas ini **tidak berlaku**, tetap ditolak 403\.

Ini konsisten dengan aturan "draft cuma boleh diakses pemiliknya sendiri" yang berlaku di seluruh sistem SOPP (sama seperti aturan di Card A dan guard hapus Riwayat) — kapabilitas edit apapun **tidak pernah** membuka akses ke draft milik orang lain.

Kalau user tidak memenuhi satupun dari 4 kondisi di atas → **403 Forbidden**.

### **3.2 Tidak semua entry punya foto — metode `kpay` dikecualikan**

Dari model `SetoranDetail::butuhFotoBukti()`: field `foto_bukti_path` **wajib diisi untuk metode `transfer`, `qris`, `tunai`**, tapi **tidak wajib (dan biasanya null) untuk metode `kpay`**. Konsekuensinya untuk chunk ini:

* Kalau `foto_bukti_path` sebuah entry adalah `null`, endpoint-endpoint lain juga akan mengembalikan `foto_bukti_url: null` untuk entry itu.  
* FE **wajib** cek null dulu sebelum memanggil endpoint bukti setoran / render `<img>` — jangan asumsikan tiap baris setoran pasti punya foto.

### **3.3 `{path}` harus persis sama dengan yang ada di database — bukan path filesystem bebas**

Lookup di controller adalah `SetoranDetail::where('foto_bukti_path', $path)->firstOrFail()` — jadi `{path}` yang dikirim FE **dicocokkan ke kolom database dulu**, bukan langsung dipakai untuk baca file di disk. Efeknya:

* Tidak bisa dipakai untuk menebak/mengintip file lain di luar yang tercatat di database (tidak ada path traversal ke file arbitrary), karena harus match baris `setoran_detail` yang benar-benar ada.  
* Kalau `{path}` tidak match baris manapun di database → **404**, bukan error filesystem.

### **3.4 Path fisik ≠ path yang tersimpan di database**

Path yang tersimpan di kolom `foto_bukti_path` (dan yang jadi URL/`{path}`) **tidak** menyertakan folder root `bukti_setoran/` — itu ditambahkan lagi secara manual satu-satunya di baris terakhir `BuktiSetoranController::show()` sebelum baca dari disk. Ini detail internal implementasi, tapi menjelaskan kenapa `{path}` di URL terlihat "pendek" (cuma `{tanggal}/{nama_file}`) padahal lokasi fisik sebenarnya `bukti_setoran/{tanggal}/{nama_file}`. Tidak berdampak ke kontrak FE (FE tetap pakai `foto_bukti_url` apa adanya), murni informasi konteks.

### **3.5 Tidak ada caching header eksplisit**

Response tidak menambahkan `Cache-Control`/`ETag` kustom apapun — murni default `Content-Type` \+ `Content-Length` \+ `Content-Disposition: inline`. Kalau FE butuh caching gambar (misal supaya tidak refetch tiap kali komponen re-render), itu harus diatur di sisi FE/browser sendiri, backend tidak bantu apa-apa di sisi ini.

---

## **4\. Kode error per skenario**

| Skenario | Status | Body (envelope global, lihat §4.1) | Sumber di kode |
| :---- | :---- | :---- | :---- |
| Belum login / sesi habis | **401** | `message: "Belum login atau sesi berakhir."` | Middleware `auth:sanctum` → ditangkap `AuthenticationException` handler global |
| `{path}` tidak match baris `setoran_detail` manapun | **404** | `message: "Data tidak ditemukan."` | `->firstOrFail()` → `ModelNotFoundException` → handler global |
| User login tapi tidak memenuhi satupun dari 4 kondisi akses (§3.1) | **403** | `message: "Tidak memiliki akses ke foto bukti ini."` | `abort(403, 'Tidak memiliki akses ke foto bukti ini.')` eksplisit di controller → handler global (masuk cabang `HttpExceptionInterface` generik, message dipakai apa adanya) |
| Sukses, boleh akses | **200** | *(bukan JSON — file stream, lihat §2)* | — |
| Row DB ada, file fisik hilang dari disk | **500** *(terkonfirmasi dari kode dependency, belum ada test app)* | `message: "Terjadi kesalahan pada server."` | Lihat detail di §4.2 |

### **4.1 Format envelope error (berlaku global, bukan spesifik chunk ini)**

Semua exception di route `/api/*` diseragamkan lewat satu handler di `bootstrap/app.php`, bentuknya:

{  
  "status": "error",  
  "message": "...",  
  "errors": {}  
}

`errors` selalu object kosong `{}` untuk ketiga skenario error di atas (401/403/404) — tidak ada rincian field-level seperti pada 422 validasi (chunk ini memang tidak punya body request yang divalidasi, jadi tidak akan pernah muncul 422).

### **4.2 Baris database ada, tapi file fisik sudah hilang dari disk → 500**

Skenario: row `setoran_detail` ketemu (`foto_bukti_path` match), tapi file fisiknya sudah tidak ada di disk (misal terhapus manual di luar aplikasi, atau storage bermasalah). Ini **tidak ada test aplikasi yang meng-cover-nya**, tapi saya sudah telusuri sampai ke kode dependency-nya (`vendor/laravel/framework` dan `vendor/league/flysystem-local`) untuk memastikan perilakunya, bukan menebak:

* `Storage::disk('local')->response()` (dipanggil `BuktiSetoranController::show()`) menghitung header `Content-Length` lewat `FilesystemAdapter::size()`, yang manggil `LocalFilesystemAdapter::fileSize()` langsung **tanpa try/catch apapun** di sisi Laravel.  
* Kalau file tidak ada, `LocalFilesystemAdapter::fileSize()` melempar `League\Flysystem\UnableToRetrieveMetadata` (`vendor/league/flysystem-local/LocalFilesystemAdapter.php:449`) — exception ini murni `RuntimeException` biasa, **bukan** `HttpExceptionInterface`/`ModelNotFoundException`/exception lain yang dikenali khusus di `bootstrap/app.php`.  
* Konsekuensinya: exception ini jatuh ke cabang **fallback generik terakhir** di handler global → **status `500`**, body `{"status":"error","message":"Terjadi kesalahan pada server.","errors":{}}`.  
* Ini beda dengan `mimeType()` (dipanggil tepat sebelum `size()` di kode yang sama) yang memang sengaja dibungkus try/catch dan diredam sesuai config `'throw' => false` di `config/filesystems.php` — tapi `size()` tidak dapat perlakuan yang sama, jadi tetap tembus jadi exception mentah.

**Kesimpulan: perilaku 500 ini terkonfirmasi lewat pembacaan kode dependency langsung**, bukan dugaan — tapi tetap belum ada test aplikasi (feature test) yang membuktikannya end-to-end, jadi kalau mau dijadikan kontrak resmi ke FE (misal FE mau nge-handle 500 khusus untuk kasus ini), sebaiknya ditambahkan test dulu di BE untuk mengunci perilaku ini secara eksplisit.

---

## **5\. Ringkasan tabel akses (buat cepat dicek FE/QA)**

| Kondisi user | Foto milik sendiri | Foto orang lain, status `draft` | Foto orang lain, status `submitted` |
| :---- | :---- | :---- | :---- |
| Superadmin | ✅ 200 | ✅ 200 | ✅ 200 |
| `bisa_akses_laporan = true` | ✅ 200 | ✅ 200 | ✅ 200 |
| `bisa_edit_setoran_semua_loket = true` (saja, tanpa dua kapabilitas di atas) | ✅ 200 | ❌ 403 | ✅ 200 |
| Teller biasa tanpa kapabilitas apapun | ✅ 200 | ❌ 403 | ❌ 403 |

*(Tabel ini adalah terjemahan langsung dari 6 test yang ada di `BuktiSetoranTest.php` — semua sel di atas punya test yang menegaskannya, kecuali kombinasi yang secara logis pasti sama dengan yang sudah dites.)*

# Dashboard Teller

# **Kontrak API — Dashboard Teller (SOPP)**

| Istilah | Artinya |
| :---- | :---- |
| **Loket** | Cabang/counter tempat teller bertugas. 1 teller selalu terikat ke 1 loket (`loket_id`). |
| **Bridge** | Jembatan ke sistem SOPP lama (WAMP/MySQL lama) yang jadi sumber angka "pendapatan resmi" per loket per tanggal. Bisa gagal dihubungi kapan saja (server lama, bukan under kontrol kita). |
| **Setoran** | 1 dokumen input teller untuk 1 loket \+ 1 tanggal, isinya banyak baris pembayaran (tunai/QRIS/transfer/KPAY). Statusnya `draft` (belum final, masih bisa diubah/dihapus) atau `submitted` (sudah dikunci/final). |
| **Selisih** | Pendapatan versi Bridge dikurangi total setoran yang sudah di-submit. Kalau 0 \= "lunas"/cocok, kalau bukan 0 \= ada selisih yang perlu diverifikasi. |
| **Snapshot** | Hasil fetch dari Bridge yang disimpan sementara (di-cache 10 menit) supaya gak nembak Bridge tiap request. |

## **1\. Mekanis**

**Endpoint:** `GET /api/v1/teller/dashboard`

**Autentikasi:** wajib login (Sanctum). **Tidak ada** pembatasan kapabilitas/role — semua user login yang punya `loket_id` valid bisa akses endpoint ini. **Tidak ada** rate-limit di endpoint ini.

**Request:** tidak ada query parameter, tidak ada body. Selalu mengembalikan data untuk **hari ini**, untuk loket milik user yang sedang login (diambil dari sesi, bukan dari parameter).

### **Response sukses — `200 OK`**

{  
  "status": "ok",  
  "data": {  
    "nama\_teller": "Budi Santoso",  
    "nama\_loket": "Loket Malang Kota",  
    "tanggal\_transaksi": "2026-08-30",  
    "tanggal\_hari\_ini": "2026-08-30",  
    "pendapatan\_sopp": 5250000,  
    "pendapatan\_sopp\_status": null,  
    "data\_terkini": true,  
    "pendapatan\_kemarin\_status": null,  
    "persentase\_vs\_kemarin": 8.4,  
    "status\_setoran": "belum\_input",  
    "selisih": 250000,  
    "ada\_selisih": true,  
    "fetched\_at": "2026-08-30 08:55:00",  
    "aktivitas\_terakhir": \[  
      {  
        "tanggal\_transaksi": "2026-08-29",  
        "nama\_teller": "Siti Aminah",  
        "total\_pendapatan": 4800000,  
        "total\_setor": 4800000,  
        "selisih": 0,  
        "status\_keuangan": "lunas",  
        "status\_workflow": "submitted"  
      }  
    \]  
  }  
}

### **Deskripsi tiap field**

| Field | Tipe | Keterangan |
| :---- | :---- | :---- |
| `nama_teller` | string | Nama user yang login. |
| `nama_loket` | string | null | Nama loket. **Bisa `null`** — lihat catatan \#9 di bawah. |
| `tanggal_transaksi` | string (YYYY-MM-DD) | Tanggal hari ini. |
| `tanggal_hari_ini` | string (YYYY-MM-DD) | Isinya **selalu identik** dengan `tanggal_transaksi` di atas — dua nama field untuk data yang sama, bukan duplikat yang perlu dibersihkan/dipilih salah satu. |
| `pendapatan_sopp` | integer | null | Angka pendapatan versi Bridge, hari ini. `null` kalau gagal dimuat. |
| `pendapatan_sopp_status` | `"gagal_dimuat"` | null | Penanda kegagalan untuk `pendapatan_sopp`. |
| `data_terkini` | boolean | null | Lihat catatan \#5. |
| `pendapatan_kemarin_status` | `"gagal_dimuat"` | null | Penanda kegagalan untuk data **kemarin**. Wajib baca catatan \#4. |
| `persentase_vs_kemarin` | float | null | Perubahan pendapatan hari ini vs kemarin dalam persen, dibulatkan 1 desimal. |
| `status_setoran` | `"belum_input"` | `"draft"` | `"submitted"` | Status setoran **milik teller yang login sendiri**, hari ini. |
| `selisih` | integer | null | Lihat catatan \#1. |
| `ada_selisih` | boolean | null | `true` kalau `selisih !== 0`. `null` kalau `selisih` juga `null`. |
| `fetched_at` | string | null | Waktu terakhir Bridge berhasil di-fetch untuk data hari ini. |
| `aktivitas_terakhir` | array (maks 8 item) | Lihat catatan \#7. |

**Struktur tiap item di `aktivitas_terakhir`:**

| Field | Tipe | Keterangan |
| :---- | :---- | :---- |
| `tanggal_transaksi` | string | Tanggal kejadian. |
| `nama_teller` | string | null | `null` untuk baris `belum_input` (tidak ada teller yang bisa disebut). |
| `total_pendapatan` | integer | null | Pendapatan versi Bridge di tanggal itu. |
| `total_setor` | integer | null | **Personal** — total setoran teller tsb saja, di baris itu saja. Lihat catatan \#7a. |
| `selisih` | integer | null | **Gabungan loket** — beda basis dari `total_setor` di baris yang sama. Lihat catatan \#7a. |
| `status_keuangan` | `"lunas"` | `"belum_lunas"` | null |  |
| `status_workflow` | `"submitted"` | `"belum_input"` | Draft tidak pernah muncul di array ini. |

### **Response gagal — `403 Forbidden`**

Muncul kalau user yang login tidak punya `loket_id` (mis. akun superadmin generik yang nyasar hit endpoint teller):

{  
  "status": "error",  
  "message": "Akun ini tidak terhubung ke loket manapun.",  
  "errors": {}  
}

### **Response gagal — `401 Unauthorized`**

Belum login / sesi habis. Ini format envelope error standar seluruh API (bukan format default Laravel), dipakai konsisten di semua endpoint:

{  
  "status": "error",  
  "message": "Belum login atau sesi berakhir.",  
  "errors": {}  
}

---

## **2\. Notes bisnis-logic**

Bagian ini berisi aturan/kondisi yang **tidak kelihatan dari tipe data doang** — kalau FE cuma baca tabel field di atas tanpa baca ini, kemungkinan besar salah tampil.

### **\#1 — `selisih` dihitung dari total GABUNGAN loket, hanya yang `submitted`**

Angka pembanding untuk `selisih` \= total setoran **semua teller** di loket yang sama, hari ini, **tapi hanya row berstatus `submitted`**. Draft — termasuk draft milik teller yang sedang login sendiri — **tidak pernah** ikut dihitung.

Alasan bisnis: kalau draft ikut dihitung, dashboard bisa kelihatan "sudah setor" padahal teller belum pernah pencet submit. Ini disengaja, bukan celah yang belum sempat ditutup.

### **\#2 — `status_setoran` itu axis yang beda sendiri dari `selisih`**

`status_setoran` murni personal (row milik user yang login). Jadi kombinasi berikut **valid dan bisa terjadi bersamaan**, jangan dianggap data rusak:

* `status_setoran: "belum_input"` **dan** `selisih: 0` — bisa terjadi kalau teller lain di loket yang sama sudah submit duluan sampai lunas, sementara teller yang login belum input apa-apa hari ini.  
* `status_setoran: "draft"` **dan** `selisih` masih penuh (belum berkurang sama sekali) — draft sendiri gak pernah mengurangi selisih, walau nominalnya sudah diisi di form.

### **\#3 — Dua fetch Bridge (hari ini & kemarin) sepenuhnya independen**

Gagal ambil data hari ini tidak pernah menjatuhkan data kemarin, atau sebaliknya. Response tetap `200 OK` walau **kedua-duanya** gagal sekaligus — endpoint ini didesain untuk tidak pernah 500 karena Bridge down.

### **\#4 — Wajib bedakan 3 kombinasi status/persentase kemarin**

Supaya tidak salah tampil ke user, FE wajib cek kombinasi berikut untuk data **kemarin**:

| `pendapatan_kemarin_status` | `persentase_vs_kemarin` | Artinya | Tampilkan sebagai |
| :---- | :---- | :---- | :---- |
| `"gagal_dimuat"` | `null` | Data kemarin gagal diambil dari Bridge | "Data tidak bisa dimuat" — **jangan** "0%" |
| `null` | `null` | Data kemarin **berhasil** diambil, tapi memang nilainya 0 | "Kemarin tidak ada pendapatan" — **jangan** "0%" (bisa disalahartikan "tidak berubah dari kemarin") |
| `null` | ada angka | Kondisi normal | Tampilkan apa adanya |

Catatan: angka mentah pendapatan kemarin (rupiah) **tidak dikirim** oleh API ini, hanya persentasenya. Kalau FE nanti butuh angka mentahnya juga, itu perlu request tambahan terpisah — belum ter-cover di endpoint ini.

### **\#5 — `data_terkini` menandai kesegaran data `pendapatan_sopp` (hari ini)**

* `true` — data baru saja berhasil di-fetch dari Bridge, atau masih dalam rentang cache 10 menit.  
* `false` — Bridge gagal dihubungi saat request ini, tapi ada data lama (di luar 10 menit) yang dipakai sebagai fallback. **Tampilkan indikator "data mungkin belum terbaru"** di UI kalau `false`.  
* `null` — Bridge gagal **dan** sama sekali tidak ada data fallback apapun. Dalam kondisi ini `pendapatan_sopp_status` pasti `"gagal_dimuat"`.

Field ini **hanya berlaku untuk `pendapatan_sopp`** (hari ini) — tidak ada field setara untuk data kemarin maupun untuk isi `aktivitas_terakhir`.

### **\#6 — `selisih`/`ada_selisih` jadi `null`, bukan `0`/`false`, saat data gagal dimuat**

Kalau `pendapatan_sopp` gagal (`pendapatan_sopp_status: "gagal_dimuat"`), maka `selisih` dan `ada_selisih` otomatis ikut `null`. **Jangan** ditampilkan sebagai "selisih 0 / sudah lunas" — itu salah baca total, artinya "tidak diketahui", bukan "cocok".

### **\#7 — `aktivitas_terakhir` adalah feed PER-LOKET, bukan riwayat personal**

Poin ini paling sering disalahpahami, jadi dirinci:

* **Cakupan siapa**: semua teller di loket ini, bukan cuma teller yang login.  
* **Bentuk baris**: 1 baris \= 1 kejadian submit. Kalau 2 teller submit di tanggal yang sama, itu jadi **2 baris terpisah**, tidak digabung jadi 1\.  
* **Rentang tanggal**: H-30 sampai H-1 (30 hari ke belakang, **tidak termasuk hari ini**), diambil maksimal 8 baris terbaru.  
* **Draft tidak pernah muncul di sini sama sekali** — baik draft milik teller lain maupun milik teller yang sedang login. Ini keputusan privasi (kerjaan yang belum selesai tidak ditampilkan ke siapapun), bukan bug.  
* **Baris `status_workflow: "belum_input"`** muncul kalau Bridge punya data transaksi di tanggal itu untuk loket ini, tapi **nol** row `submitted` dari siapapun. Artinya: "belum ada satupun teller yang submit di tanggal itu" — **bukan** "giliran teller tertentu belum jalan".

**\#7a — Basis perhitungan `total_setor` vs `selisih` per baris BERBEDA**, walau sekilas terlihat harus konsisten satu sama lain:

* `total_setor` di tiap baris \= **personal**, cuma total setoran teller itu untuk baris/tanggal itu saja.  
* `selisih` di baris yang sama \= dihitung dari perbandingan **gabungan loket** (pendapatan Bridge dikurangi total submit semua teller di tanggal itu), sama seperti logika di catatan \#1 — bisa melibatkan setoran teller lain yang tidak muncul angkanya secara eksplisit di baris ini.

Contoh: baris teller A nunjukin `total_setor: 200000`, tapi `selisih: 0` — itu valid kalau ternyata di tanggal yang sama ada teller B yang juga submit 150000, dan total gabungan (350000) pas sama pendapatan Bridge hari itu. Jangan cross-check `selisih` cuma pakai `total_setor` di baris yang sama — itu perbandingan yang salah.

### **\#8 — Isolasi antar loket & antar teller dijamin ketat**

* `selisih` (top-level) di-scope per `loket_id` — setoran loket lain tidak pernah ikut kehitung.  
* `status_setoran` di-scope per `user_id` \+ `loket_id` — status/aktivitas teller lain di loket yang sama tidak "bocor" ke field ini.

### **\#9 — `nama_loket` bisa `null`, itu kondisi valid**

Terjadi kalau `loket_id` milik user tidak (belum) ada di tabel referensi loket lokal — biasanya loket baru yang belum sempat disinkronkan. Ini **bukan error**, tidak pernah menyebabkan 500, dan endpoint tetap berjalan normal untuk field lainnya. FE cukup tampilkan fallback (mis. "-" atau kosongkan label loket) kalau `null`.

### **\#10 — Tidak ada gate akses tambahan di endpoint ini**

Berbeda dari beberapa endpoint SOPP lain yang butuh kapabilitas khusus (misal `bisa_akses_laporan`), endpoint dashboard ini terbuka untuk semua user login yang punya `loket_id`. Satu-satunya penolakan akses adalah kondisi \#403 di atas (tidak punya `loket_id` sama sekali).

---

## **3\. Kode error per skenario**

| Skenario | HTTP Status | Kapan terjadi |
| :---- | :---- | :---- |
| Belum login / token/sesi expired | **401** | Middleware auth gagal sebelum request sampai ke controller. |
| User login tapi `loket_id` kosong (null) | **403** | Dicek di awal controller, sebelum ada fetch data apapun. Body: `message: "Akun ini tidak terhubung ke loket manapun."` |
| Bridge gagal fetch data hari ini | **200** (bukan error) | `pendapatan_sopp` → `null`, `pendapatan_sopp_status` → `"gagal_dimuat"`, `selisih`/`ada_selisih` → `null`. Field lain (mis. `status_setoran`, `aktivitas_terakhir`) tetap terisi normal karena sumbernya database lokal, bukan Bridge. |
| Bridge gagal fetch data kemarin | **200** (bukan error) | `pendapatan_kemarin_status` → `"gagal_dimuat"`, `persentase_vs_kemarin` → `null`. Tidak memengaruhi data hari ini sama sekali. |
| Bridge gagal fetch **hari ini DAN kemarin** sekaligus | **200** (bukan error) | Kombinasi dua kondisi di atas terjadi bersamaan. Endpoint tetap merespons normal, tidak pernah 500\. |
| Request valid, semua data berhasil dimuat | **200** | Kondisi normal. |

**Yang TIDAK ada di endpoint ini:**

* **422 (validasi)** — tidak ada, karena tidak ada input/query param yang divalidasi sama sekali.  
* **429 (rate limit)** — tidak ada, endpoint ini tidak diberi throttle (beda dari beberapa endpoint lain di sistem ini seperti login atau export).  
* **500 karena Bridge down** — secara sengaja dihindari; semua skenario kegagalan Bridge ditangani jadi respons 200 dengan flag status, bukan exception yang bocor ke response.

# Card A Setoran

# **API Contract — Card A (Input Setoran Harian)**

Base path semua endpoint: `/api/v1`. Semua endpoint di dokumen ini butuh sesi login (`auth:sanctum`) — tanpa sesi valid, semua endpoint balikin **401**.

---

## **0\. Hal yang berlaku di SEMUA endpoint Card A (baca ini dulu)**

### **0.1 Response envelope**

Semua response, sukses maupun gagal, selalu:

{ "status": "ok" | "error", "data": {...} }

atau untuk error:

{ "status": "error", "message": "...", "errors": {...} }

`errors` selalu **object**, tidak pernah array — tapi isinya **beda tergantung sumber error**, ini penting buat FE karena menentukan apakah bisa nge-highlight per-field atau cuma nampilin `message` polos:

**Error dari validation rule** (`$request->validate()` — format salah, enum invalid, field wajib kosong, dsb): `errors` **terisi** per-field, format standar Laravel:  
 { "status": "error", "message": "Validasi gagal.", "errors": { "entries.0.nominal": \["The entries.0.nominal field must be at least 1."\], "entries.0.foto\_bukti": \["..."\] } }

* Key-nya pakai dot-notation persis path field di payload (`entries.0.nominal`, `entries.1.foto_bukti`, dst) — berguna kalau FE mau highlight baris/field yang salah secara spesifik. `message` di kasus ini selalu generik: `"Validasi gagal."`  
* **Error dari business-rule check manual** (lock 409, entry id tidak ditemukan, akun tanpa loket, dsb — bukan lewat `$request->validate()`): `errors` **selalu kosong** (`{}`), semua informasi ada di `message` sebagai kalimat lengkap berbahasa Indonesia. FE cuma bisa nampilkan `message` apa adanya, tidak ada breakdown per-field.  
* **Error dari `Gate::authorize()` gagal** (kirim `loket_id` tanpa kapabilitas): `errors` kosong, dan `message`\-nya **selalu teks generik tetap** `"Anda tidak memiliki akses untuk melakukan aksi ini."` — TIDAK peduli pesan apa yang ditulis di Policy method-nya (Policy method di codebase ini bahkan tidak custom message sama sekali), jadi jangan berharap teks yang lebih spesifik dari ini untuk kasus 403 akibat kapabilitas.

Tabel error di tiap endpoint di bawah menandai kolom "Sumber" untuk membedakan dua jenis ini.

### **0.2 Resolusi `loket_id` (dipakai di ke-4 endpoint)**

* Kalau parameter `loket_id` **tidak** dikirim di request → dipakai `loket_id` milik user yang login sendiri.  
  * Kalau user itu sendiri gak punya `loket_id` (akun superadmin murni) → **403**, bukan crash.  
* Kalau parameter `loket_id` **dikirim eksplisit** (dropdown pilih cabang di FE, dipakai Bu Wiwik):  
  * Divalidasi dulu: harus integer dan ada di tabel `lokets` → kalau tidak ada, **422** (bukan 403 — urutan cek ini sengaja: format/existensi dicek duluan sebelum kapabilitas).  
  * Baru dicek kapabilitas `bisa_input_setoran_semua_loket` → kalau `false`, **403**.  
  * **PENTING**: guard ini jalan **walaupun nilai `loket_id` yang dikirim sama persis dengan loket\_id user sendiri**. Ngirim parameter ini tanpa hak sama sekali sudah dianggap pelanggaran, terlepas dari nilai akhirnya sama atau beda.  
  * Kapabilitas `bisa_edit_setoran_semua_loket` **TIDAK** dicek di sini — itu kapabilitas yang berbeda, dipakai di chunk lain (Riwayat/Detail Setoran), bukan di Card A.

### **0.3 Resolusi `tanggal` (dipakai di ke-4 endpoint)**

* Parameter `tanggal` format `Y-m-d`. Kalau tidak dikirim → default hari ini.  
* **Tidak ada batas mundur** — teller boleh input/lihat tanggal kapan pun di masa lalu.  
* Tanggal **masa depan ditolak** (`422`).  
* Format selain `Y-m-d` (mis. `14-07-2026`) ditolak (`422`).

### **0.4 Model batch (bukan CRUD per-baris)**

FE menyimpan semua baris entry di state lokal selama teller masih mengisi form — tombol "+ Tambah"/"Hapus" di FE **TIDAK** memanggil API sama sekali. Baru saat "Simpan Draft" atau "Submit" ditekan, **satu** request dikirim berisi array `entries[]` penuh (representasi lengkap form saat itu):

* Baris yang bawa `id` (hasil dari `GET index()` sebelumnya) → di-**update**.  
* Baris tanpa `id` → di-**create** sebagai baris baru.  
* Baris lama (ada di DB) yang **tidak ikut terkirim lagi** di array → otomatis **dihapus** (delete-by-omission). Ini bukan bug, ini cara FE bilang "baris ini dihapus dari form".

Tidak ada endpoint create/update/delete per-baris terpisah di Card A.

### **0.5 Lock setelah submit (read-only)**

Begitu status row \= `submitted`, row itu **read-only** lewat Card A. Baik `simpanDraft()` maupun `submit()` lagi ke kombinasi `user_id + loket_id + tanggal` yang sama akan ditolak **409**, walaupun payload-nya valid. Perubahan lanjutan (tambah entry ketinggalan, edit, hapus setelah submit) adalah domain halaman **Detail Setoran** — bukan Card A.

* Lock ini **per tanggal** — submit ke tanggal A tidak mengunci tanggal B.  
* Status hanya bisa berubah `draft → submitted`, satu arah, satu kali. Tidak pernah flip balik ke `draft` lewat controller ini.

### **0.6 Aturan foto bukti per baris**

| Kondisi baris | Aturan `foto_bukti` |
| :---- | :---- |
| `metode_pembayaran = kpay` (baris baru ATAU lama) | **Dilarang** kirim foto sama sekali (`prohibited`) — kpay tidak pernah punya bukti foto |
| Baris **baru** (tanpa `id`), bukan kpay | **Wajib** foto |
| Baris **lama** (ada `id`), metode LAMA-nya kpay, sekarang pindah ke metode lain | **Wajib** foto baru — tidak ada foto lama yang bisa dipertahankan |
| Baris **lama** (ada `id`), bukan kpay dan tetap bukan kpay | **Opsional** — kalau tidak dikirim, foto lama otomatis dipertahankan; kalau dikirim, foto lama dihapus dan diganti |

Format foto: `jpg`/`jpeg`/`png`, maksimal 5MB (5120 KB). Di luar itu → `422`.

### **0.7 Aturan field kpay (`nama_karyawan` / `identitas_karyawan`)**

* `metode_pembayaran = kpay` → **wajib** isi `nama_karyawan` dan `identitas_karyawan`.  
* `metode_pembayaran` selain kpay → kedua field itu **dilarang** dikirim sama sekali (`prohibited_unless`), bukan cuma "diabaikan". Kalau tetap dikirim → `422`.  
* Konsekuensi penting untuk FE: kalau user ganti metode dari kpay ke metode lain di baris yang sama, FE **wajib mengosongkan** `nama_karyawan`/`identitas_karyawan` dari payload — kalau field lama itu masih ikut terkirim (walau usernya sudah ganti metode di form), request ditolak 422\.

### **0.8 Rate limit anti-klik-dobel**

`simpanDraft()` dan `submit()` sama-sama kena rate limit **2 detik** per kombinasi `user_id + loket_id + tanggal` (mencegah network lambat/klik dobel bikin baris baru ke-create dua kali). Kena limit → `429`.

### **0.9 `keterangan` dan `is_konfirmasi`**

* `keterangan`: opsional untuk **semua** metode pembayaran termasuk kpay — tidak ada aturan wajib kondisional untuk field ini.  
* `is_konfirmasi`: boolean, opsional (default `false` kalau tidak dikirim). Field ini murni display/checklist, **tidak** memengaruhi perhitungan `selisih`/`total_setor`.

---

## **1\. GET `/teller/setoran` — index (ambil state form)**

### **Mekanis**

* **Method/Path**: `GET /api/v1/teller/setoran`  
* **Query params**:  
  * `loket_id` (opsional, integer) — lihat §0.2  
  * `tanggal` (opsional, `Y-m-d`) — lihat §0.3  
  * `refresh` (opsional, boolean/`1`) — paksa fetch ulang data pendapatan SOPP dari bridge, bypass cache 10 menit

* **Response 200**:

{  
  "status": "ok",  
  "data": {  
    "setoran\_harian\_id": 12,           // null kalau belum pernah ada row utk kombinasi user+loket+tanggal ini  
    "tanggal\_transaksi": "2026-08-30",  
    "nama\_loket": "Loket A",  
    "nama\_teller": "Budi",  
    "status\_workflow": "draft",        // "belum\_input" | "draft" | "submitted"  
    "status\_keuangan": "belum\_lunas",  // "lunas" | "belum\_lunas" | null (null kalau data SOPP gagal dimuat)  
    "is\_persisted": true,              // true kalau row setoran\_harian sudah ada di DB  
    "is\_locked": false,                // true HANYA kalau is\_persisted && status \=== 'submitted'  
    "fetched\_at": "2026-08-30T09:00:00+07:00", // null kalau pendapatan\_sopp\_status \= gagal\_dimuat  
    "pendapatan\_sopp\_status": null,    // null | "gagal\_dimuat"  
    "data\_pendapatan\_sopp": \[ { "vendor\_id": 1, "vendor\_name": "...", "kategori": "...", "total": 50000 } \], // null kalau gagal dimuat  
    "total\_pendapatan": 200000,        // null kalau gagal dimuat  
    "entries": \[  
      {  
        "id": 55,  
        "metode\_pembayaran": "tunai",  
        "keterangan": null,  
        "nominal": 50000,  
       "foto\_bukti\_url": "https://.../api/v1/bukti-setoran/2026-08-30/1\_tunai\_....jpg", // null kalau kpay  
        "is\_konfirmasi": false,  
        "nama\_karyawan": null,  
        "identitas\_karyawan": null  
      }  
    \],  
    "total\_setor": 50000,       // PERSONAL, punya user yang login doang (semua status, draft ikut)  
    "total\_setor\_loket": 100000, // GABUNGAN semua teller di loket+tanggal ini, HANYA yang berstatus submitted  
    "selisih": 100000            // null kalau pendapatan SOPP gagal dimuat  
  }  
}

### **Business-logic notes**

* **`selisih` dan `status_keuangan` dihitung dari `total_setor_loket`, bukan `total_setor`.** `total_setor_loket` menjumlahkan entry dari **semua teller** yang berbagi `loket_id + tanggal` yang sama, tapi **hanya row berstatus `submitted`** — draft (termasuk draft milik user yang sedang login sendiri) sengaja **tidak** ikut dihitung. Jadi:  
  * Draft milik teller lain di loket sama tidak pernah bikin `selisih` orang lain "kelihatan lunas palsu".  
  * Draft milik diri sendiri yang belum di-submit juga tidak ikut mengurangi `selisih` — walau `total_setor` (field personal) tetap menampilkan angka draft itu apa adanya (untuk keperluan prefill form).  
* Kalau fetch data SOPP dari bridge gagal (`BridgeException`), endpoint **tetap 200 OK**, bukan 500\. Field-field terkait SOPP jadi null (`pendapatan_sopp_status: "gagal_dimuat"`, `total_pendapatan: null`, `selisih: null`, `status_keuangan: null`, `fetched_at: null`) — sisanya (entries, total\_setor personal) tetap tampil normal.  
* `refresh=1` kena rate limit **10 detik** per kombinasi `user_id + loket_id + tanggal`. GET biasa tanpa `refresh` **tidak** kena limit ini sama sekali (bisa dipanggil berkali-kali bebas).  
* `foto_bukti_url` selalu lewat endpoint terautentikasi

### **Kode error**

| Status | Kapan | Sumber `errors` |
| :---- | :---- | :---- |
| 401 | Tidak ada sesi login | kosong, `message`: "Belum login atau sesi berakhir." |
| 403 | User tanpa `loket_id` sendiri dan tidak kirim `loket_id` eksplisit | kosong, `message`: "Akun ini tidak terhubung ke loket manapun." |
| 403 | Kirim `loket_id` eksplisit tanpa kapabilitas `bisa_input_setoran_semua_loket` (walau nilainya sama dengan loket sendiri) | kosong, `message` **generik tetap**: "Anda tidak memiliki akses untuk melakukan aksi ini." (lihat §0.1) |
| 422 | `loket_id` yang dikirim tidak terdaftar di tabel `lokets` | **terisi** per-field (`errors.loket_id`) |
| 422 | `tanggal` masa depan, atau format `tanggal` bukan `Y-m-d` | **terisi** per-field (`errors.tanggal`) |
| 429 | `refresh=1` dipanggil lagi dalam 10 detik dari panggilan `refresh=1` sebelumnya (key: user+loket+tanggal) | kosong, `message`: "Tunggu beberapa detik sebelum refresh lagi." |

---

## **2\. POST `/teller/setoran/draft` — simpan draft**

### **Mekanis**

* **Method/Path**: `POST /api/v1/teller/setoran/draft`  
* **Content-Type**: `multipart/form-data` (karena ada file upload per baris)  
* **Body**:

loket\_id?: integer            // opsional, lihat §0.2  
tanggal?: string (Y-m-d)      // opsional, lihat §0.3  
entries: array                // WAJIB ada key-nya, BOLEH kosong (\[\])  
entries\[i\].id?: integer       // ada \= update baris lama, tidak ada \= baris baru  
entries\[i\].metode\_pembayaran: "transfer"|"qris"|"tunai"|"kpay"   // wajib  
entries\[i\].nominal: integer   // wajib, minimal 1 (0 ditolak)  
entries\[i\].keterangan?: string  
entries\[i\].is\_konfirmasi?: boolean  
entries\[i\].foto\_bukti?: file  // aturan kondisional, lihat §0.6  
entries\[i\].nama\_karyawan?: string     // wajib kalau kpay, dilarang kalau bukan kpay  
entries\[i\].identitas\_karyawan?: string // wajib kalau kpay, dilarang kalau bukan kpay

* **Response 200**: sama persis shape-nya dengan response `GET index()` di atas (endpoint ini me-return ulang state terbaru setelah disimpan, bukan cuma "ok").

### **Business-logic notes**

* **`entries` boleh array kosong (`[]`)** — ini bukan error, ini cara sah teller mengosongkan seluruh draft-nya. Semua entry lama akan terhapus, row header (`setoran_harian`) tetap ada dengan status `draft` dan 0 entry. (Ini beda dari `submit`, lihat §3.)  
* **`id` yang dikirim harus benar-benar milik header `user_id + loket_id + tanggal` yang sedang diproses** — kalau `id` menunjuk entry milik user lain atau header lain, ditolak **422** dan seluruh batch di-rollback (transaksional, tidak ada partial-save).  
* **`id` yang sama tidak boleh muncul dua kali** dalam satu payload — ditolak `422` sebelum masuk transaksi apa pun (mencegah update ganda \+ log aktivitas duplikat). Aturan ini khusus untuk `id` yang terisi; beberapa baris baru tanpa `id` (semuanya `null`) tetap sah, bukan dianggap "duplikat".  
* **Konsistensi transaksional**: satu request \= satu transaksi DB. Kalau ada satu saja entry di batch yang gagal (id tidak valid, dsb), **seluruh batch di-rollback** — termasuk foto yang sempat tertulis fisik ke disk untuk entry lain di batch yang sama (misal entry pertama sukses upload foto, entry kedua di batch yang sama gagal validasi id → foto entry pertama ikut dihapus balik, tidak jadi file orphan). Entry lama yang "terhapus" lewat delete-by-omission dan foto lamanya juga tidak jadi benar-benar hilang kalau belakangan ada entry lain di batch yang sama gagal — physical delete file ditunda sampai transaksi benar-benar commit.  
* Simpan draft berkali-kali ke kombinasi user+loket+tanggal yang sama itu **normal** (bukan berarti error) — teller mengisi form sepanjang hari lewat beberapa kali klik "Simpan Draft".  
* Kalau row untuk kombinasi ini sudah `submitted` → **409**, lihat §0.5.

### **Kode error**

| Status | Kapan | Sumber `errors` |
| :---- | :---- | :---- |
| 401 | Tidak ada sesi login | kosong |
| 403 | Sama seperti §1 (resolusi loket\_id) | lihat §1 |
| 409 | Row untuk `user_id+loket_id+tanggal` ini sudah berstatus `submitted` | kosong, `message`: "Setoran tanggal ini sudah disubmit dan tidak bisa diubah lagi lewat halaman ini." |
| 422 | `loket_id` tidak terdaftar | **terisi** (`errors.loket_id`) |
| 422 | `tanggal` masa depan / format salah | **terisi** (`errors.tanggal`) |
| 422 | `metode_pembayaran` di luar enum (`transfer`/`qris`/`tunai`/`kpay`) | **terisi** (`errors["entries.N.metode_pembayaran"]`) |
| 422 | `nominal` ≤ 0 | **terisi** (`errors["entries.N.nominal"]`) |
| 422 | Foto tidak dikirim padahal wajib (baris baru non-kpay, atau baris pindah dari kpay ke metode lain) | **terisi** (`errors["entries.N.foto_bukti"]`) |
| 422 | Foto dikirim padahal dilarang (baris kpay) | **terisi** (`errors["entries.N.foto_bukti"]`) |
| 422 | Foto mime bukan jpg/jpeg/png | **terisi** |
| 422 | Foto lebih dari 5MB | **terisi** |
| 422 | `nama_karyawan`/`identitas_karyawan` tidak diisi padahal metode kpay | **terisi** (`errors["entries.N.nama_karyawan"]`, dst) |
| 422 | `nama_karyawan`/`identitas_karyawan` diisi padahal metode bukan kpay (termasuk kasus baris yang baru saja pindah metode dari kpay tapi field lama itu masih ikut terkirim) | **terisi**, sama field-nya |
| 422 | `id` yang dikirim tidak ditemukan / bukan milik header user+loket+tanggal ini | **kosong** — ini abort() manual, bukan validate(). `message`: "Salah satu entry tidak ditemukan atau bukan milik setoran ini." |
| 422 | `id` yang sama dikirim 2× dalam satu payload | **kosong** — abort() manual. `message`: "Satu entry (id) tidak boleh dikirim dua kali dalam satu request." |
| 429 | Klik dobel dalam 2 detik (key: user+loket+tanggal) | kosong, `message`: "Tunggu sebentar sebelum menyimpan lagi." |

> Catatan buat FE: dua baris terakhir (id tidak ditemukan / id duplikat) itu beda sumber dari baris-baris 422 lainnya di atasnya — jangan asumsikan semua 422 di endpoint ini punya `errors` per-field. Cek §0.1.

---

## **3\. POST `/teller/setoran/submit` — submit (kunci final)**

### **Mekanis**

* **Method/Path**: `POST /api/v1/teller/setoran/submit`  
* **Content-Type & Body**: identik dengan `draft` di atas (§2), bedanya hanya di validasi `entries` dan efek sampingnya.  
* **Response 200**: shape sama dengan `index()`, dengan `status_workflow: "submitted"`, `is_locked: true`.

### **Business-logic notes**

* Perbedaan utama dengan `draft`: **`entries` wajib berisi minimal 1 baris** (`required|array|min:1`) — submit dengan `entries: []` ditolak `422`. (Bandingkan dengan draft yang boleh kosong.)  
* **Tidak ada validasi "selisih harus nol"** sebelum bisa submit — teller boleh submit walau `selisih` masih besar; itu murni informasi, bukan gate.  
* `submitted_at` diisi **hanya kalau masih `null`** — submit ulang (kalau lolos, tapi lihat lock di bawah) tidak mereset waktu submit pertama. Namun secara praktik ini jarang relevan karena submit kedua ke row yang sama akan kena lock 409 duluan.  
* Setelah sukses, `status` berubah `draft → submitted` **satu kali**, dan sejak itu row terkunci read-only lewat Card A (§0.5) — submit lagi ke kombinasi yang sama → `409`.  
* Semua rule validasi per-baris (foto, kpay, batch, id ownership, dsb — §0.6–§0.9, dan tabel error di §2) berlaku identik di endpoint ini.

### **Kode error**

Sama seperti tabel error §2 (termasuk catatan sumber `errors`\-nya), ditambah:

| Status | Kapan | Sumber `errors` |
| :---- | :---- | :---- |
| 422 | `entries` kosong (`[]`) atau key `entries` tidak dikirim sama sekali saat submit — beda dari draft, di sini wajib minimal 1 baris | **terisi** (`errors.entries`) — ini via rule `required|array|min:1` di `validate()`, BUKAN abort manual |
| 422 | (guard tambahan, jarang kena karena rule di atas biasanya sudah menangkap duluan) hasil akhir batch di DB tetap 0 entry setelah semua create/update/delete diproses | **kosong** — ini abort() manual terpisah, `message`: "Tidak bisa submit tanpa entry sama sekali." |
| 409 | Row untuk `user_id+loket_id+tanggal` ini sudah `submitted` sebelumnya | kosong, pesan sama seperti §2 |

---

## **4\. GET `/teller/setoran/kalender` — status per tanggal (date-picker)**

### **Mekanis**

* **Method/Path**: `GET /api/v1/teller/setoran/kalender`  
* **Query params**:  
  * `loket_id` (opsional, integer) — lihat §0.2  
  * `bulan` (**wajib**, format `Y-m`, mis. `2026-08`)  
* **Response 200**:

{  
  "status": "ok",  
  "data": {  
    "bulan": "2026-08",  
    "kalender": \[  
      { "tanggal": "2026-08-01", "status": "belum\_input", "bisa\_diinput": true },  
      { "tanggal": "2026-08-15", "status": "draft", "bisa\_diinput": true },  
      { "tanggal": "2026-08-20", "status": "submitted", "bisa\_diinput": false }  
      // ... satu entry per tanggal, mencakup SELURUH hari dalam bulan yang diminta,  
      // termasuk hari yang belum pernah ada row sama sekali (status: "belum\_input")  
    \]  
  }  
}

### **Business-logic notes**

* **Endpoint ini murni baca data lokal** (`setoran_harian`) — **tidak** memanggil bridge/`SnapshotService` sama sekali. Jadi tidak ada info `selisih`/`ada_selisih` di sini secara sengaja; itu domain halaman Pelunasan (Card B), bukan Card A. (Dikonfirmasi eksplisit oleh test `test_ada_selisih_sengaja_tidak_ada_di_response`.)  
* **PENTING — scope-nya PERSONAL, bukan per-loket**: query di baliknya di-filter `user_id = user yang login` **DAN** `loket_id`, bukan cuma `loket_id` saja. Artinya kalender ini menjawab "status setoran SAYA di loket ini per tanggal", bukan "status loket ini secara keseluruhan per tanggal". Kalau Bu Wiwik buka kalender loket lain (`loket_id` eksplisit \+ kapabilitas), yang muncul cuma baris yang `user_id`\-nya dia sendiri di loket itu — bukan gabungan semua teller di loket tersebut. FE jangan salah pakai endpoint ini untuk menampilkan "status loket" ke semua orang; itu bukan tujuannya (konsisten dengan sifat Card A yang selalu personal, lihat §0.4).  
* `status` per tanggal: `belum_input` (tidak ada row) | `draft` | `submitted`.  
* `bisa_diinput` \= `true` kalau tanggal itu ≤ hari ini **DAN** statusnya bukan `submitted`. Artinya hari ini sendiri selalu `bisa_diinput: true` selama belum submitted (konsisten dengan §0.3 yang tidak melarang tanggal hari ini).  
* Response selalu mencakup satu object untuk **setiap** tanggal dalam bulan yang diminta (gap-filled, jumlahnya \= jumlah hari di bulan itu — mis. 31 untuk Agustus), bukan cuma tanggal yang punya row di DB — FE tidak perlu isi kekosongan sendiri.

### **Kode error**

| Status | Kapan | Sumber `errors` |
| :---- | :---- | :---- |
| 401 | Tidak ada sesi login | kosong |
| 403 | Sama seperti §1 (resolusi loket\_id) | lihat §1 |
| 422 | `loket_id` tidak terdaftar | **terisi** (`errors.loket_id`) |
| 422 | `bulan` tidak dikirim atau formatnya bukan `Y-m` | **terisi** (`errors.bulan`) |

> Urutan cek di controller: `loket_id` (resolusi \+ otorisasi) divalidasi **duluan**, baru `bulan`. Kalau dua-duanya salah sekaligus, yang muncul di response adalah error `loket_id` (403/422), bukan error `bulan`.

---

## **5\. Ringkasan status kode (semua endpoint Card A)**

| Kode | Arti umum di Card A |
| :---- | :---- |
| 200 | Sukses (termasuk kasus bridge gagal di `index()` — tetap 200 dengan field null) |
| 401 | Tidak ada sesi login valid |
| 403 | Pelanggaran kapabilitas (loket\_id lintas-cabang tanpa hak, atau akun tanpa loket\_id sendiri) |
| 409 | Row sudah `submitted` — Card A menolak menyentuhnya lagi (read-only lock) |
| 422 | Validasi input gagal — bisa dari `errors` terisi per-field (rule validate() biasa) ATAU `errors` kosong dengan detail di `message` (business-rule check manual seperti id tidak ditemukan/duplikat) — lihat §0.1 dan catatan di tiap endpoint |
| 429 | Rate limit — klik dobel simpan (2 detik) atau refresh dobel (10 detik) |

**Jangan asumsikan semua 422 punya bentuk yang sama** — itu koreksi paling penting dari pengecekan ulang ini. Selalu cek kolom "Sumber `errors`" di tabel tiap endpoint sebelum FE menulis logic parsing error generik untuk seluruh Card A.

# Card B Pelunasan

# **Kontrak API — Pelunasan Setoran Lama (Card B)**

## **0\. Konsep dasar (wajib dipahami sebelum baca detail endpoint)**

Card B **tidak punya tabel sendiri**. Entry yang dibuat di sini masuk ke tabel `setoran_detail` yang **sama persis** dengan Card A — bedanya cuma `setoran_harian_id` mana yang jadi induknya.

* **Multi-metode dalam 1 transaksi**: 1 request POST boleh berisi 1–4 baris metode pembayaran (`tunai`, `qris`, `transfer`, `kpay`), tiap metode **maksimal muncul 1x** per transaksi.  
* **Tidak ada draft** — POST langsung final, tidak ada status pending.  
* **1x transaksi selamanya per (user, loket, tanggal)** — begitu user pernah submit pelunasan untuk kombinasi loket+tanggal tertentu, dia tidak bisa submit lagi ke situ selamanya, walau partial. Sisa selisih (kalau ada) diselesaikan lewat halaman **Detail Setoran**, bukan retry di sini.  
* **"Tidak ada numpang" (desain final)**: entry pelunasan yang dibuat user **selalu** menempel ke row `setoran_harian` milik **user itu sendiri** — tidak pernah ke row teller lain. Row teller lain di loket+tanggal yang sama **hanya dibaca** (untuk syarat "loket ini sudah pernah ada yang submit"), **tidak pernah ditulis**.  
* Kalau user belum punya row `setoran_harian` untuk loket+tanggal yang dipilih, row itu **dibuat otomatis** saat POST (status langsung `submitted`, dengan flag `dibuat_via_bantuan_pelunasan = true` untuk audit trail). Ini hanya terjadi di POST, **tidak pernah di GET**.  
* Angka selisih itu **milik loket** (gabungan semua row `submitted` di loket+tanggal itu), bukan milik 1 row. Jadi status lunas/belum-lunas user A bisa berubah karena pembayaran user B di loket yang sama.

---

## **1\. Endpoint & Auth**

| \# | Method | Path | Middleware |
| :---- | :---- | :---- | :---- |
| 1 | GET | `/api/v1/teller/setoran/pelunasan` | `auth:sanctum` |
| 2 | POST | `/api/v1/teller/setoran/pelunasan` | `auth:sanctum` |

* Tanpa token/belum login → **401** (tidak ada body khusus, ini bawaan Sanctum).  
* Kedua endpoint dipakai untuk **2 mode sekaligus** (loket sendiri & loket lain) lewat mekanisme `resolveLoketId()` yang sama — **tidak ada percabangan endpoint terpisah** untuk "mode lintas loket".

### **Penentuan loket (`resolveLoketId()`) — berlaku sama untuk GET & POST**

1. Kalau **tidak ada** query param `?loket_id=` → pakai `loket_id` milik user yang login.  
   * Kalau akun ini `loket_id`\-nya `null` (tidak terhubung loket manapun) → **403**, `"Akun ini tidak terhubung ke loket manapun."`  
2. Kalau **ada** `?loket_id=`:  
   * Divalidasi dulu apakah `loket_id` itu benar-benar ada di tabel `lokets`. Kalau tidak ada → **422** (bukan 403\!). Urutan ini sengaja dibuat sama seperti Riwayat Setoran: biar FE bisa bedakan "loket memang tidak ada" (422) vs "loket ada tapi tidak punya akses" (403).  
   * Kalau `loket_id` valid → dicek kapabilitas **`bisa_input_setoran_semua_loket`**. Kalau `false` → **403**.  
   * **PENTING**: kapabilitas yang dipakai adalah `createForOtherLoket` (⇒ kolom `bisa_input_setoran_semua_loket`), **BUKAN** `bisa_edit_setoran_semua_loket`. Alasannya: membuat row baru di loket manapun secara harfiah adalah tindakan "submit/input", bukan "edit" — karena di desain ini tidak pernah ada aksi menulis ke row milik orang lain yang sudah final.  
   * **`is_superadmin` TIDAK otomatis bypass ability ini** — ini pengecualian sengaja dari bypass global superadmin di `Gate::before()`. Superadmin baru bisa akses loket lain kalau kolom `bisa_input_setoran_semua_loket` di akunnya memang di-set `true` lewat Hak Akses.  
3. Siapapun (tanpa kapabilitas apapun) boleh melakukan Pelunasan untuk **loket miliknya sendiri** — termasuk teller yang hari itu tidak pernah input transaksi lewat Card A sama sekali (dijelaskan lebih lanjut di §4).

---

## **2\. GET `/api/v1/teller/setoran/pelunasan`**

Endpoint ini **murni baca** — tidak pernah menulis row apapun ke DB, apapun query param-nya. Punya 2 mode berdasarkan ada/tidaknya `?tanggal=`.

### **2a. Tanpa `?tanggal=` — Daftar tanggal tersedia**

**Request**: `GET /api/v1/teller/setoran/pelunasan` atau `GET /api/v1/teller/setoran/pelunasan?loket_id={id}`

**Response 200**:

{  
  "status": "ok",  
  "data": {  
    "tanggal\_tersedia": \[  
      { "tanggal": "2026-08-20" },  
      { "tanggal": "2026-08-18" }  
    \]  
  }  
}

* Urutan **descending** (tanggal terbaru dulu).  
* Array **unik per tanggal** — walau ada 2+ row (2 teller berbeda) di loket+tanggal yang sama, tanggal itu cuma muncul 1x.  
* Field ini **secara sengaja TIDAK PERNAH** menyertakan `nama_teller` atau `setoran_harian_id` — siapa yang submit tidak relevan untuk keputusan pilih tanggal, dan tidak ada lagi konsep "row rujukan" yang perlu dikirim balik ke POST.  
* **Query database konstan** — tidak N+1 walau ada belasan tanggal eligible (diverifikasi test khusus).  
* **Endpoint ini tidak pernah memanggil bridge SOPP sama sekali** (diverifikasi test khusus) — murni query DB lokal, jadi tidak akan gagal/lambat gara-gara bridge down.

**Syarat 1 tanggal masuk daftar** (business rule inti — **bukan** cuma "punya row"):

* Loket+tanggal itu punya minimal 1 row `setoran_harian` berstatus **`submitted`** (row `draft` tidak dihitung sama sekali, **termasuk draft milik user yang sedang login sendiri**).  
* Row yang **soft-deleted** tidak dihitung.  
* Kalau row itu **belum pernah punya snapshot** (`snapshot_sopp === null`, artinya belum pernah "Muat Data") → **tetap muncul** di daftar (dianggap masih berpotensi ada selisih, karena belum diketahui).  
* Kalau row **sudah punya snapshot** → baru dihitung selisihnya: `total pendapatan SOPP (grand_total snapshot) − total setor gabungan semua row submitted di loket+tanggal itu`. Tanggal itu masuk daftar **hanya kalau selisih \> 0** (masih ada yang belum lunas).  
* **Tidak ada pengecualian "hari ini"** — kalau hari ini sudah submitted dan masih ada selisih, tanggal hari ini juga langsung muncul di daftar dan bisa langsung dilunasi hari itu juga.  
* Loket lain (bukan loket target) tidak ikut terhitung sama sekali.

### **2b. Dengan `?tanggal=YYYY-MM-DD` — "Muat Data" (force-refresh)**

**Request**: `GET /api/v1/teller/setoran/pelunasan?tanggal=2026-08-18` (bisa dikombinasikan dengan `?loket_id=` untuk loket lain)

**Perilaku penting**: endpoint ini **force-refresh** data dari bridge SOPP setiap kali dipanggil — **BUKAN** baca cache TTL biasa seperti Dashboard/Card A. Alasannya: submit Pelunasan cuma sekali tembak tanpa retry, jadi angka basi jauh lebih berbahaya di sini dibanding di halaman lain.

**Urutan pengecekan (penting untuk perilaku error yang benar)**:

Cek dulu murni ke DB (tanpa nembak bridge): apakah loket+tanggal ini punya minimal 1 row `submitted`? Kalau **tidak** → langsung **422** tanpa pernah menyentuh bridge sama sekali.  
 {  "status": "error",  "message": "Tanggal ini tidak memenuhi syarat pelunasan.",  "errors": {}}

1. Ini berlaku juga untuk tanggal yang row-nya sudah **soft-deleted**.  
2. Baru setelah lolos cek di atas → cek rate limit (lihat di bawah).  
3. Baru setelah lolos rate limit → panggil bridge (force refresh).

**Rate limit**: 1 request per 10 detik per kombinasi `loket_id + tanggal`. Kena limit → **429**, `"Tunggu beberapa detik sebelum refresh lagi."` Nilai 10 detik ini ada eksplisit di kode (`RateLimiter::attempt(..., 10)`), tapi ditandai lewat komentar di kode itu sendiri sebagai default yang dipakai karena wajar, bukan angka yang sudah resmi disepakati sebagai keputusan final — kalau FE/bisnis mau angka lain, ini yang perlu diubah.

**Kalau bridge gagal (exception `BridgeException`) DAN loket+tanggal ini belum pernah punya snapshot tersimpan sama sekali** → **502**, `"Gagal mengambil data terbaru dari bridge, coba lagi."`

**Kalau bridge gagal (exception) TAPI loket+tanggal ini pernah punya snapshot tersimpan sebelumnya** (dari fetch sukses kapan pun sebelumnya, walau sudah lama) → **BE tidak melempar error ke FE** — otomatis fallback pakai snapshot lama itu, tetap balas **200** dengan `data_terkini: false`. Yang perlu diperhatikan FE: `fetched_at` di response ini adalah **waktu snapshot lama itu diambil**, bukan waktu request sekarang — jadi FE bisa langsung tampilkan "data per {fetched\_at}" sebagai indikator ke user kalau datanya basi, tanpa perlu hitung sendiri. (Logic fallback ini murni terjadi di `SnapshotService`, dipakai bareng oleh Dashboard/Card A/Card B — bukan sesuatu yang spesifik ditulis ulang di `PelunasanController`.)

**Response 200 (sukses)**:

{  
  "status": "ok",  
  "data": {  
    "setoran\_harian\_id": 123,  
    "tanggal\_transaksi": "2026-08-18",  
    "sisa\_selisih": 200000,  
    "sudah\_lunas": false,  
    "data\_terkini": true,  
    "fetched\_at": "2026-08-18T10:00:00+07:00"  
  }  
}

Catatan per field:

* `setoran_harian_id` — dipilih dengan prioritas: **row milik user yang login sendiri** (kalau dia sudah punya satu untuk loket+tanggal ini), kalau belum ada → row `submitted` siapapun di loket+tanggal itu dengan `submitted_at` paling awal. **Angka `sisa_selisih` akan sama saja siapapun row yang dipilih**, karena selisih itu angka loket, bukan personal — field ini murni untuk kenyamanan referensi FE, bukan untuk dikirim balik ke POST (lihat §3, POST sudah tidak butuh field ini lagi).

  Catatan akurasi: kalau ada 2+ row **milik orang lain** (bukan milik user yang login) dengan `submitted_at` identik persis di loket+tanggal yang sama, kode **tidak** punya tie-break eksplisit (query-nya cuma `orderBy('submitted_at')`, tanpa `orderBy('id')` kedua) — mana yang kepilih di kasus itu bergantung ke perilaku database, bukan kontrak yang dijamin di level aplikasi. Praktiknya kasus 2 row identik `submitted_at` persis ini sangat jarang terjadi, tapi FE sebaiknya tidak mengasumsikan urutan tie-break tertentu.

* `sudah_lunas` — `true` kalau `sisa_selisih !== null && sisa_selisih <= 0`.

   Catatan akurasi — **beda operator dengan POST**: `sudah_lunas` di GET ini pakai `<= 0` (angka negatif dihitung lunas juga), sedangkan `status_keuangan` di response POST (§3.5) sumbernya `isLunas()` di model yang pakai `=== 0` (strict, cuma pas-pas saja yang dianggap lunas — kalau selisihnya negatif, `isLunas()` balikin `false`/ `"belum_lunas"`, bukan `"lunas"`). Praktiknya selisih negatif jarang terjadi (submit di POST sudah dicegah melebihi sisa selisih), tapi **bisa** terjadi kalau angka `grand_total` dari SOPP berubah/turun di antara 2 kali "Muat Data" setelah sebagian sudah disetor — jadi FE sebaiknya jangan asumsikan `sudah_lunas` (GET) dan `status_keuangan === "lunas"` (POST) selalu sinkron di kasus itu.

* **GET ini tidak pernah membuat row baru** walau user belum punya row sendiri untuk loket+tanggal ini — murni proyeksi/preview. (Ini pembenahan bug lama: dulu GET bisa nyangkutin row `submitted` kosong permanen kalau user cuma klik "lihat data" tanpa jadi bayar, dan akibatnya user itu terkunci selamanya dari submit Card A asli ke loket+tanggal itu karena kena unique constraint. Sekarang pembuatan row baru **hanya** terjadi di POST, lihat §4.)

---

## **3\. POST `/api/v1/teller/setoran/pelunasan`**

### **Request body**

{  
  "tanggal": "2026-08-18",  
  "metode\_pembayaran": \[  
    {  
      "metode": "tunai",  
      "nominal": 150000,  
      "keterangan": "opsional",  
      "bukti": "\<file gambar\>",  
      "is\_konfirmasi": false  
    },  
    {  
      "metode": "kpay",  
      "nominal": 50000,  
      "nama\_karyawan": "Budi",  
      "identitas\_karyawan": "12345"  
    }  
  \]  
}

Query param `?loket_id=` opsional, sama seperti GET (lihat §1) — kalau tidak diisi, defaultnya loket milik user sendiri, **bukan** diturunkan dari row manapun.

> Catatan penting untuk FE: **`setoran_harian_id` sudah tidak pernah dikirim di body POST sama sekali.** Yang dikirim FE cukup `loket_id` (lewat query param, opsional) \+ `tanggal` di body. BE yang menentukan sendiri row mana yang jadi induk entry (lihat §3.4).

### **3.1 Validasi field (422 kalau gagal, format standar Laravel**

`errors: {field: [pesan]}`)

| Field | Aturan |
| :---- | :---- |
| `tanggal` | wajib, format `Y-m-d` |
| `metode_pembayaran` | wajib, array, **min 1, max 4** baris |
| `metode_pembayaran.*.metode` | wajib, salah satu dari `transfer`, `qris`, `tunai`, `kpay` |
| `metode_pembayaran.*.nominal` | wajib, integer, **min 1** (nominal 0 ditolak) |
| `metode_pembayaran.*.keterangan` | opsional, string |
| `metode_pembayaran.*.bukti` | **wajib kecuali metode \= `kpay`**; kalau diisi harus file gambar `jpg/jpeg/png`, max 5MB |
| `metode_pembayaran.*.is_konfirmasi` | opsional, boolean |
| `metode_pembayaran.*.nama_karyawan` | **wajib kalau metode \= `kpay`**, dan **terlarang (prohibited)** kalau metode selain `kpay` |
| `metode_pembayaran.*.identitas_karyawan` | sama seperti `nama_karyawan` — wajib untuk `kpay`, terlarang untuk selain `kpay` |

Konsekuensi praktis dari baris terakhir: kalau user kirim `nama_karyawan`/`identitas_karyawan` di baris metode **non-kpay** (mis. `tunai`), request **ditolak 422** dengan validation error di kedua field itu — bukan diam-diam diabaikan.

**Validasi tambahan di luar rule Laravel** — setiap `metode` dalam 1 request **tidak boleh duplikat** (mis. 2 baris `tunai` dalam 1 transaksi yang sama):

{  
  "status": "error",  
  "message": "Tiap metode pembayaran cuma boleh muncul sekali per transaksi pelunasan.",  
  "errors": {}  
}

→ **422**.

### **3.2 Urutan eksekusi & efek samping penting**

1. `resolveLoketId()` dipanggil **paling awal**, sebelum upload file apapun — supaya request yang pasti gagal otorisasi tidak sempat upload file sia-sia.  
2. Validasi field (di atas).  
3. **File bukti disimpan ke disk DULU**, baru validasi bisnis (cek selisih, cek sudah pernah bayar, dll) dijalankan **setelah** file tersimpan. **Implikasi untuk FE**: kalau validasi bisnis gagal (contoh: nominal melebihi selisih), **file yang sudah sempat diupload akan otomatis dihapus lagi** oleh BE (baik gagal karena exception maupun gagal normal) — FE tidak perlu khawatir ada file bukti "nyangkut" di server untuk transaksi yang gagal.  
4. Baru semua validasi bisnis dijalankan di dalam 1 DB transaction (dengan `lockForUpdate()` di semua row loket+tanggal itu, retry otomatis 3x kalau ada deadlock) — lihat urutan pengecekan di §3.3.

### **3.3 Urutan pengecekan bisnis (di dalam transaction, semua row**

loket+tanggal itu sudah di-lock)

1. **Tentukan/buat row aktor** (lihat §3.4 detail). Kalau gagal di sini (loket+tanggal belum eligible sama sekali) → **422**, `"Tanggal ini tidak memenuhi syarat pelunasan."`  
2. Cek row aktor **statusnya harus `submitted`**. Kalau row aktor ternyata masih `draft` (bisa terjadi kalau row itu row lama milik aktor sendiri yang belum di-submit lewat Card A) → **422**, `"Setoran ini belum submitted, pelunasan cuma buat setoran yang sudah final."`  
3. Cek **user ini sudah pernah pelunasan untuk row ini sebelumnya** (dicek dari `ActivityLog` jenis `buat_pelunasan` milik user ini \+ `setoran_harian_id` row aktor — **bukan** dicek dari `setoran_detail`, karena tabel itu tidak punya kolom penanda "ini dari pelunasan"). Kalau sudah pernah → **422**, `"Kamu sudah pernah mengisi pelunasan untuk tanggal ini. Sisa selisih (jika ada) diselesaikan lewat Detail Setoran."`  
   * Ini dicek **per user**, bukan per loket+tanggal — teller lain yang sudah pernah bayar di loket+tanggal yang sama **tidak** menghalangi teller ini untuk pertama kalinya bayar (dan sebaliknya, punya row sendiri yang beda dari teller lain — lihat §3.4).  
4. Hitung `sisaSelisih = row->selisih()`. Kalau `null` (artinya `snapshot_sopp` belum pernah terisi — user belum pernah "Muat Data" sebelum submit) → **422** dengan `error_code: "selisih_belum_dimuat"`:  
    {  "status": "error",  "message": "Sisa selisih belum diketahui karena data belum pernah dimuat (Muat Data dulu sebelum submit pelunasan).",  "error\_code": "selisih\_belum\_dimuat",  "errors": {}}  
5. Kalau `total nominal semua baris > sisaSelisih` → **422** dengan `error_code: "nominal_melebihi_selisih"`:  
    {  "status": "error",  "message": "Total nominal tidak boleh melebihi sisa selisih.",  "error\_code": "nominal\_melebihi\_selisih",  "errors": {}}  
   * Total nominal **persis sama** dengan sisa selisih **diperbolehkan** (bukan strict `<`).  
   * Total nominal **kurang dari** sisa selisih **diperbolehkan** (partial payment) — sisanya nanti diselesaikan lewat Detail Setoran.  
6. Lolos semua → tiap baris `metode_pembayaran` dibuat sebagai 1 row `setoran_detail` baru (menempel ke row aktor), dan 1 `ActivityLog` `buat_pelunasan` dicatat per baris (jadi kalau kirim 2 metode, tercatat 2 log terpisah, masing-masing dengan `detail_perubahan` berisi `setoran_harian_id`, `metode_pembayaran`, `nominal`).

**Semua kode error di atas menggunakan status HTTP 422\.** Penting soal bentuk envelope-nya: **kunci `error_code` selalu ada di body**, isinya `null` untuk skenario yang belum diklasifikasi khusus, dan cuma diisi string untuk 2 skenario yang butuh penanganan UX berbeda di FE (`selisih_belum_dimuat`, `nominal_melebihi_selisih`). Jadi FE **jangan** cek pakai `if ('error_code' in response)` — itu selalu `true`. Cek pakai `response.error_code === 'selisih_belum_dimuat'` dst.

**Satu pengecualian**: skenario "metode pembayaran duplikat" (§3.1) itu ditolak **sebelum masuk transaction**, lewat jalur kode yang beda sama 5 skenario lain di atas — response-nya **betul-betul tidak punya kunci `error_code` sama sekali** (bukan `null`, tapi absen). Ini satu-satunya skenario 422 di POST yang envelope-nya beda bentuk.

### **3.4 Penentuan row aktor (`cariAtauBuatRowAktor()`) — jantung dari**

desain "tidak ada numpang"

1. Kalau user (aktor yang login) **sudah punya row** `setoran_harian` untuk loket+tanggal ini (apapun statusnya, draft atau submitted) → pakai row itu.  
2. Kalau **belum punya**:  
   * Syarat: loket+tanggal ini harus **sudah punya minimal 1 row `submitted` dari siapapun**. Kalau tidak ada sama sekali → **422**, `"Tanggal ini tidak memenuhi syarat pelunasan."` (row teller lain di sini murni **dibaca**, tidak pernah ditulis).  
   * Kalau syarat terpenuhi → row baru **dibuat otomatis** atas nama user ini sendiri: `status = submitted`, `submitted_at = now()`, `dibuat_via_bantuan_pelunasan = true`, dan `snapshot_sopp` **disalin** dari row lain di loket+tanggal itu yang sudah punya snapshot (bukan nembak bridge lagi — semua row di loket+tanggal yang sama seharusnya punya snapshot yang identik).  
   * **Siapapun boleh melakukan ini untuk loket miliknya sendiri, tanpa kapabilitas apapun.** Alasan bisnis: Card A punya aturan "0 transaksi tidak bisa disubmit", jadi teller yang hari itu memang tidak pegang transaksi tetap butuh jalan untuk ikut menutup selisih loketnya sendiri. Untuk loket **lain**, baru butuh kapabilitas `bisa_input_setoran_semua_loket` (lihat §1).  
   * **Race condition ditangani**: kalau 2 request bersamaan (mis. user double-klik submit atau buka 2 tab) sama-sama mencoba membuat row baru untuk kombinasi (user, loket, tanggal) yang sama, salah satu akan kena unique constraint DB — BE menangkap ini dan otomatis mengambil row yang barusan berhasil dibuat oleh request lain, **tidak** melempar error ke user.

**Implikasi penting untuk FE**: kalau ada 2 teller berbeda di loket yang sama melakukan Pelunasan di tanggal yang sama, masing-masing dapat `setoran_harian_id` yang **berbeda** (row masing-masing), bukan menumpuk ke 1 row yang sama.

### **3.5 Response sukses (200)**

{  
  "status": "ok",  
  "data": {  
    "setoran\_harian\_id": 123,  
    "tanggal\_transaksi": "2026-08-18",  
    "selisih": 0,  
    "status\_keuangan": "lunas",  
    "total\_setor": 500000,  
    "entries": \[  
      {  
        "id": 45,  
        "metode\_pembayaran": "tunai",  
        "keterangan": null,  
        "nominal": 150000,  
        "foto\_bukti\_url": "https://domain.tld/api/v1/bukti-setoran/2026/08/xxxx.jpg",  
        "is\_konfirmasi": false,  
        "nama\_karyawan": null,  
        "identitas\_karyawan": null  
      },  
      {  
        "id": 46,  
        "metode\_pembayaran": "kpay",  
        "keterangan": null,  
        "nominal": 50000,  
        "foto\_bukti\_url": null,  
        "is\_konfirmasi": false,  
        "nama\_karyawan": "Budi",  
        "identitas\_karyawan": "12345"  
      }  
    \]  
  }  
}

Catatan per field (semua dihitung **setelah** entry baru tersimpan):

* `setoran_harian_id` — id row aktor (lihat §3.4), bukan lagi row rujukan dari input FE.  
* `selisih` — **sudah menghitung entry yang baru saja dibuat**, dan **gabungan seluruh loket** (semua row `submitted` di loket+tanggal itu, bukan cuma row aktor). Kalau partial payment, angka ini \> 0\.  
* `status_keuangan` — `"lunas"` (selisih \= 0), `"belum_lunas"` (selisih 0), atau `"gagal_dimuat"` (kasus edge: `snapshot_sopp` ternyata `null` — secara teori tidak akan terjadi di titik ini karena sudah lolos cek `selisih_belum_dimuat` di atas, tapi tetap ditangani di response).  
* `total_setor` — total setor **gabungan seluruh loket** untuk tanggal itu (semua row `submitted`, bukan cuma row aktor) **setelah** entry baru ini masuk. Kalau ada teller lain yang sudah setor duluan di loket+tanggal yang sama, angka ini termasuk kontribusi mereka juga — **bukan** cuma nominal yang baru saja di-submit di request ini.  
* `entries[].foto_bukti_url` — URL absolut siap-pakai ke endpoint serve foto (`GET /api/v1/bukti-setoran/{path}`), atau `null` kalau baris itu metode `kpay` (tidak wajib foto). FE **tidak perlu** bikin URL sendiri dari path manapun — field ini sudah final, tinggal dipasang ke `<img>`.  
* `entries` — **hanya breakdown dari request POST ini saja** (bukan seluruh histori row aktor), sesuai urutan baris yang dikirim di request.

### **3.6 Ringkasan kode error POST (selain validasi field 422 di §3.1)**

| Skenario | Status | `error_code` |
| :---- | :---- | :---- |
| Belum login | 401 | *(tidak relevan)* |
| Akun tanpa loket (dan tidak isi `?loket_id=`) | 403 | *(tidak relevan)* |
| `?loket_id=` menunjuk loket yang tidak terdaftar | 422 | *(tidak relevan — respons validasi Laravel biasa)* |
| `?loket_id=` ke loket lain tanpa kapabilitas `bisa_input_setoran_semua_loket` | 403 | *(tidak relevan)* |
| Metode pembayaran duplikat dalam 1 request | 422 | **kunci absen** (bukan `null` — lihat catatan di atas) |
| Loket+tanggal ini belum pernah ada row `submitted` sama sekali (dan aktor juga belum punya row) | 422 | `null` |
| Row aktor untuk loket+tanggal ini statusnya masih `draft` | 422 | `null` |
| User ini sudah pernah pelunasan untuk row aktornya di loket+tanggal ini | 422 | `null` |
| `snapshot_sopp` row aktor belum pernah dimuat | 422 | `"selisih_belum_dimuat"` |
| Total nominal melebihi sisa selisih | 422 | `"nominal_melebihi_selisih"` |

---

## **4\. Ringkasan aturan otorisasi (gabungan GET \+ POST)**

| Siapa | Loket sendiri | Loket lain (`?loket_id=`) |
| :---- | :---- | :---- |
| Teller biasa (tanpa kapabilitas apapun) | ✅ boleh, tanpa syarat tambahan | ❌ 403 |
| Teller dengan `bisa_input_setoran_semua_loket = true` | ✅ boleh | ✅ boleh |
| Superadmin (`is_superadmin = true`) TANPA `bisa_input_setoran_semua_loket` | ✅ boleh (karena tetap "loket sendiri") | ❌ **403** — superadmin **tidak** otomatis bypass ability lintas-loket ini |
| Akun tanpa `loket_id` sama sekali | ❌ 403 di semua kasus | ❌ 403 |

---

## **5\. Hal-hal yang sengaja SAMA antara GET dan POST** 

* Sumber daftar tanggal eligible (`hitungTanggalEligible()`) dipakai identik oleh mode "tanggal tersedia" (GET tanpa `?tanggal=`) — tidak ada logic terpisah untuk "loket sendiri" vs "loket lain".  
* Aturan `?loket_id=` tidak terdaftar → 422 sebelum cek kapabilitas berlaku **sama** di GET maupun POST.  
* `resolveLoketId()` dipanggil di titik paling awal di kedua endpoint.

## **6\. Catatan untuk FE (bukan gap — ini memang begitu adanya di kode)**

* Dari 6 kemungkinan skenario 422 di POST (§3.6), **cuma 2** yang punya `error_code` dengan nilai spesifik (`selisih_belum_dimuat`, `nominal_melebihi_selisih`). 3 skenario lainnya (tanggal tidak eligible, row belum submitted, sudah pernah pelunasan) tetap punya kunci `error_code` di body-nya, cuma nilainya `null` — FE yang mau membedakan 3 skenario itu secara programatik untuk sekarang harus parsing `message` string. 1 skenario sisanya (metode duplikat) malah **tidak** punya kunci `error_code` sama sekali (envelope beda sendiri, lihat §3.3). Ini fakta final dari kode saat ini, bukan sesuatu yang belum diputuskan — kalau FE butuh kode mesin juga untuk skenario yang masih `null`/absen itu, itu permintaan perubahan baru ke BE, bukan koreksi dokumen ini.  
* Window rate limit 10 detik (§2b) sudah aktif dan berlaku di kode sekarang — cuma perlu diketahui FE bahwa angka ini masih bisa berubah kalau bisnis minta lain, karena statusnya di kode ditandai "default wajar" bukan "hasil keputusan resmi".  
* Di §2b ada 1 guard defensif ekstra: kalau race super sempit bikin row yang tadinya eligible keburu soft-delete di antara 2 query internal, GET akan balikin 422 pesan yang sama (`"Tanggal ini tidak memenuhi syarat pelunasan."`) walau secara teori jarang sekali kejadian. Efek ke FE sama persis dengan skenario tanggal tidak eligible biasa, jadi tidak perlu ditangani beda.

# Riwayat Setoran

# **Kontrak API — Riwayat Setoran**

---

## **⚠️ PERUBAHAN BESAR DARI VERSI LAMA — WAJIB DIBACA FE**

Kalau FE pernah pegang dokumen kontrak Riwayat Setoran versi sebelumnya, ini bagian yang **berubah total**, bukan cuma detail kecil:

1. **`selisih`, `status_keuangan`, `total_pendapatan` sekarang PERSONAL, bukan gabungan loket.** Dulu: dihitung dari `SetoranHarian::selisih()`/`isLunas()`, gabungan semua teller submitted di loket+tanggal yang sama, baca kolom `snapshot_sopp` yang di-cache (gratis, tanpa network call). Sekarang: dihitung murni per-teller (`SelisihPerUserService`), live-fetch ke `bridge.php` tiap kali endpoint dipanggil. Formulanya: ambil semua baris data bridge yang `user_id`\-nya cocok `user_id_sopp` teller ini **dan** tanggalnya sama dengan `tanggal_transaksi` baris ini, lalu `selisih_sendiri = total_pendapatan_bridge - row->totalSetor()`. **Konsekuensi penting:** formula ini sekarang **tidak peduli status\_workflow row itu sendiri** — draft dan submitted dihitung dengan rumus yang sama persis (beda dari dulu, di mana draft tidak pernah ikut dihitung ke agregat manapun). Yang tetap murni "punya baris ini sendiri", bukan gabungan lintas-teller lagi.  
2. **`gagal_dimuat` sekarang punya 2 sumber independen**, bukan cuma 1:  
   * Fetch ke bridge gagal (`BridgeException`) — sama seperti dulu.  
   * **Baru:** user yang login bukan "teller SOPP" (`isTellerSopp()` false). Definisi persisnya dari `User::isTellerSopp()`: `! is_superadmin && ! is_null(user_id_sopp)` — **dua syarat sekaligus**, bukan cuma cek `user_id_sopp` null. Kasus paling umum: superadmin generik (`is_superadmin = true`, `user_id_sopp` memang null karena dibuat manual lewat seeder, independen dari SOPP lama). Fetch bahkan tidak pernah dicoba, langsung `gagal_dimuat`.  
3. **Guard performa berubah dari 90 hari → 30 hari**, dan syarat pemicunya berubah total:  
   * `index()`: dulu trigger-nya *mode lintas-loket* **DAN** `status_keuangan` diisi **DAN** tanpa `tanggal_mulai`. Sekarang trigger-nya cuma *`status_keuangan` diisi* **DAN** tanpa `tanggal_mulai` — **mode tidak lagi relevan**. Mode personal sekarang **ikut kena** guard ini (dulu tidak pernah).  
   * `export()`: dulu cuma kena kalau lintas-loket \+ `status_keuangan` diisi. Sekarang kena **setiap kali `tanggal_mulai` tidak dikirim**, titik — tidak peduli mode, tidak peduli `status_keuangan` diisi atau tidak.  
4. **Baris export yang gagal fetch TIDAK LAGI di-skip dari file.** Dulu baris itu hilang sama sekali dari tabel, cuma disebut lewat footnote "N hari tidak disertakan". Sekarang baris **tetap ditulis apa adanya** (tanggal \+ metode pembayaran \+ status workflow tetap keisi normal), cuma kolom Total Pendapatan & Selisih ditandai teks `"Gagal dimuat"` dan dikecualikan dari perhitungan TOTAL SEMUA. Teks footnote juga berubah redaksinya (lihat §3).  
5. **Rate limit export 10x/menit betulan aktif** (route `throttle:10,1` \+ ada test yang menegaskan 429 di request ke-11) — kalau ada catatan lama yang bilang ini "belum diputuskan", itu sudah tidak akurat, implementasinya sudah jalan di kode final.

Sisanya (mekanika `destroy()`, aturan wajib alasan, guard kapabilitas, struktur umum response, activity log) **tidak berubah** dari versi lama — detail lengkapnya tetap ditulis di bawah supaya dokumen ini berdiri sendiri.

---

## **0\. Konsep dasar**

### **Dua mode akses index & export, ditentukan otomatis dari ada/tidaknya `loket_id`**

|  | Mode Personal (default) | Mode Lintas-Loket (expanded) |
| :---- | :---- | :---- |
| Dipicu oleh | `loket_id` tidak dikirim | `loket_id` dikirim (angka valid) |
| Siapa yang bisa pakai | Semua role login | Wajib `bisa_edit_setoran_semua_loket = true` |
| Baris yang muncul | Semua baris milik `user_id` \= user login (loket manapun), **draft \+ submitted** | Semua baris di `loket_id` tsb milik siapa saja, **hanya `submitted`** |
| Draft orang lain | N/A (semua baris pasti milik sendiri) | Tidak pernah muncul — termasuk draft milik user yang login sendiri kalau dia sedang lihat mode ini |
| Field `nama_loket` per-item | Ada | Tidak ada (satu filter `loket_id` berlaku ke semua baris, cukup ditampilkan sekali di FE) |
| Field `nama_teller` per-item | Tidak ada | Ada |
| Activity log ditulis? | Tidak pernah | Ya (lihat §4) |

### **Kapabilitas `bisa_edit_setoran_semua_loket`**

`Gate::before()` global (bypass superadmin) **sengaja tidak berlaku** untuk ability `updateForOtherLoket` — dikonfirmasi dari `AppServiceProvider` yang mendaftarkan ability ini ke daftar `ABILITY_LINTAS_LOKET` yang dikecualikan dari bypass. Kapabilitas harus di-grant eksplisit lewat halaman Hak Akses, walau usernya superadmin. **Jangan asumsikan "role tinggi \= pasti bisa lihat lintas-loket."**

### **`ringkasan` selalu personal ke user yang login**

Terlepas dari mode apa yang sedang dilihat. Kalau verifikator sedang membuka mode lintas-loket loket orang lain, angka `ringkasan` yang dikirim tetap statistik verifikator itu sendiri (dikonfirmasi test `test_ringkasan_tetap_personal_walau_di_mode_expanded`).

### **Hapus baris submitted — termasuk milik sendiri**

Guard `DELETE` untuk baris submitted **tidak mengecualikan pemiliknya sendiri**. Siapapun yang menghapus baris submitted wajib (a) mengisi alasan dan (b) punya kapabilitas `bisa_edit_setoran_semua_loket`. Teller biasa tidak akan pernah bisa menghapus setorannya sendiri begitu sudah submit walaupun dia pemiliknya. Field `bisa_dihapus` di response index/export sudah mencerminkan aturan ini secara akurat.

---

## **1\. GET `/api/v1/teller/setoran/riwayat`**

### **Mekanis**

* **Method & Path:** `GET /api/v1/teller/setoran/riwayat`  
* **Auth:** wajib login (`auth:sanctum`, cookie session). Tanpa sesi valid → `401`.  
* **Rate limit:** `throttle:60,1` (60 request/menit). Melebihi → `429`.

### **Request — Query Parameters**

| Field | Tipe | Wajib | Validasi |
| :---- | :---- | :---- | :---- |
| `tanggal_mulai` | string tanggal | tidak | `date_format:Y-m-d` |
| `tanggal_akhir` | string tanggal | tidak | `date_format:Y-m-d`, `after_or_equal:tanggal_mulai` |
| `status_workflow` | string | tidak | hanya `draft` atau `submitted` (`belum_input` BUKAN nilai valid → `422`) |
| `status_keuangan` | string | tidak | hanya `lunas`, `belum_lunas`, atau `gagal_dimuat` (persis 3 nilai ini, underscore bukan strip — `belum-lunas` ditolak `422`) |
| `loket_id` | integer | tidak | harus ada di tabel `lokets` kolom `loket_id` — tidak ditemukan → `422` (bukan 403/404). Kehadiran field ini men-trigger mode lintas-loket |
| `page` | integer | tidak | minimal 1, default 1 |

Semua validasi gagal (kecuali gate kapabilitas) → `422`. **Bentuk body-nya BUKAN format default Laravel** — lihat §5 untuk envelope error yang sebenarnya dipakai di seluruh API ini.

### **Response — per skenario**

**Sukses — `200 OK`:**

{  
  "status": "ok",  
  "data": {  
    "items": \[  
      {  
        "id": 123,  
        "tanggal\_transaksi": "2026-08-10",  
        "total\_pendapatan": 500000,  
        "nominal\_transfer": 100000,  
        "nominal\_qris": 150000,  
        "nominal\_tunai": 200000,  
        "nominal\_kpay": 50000,  
        "selisih": 0,  
        "status\_keuangan": "lunas",  
        "status\_workflow": "submitted",  
        "bisa\_dihapus": false,  
        "dibuat\_via\_bantuan\_pelunasan": false,  
        "nama\_loket": "Loket A"  
      }  
    \],  
    "meta": {  
      "current\_page": 1,  
      "per\_page": 8,  
      "total\_items": 1,  
      "total\_pages": 1  
    },  
    "ringkasan": {  
      "total\_setoran\_lunas": 500000,  
      "persentase\_vs\_bulan\_lalu": 12.5,  
      "total\_entry\_bulan\_ini": 20,  
      "rata\_rata\_entry\_per\_hari": 1.0,  
      "pending\_selisih": 40000,  
      "ada\_data\_belum\_lengkap": false  
    }  
  }  
}

Catatan field per-item:

* Mode lintas-loket → item punya `nama_teller` (string), **tidak** punya `nama_loket`.  
* Mode personal → item punya `nama_loket` (string, fallback `"-"` kalau data Loket sudah dihapus dari DB — hard-delete Loket aman, tidak 500), **tidak** punya `nama_teller`.  
* `dibuat_via_bantuan_pelunasan` selalu ada, di kedua mode.  
* `total_pendapatan` dan `selisih` bernilai `null` kalau `status_keuangan = "gagal_dimuat"` — bukan error 500, sinyal data belum berhasil dicocokkan.  
* Kalau belum pernah ada setoran: `items: []`, `meta.total_items: 0`, `meta.total_pages` tetap `1` (dibulatkan minimal 1 di kode: `max(ceil(total/8), 1)`), `ringkasan` tetap ada dengan angka nol/null wajar.

**Gagal validasi — `422 Unprocessable Entity`:**

* `tanggal_akhir` sebelum `tanggal_mulai`.  
* `status_workflow` selain `draft`/`submitted` (termasuk `belum_input`).  
* `status_keuangan` selain 3 nilai valid (termasuk ejaan strip `belum-lunas`).  
* `loket_id` dikirim tapi tidak terdaftar di tabel `lokets`.

**Ditolak kapabilitas — `403 Forbidden`:**

* `loket_id` dikirim (valid), tapi user login tidak punya `bisa_edit_setoran_semua_loket`.  
* Ditolak **sebelum** activity log ditulis (lihat §4) — tidak meninggalkan jejak log.

**Belum login — `401 Unauthorized`.**

**Rate limit — `429 Too Many Requests`:** lebih dari 60 request dalam 1 menit.

*(Bentuk body untuk 422/403/401/429 di atas semuanya mengikuti 1 envelope error yang sama di seluruh API — lihat §5, jangan asumsikan format default Laravel.)*

### **Business rules — kondisi yang tidak kelihatan dari tipe data**

1. **Urutan & default tanpa filter tanggal:** selalu `orderByDesc('tanggal_transaksi')`. Tanpa filter tanggal apapun, yang muncul adalah entri terbaru apa adanya — bisa lintas bulan. Ini bukan "reset ke bulan berjalan".

2. **Formula `selisih`/`status_keuangan`/`total_pendapatan` — PERSONAL, live-fetch, per (teller, tanggal):**

   * Kalau user bukan teller SOPP (`user->isTellerSopp()` false — persisnya: `! is_superadmin && ! is_null(user_id_sopp)`, jadi gagal kalau salah satu syarat itu tidak terpenuhi, bukan cuma kalau `user_id_sopp` null) → langsung `gagal_dimuat`, tidak ada percobaan fetch sama sekali.  
   * Kalau iya, sistem fetch data bridge untuk rentang tanggal baris-baris yang sedang diambil (di-batch per `loket_id`, bukan 1x per baris), lalu ambil baris data bridge yang `user_id`\-nya cocok `user_id_sopp` teller ini **dan** tanggalnya sama dengan `tanggal_transaksi` baris ini.  
   * `total_pendapatan` \= jumlah `total` dari baris bridge yang cocok itu.  
   * `selisih` (`selisih_sendiri`) \= `total_pendapatan - totalSetor()` baris ini.  
   * `status_keuangan`: `null` (fetch gagal) → `gagal_dimuat`; `selisih === 0` → `lunas`; selainnya → `belum_lunas`.  
   * Kalau fetch ke bridge gagal (`BridgeException`) untuk suatu `loket_id`, **semua baris di loket itu** dalam response yang sama ikut jadi `gagal_dimuat` — baris di loket lain dalam response yang sama, kalau fetch-nya sukses, tidak ikut terdampak (gagal/sukses ditentukan per grup `loket_id`, bukan per baris atau per seluruh response).  
   * **Formula ini tidak peduli `status_workflow` baris itu sendiri** — draft dan submitted dihitung dengan rumus yang sama persis, keduanya bisa punya `selisih`/`status_keuangan` non-null. (Beda dari sebelumnya — ini bukan lagi agregat gabungan-loket yang mengecualikan draft.)  
3. **Guard performa 30 hari (index):** kalau `status_keuangan` diisi **DAN** `tanggal_mulai` **TIDAK** dikirim → hasil otomatis dibatasi ke 30 hari terakhir, dipotong diam-diam tanpa error. Ini **berlaku ke kedua mode** (personal maupun lintas-loket) — tidak ada pengecualian mode. Kalau `status_keuangan` tidak diisi, guard ini tidak pernah dievaluasi sama sekali, apapun mode-nya.

   * Anchor cap dihitung dari `tanggal_akhir` **kalau dikirim** (bukan selalu dari "sekarang") — kalau FE kirim `tanggal_akhir` yang jauh di masa lalu tanpa `tanggal_mulai`, cap-nya tetap dihitung mundur 30 hari dari `tanggal_akhir` itu, bukan dari hari ini.  
4. **3 state `status_keuangan`:** `gagal_dimuat` (fetch gagal atau user bukan teller SOPP — beda arti dari "belum lunas"), `lunas`, `belum_lunas`. `gagal_dimuat` adalah nilai filter yang sah, bukan error.

5. **`ringkasan` selalu personal**, bahkan saat mode lintas-loket sedang dibuka (lihat §0). Dihitung dari row-set "submitted, bulan berjalan" milik user login — 1x fetch bridge dipakai bareng untuk `total_setoran_lunas`, `pending_selisih`, dan `ada_data_belum_lengkap` sekaligus (row-set-nya persis sama).

   * `ringkasan.total_setoran_lunas` \= jumlah `totalSetor()` dari baris **submitted** bulan berjalan yang `selisih_sendiri === 0` — draft **tidak pernah** ikut dihitung.  
   * `ringkasan.total_entry_bulan_ini` \= jumlah **dokumen** `setoran_harian` submitted bulan berjalan, bukan jumlah baris transaksi/entry individual di dalamnya.  
   * `ringkasan.rata_rata_entry_per_hari` \= `total_entry_bulan_ini` ÷ jumlah hari yang sudah berjalan di bulan ini (tanggal hari ini), dibulatkan 1 desimal.  
   * `ringkasan.persentase_vs_bulan_lalu` \= `null` kalau total bulan lalu nol (hindari pembagian dengan nol) — jangan diasumsikan `0%`. Perbandingan bulan lalu memakai rentang tanggal yang sepadan (`hari ke-1` s/d `hari-ke-N` bulan lalu, N \= hari berjalan bulan ini, dibatasi akhir bulan lalu kalau lebih pendek).  
   * `ringkasan.ada_data_belum_lengkap = true` kalau ada minimal 1 baris submitted bulan berjalan yang `selisih_sendiri`\-nya `null` (fetch gagal) — dikeluarkan dari `total_setoran_lunas`, bukan dihitung `belum_lunas`.  
   * `pending_selisih` \= jumlah `abs(selisih_sendiri)` dari semua baris submitted bulan berjalan yang selisihnya bukan `0` dan bukan `null`. Otomatis berkurang begitu ada entry Pelunasan nempel ke baris yang sama (`totalSetor()` naik → `selisih_sendiri` turun) — tidak butuh logic tambahan apapun di Riwayat.  
   * **Fetch bridge untuk `ringkasan` tidak dibatasi guard 30 hari** — cakupannya memang sudah alami dibatasi ke bulan berjalan (maksimal ±31 hari), terpisah dari query `items`/filter di atas.  
6. **Field `nama_loket` fallback `"-"`** kalau data Loket terkait sudah dihapus dari database — tidak error 500\.

7. **`bisa_dihapus = true`** kalau baris `draft` ATAU user login punya `bisa_edit_setoran_semua_loket` — cocok persis dengan guard yang benar-benar dijalankan di `DELETE` (lihat §2), jadi FE bisa pakai flag ini dengan percaya diri untuk tampil/tidaknya tombol hapus.

8. Klik baris `draft` di UI Riwayat → Card A (mode edit-draft). Klik baris `submitted` → Detail Setoran. (Keputusan navigasi FE, disebut di sini supaya konsisten dengan makna `status_workflow`.)

---

## **2\. DELETE `/api/v1/teller/setoran/riwayat/{id}`**

### **Mekanis**

* **Method & Path:** `DELETE /api/v1/teller/setoran/riwayat/{id}` — `{id}` \= id baris `setoran_harian`.  
* **Auth:** wajib login. Tanpa sesi → `401`.  
* **Rate limit:** **tidak ada** throttle khusus di endpoint ini (dikonfirmasi dari `routes/api.php` — satu-satunya dari 3 endpoint chunk ini tanpa middleware `throttle`).  
* Catatan teknis: slot `{id}` ini terpisah dari `{id}` milik Detail Setoran (`GET/PUT /teller/setoran/{id}`) — path-nya beda (`/riwayat/{id}` vs `/{id}` polos, dan di `routes/api.php` literal `/riwayat/...` didaftarkan sebelum wildcard `/{id}`), jadi aman dipakai independen.

### **Request body**

| Field | Tipe | Wajib | Validasi |
| :---- | :---- | :---- | :---- |
| `alasan` | string | kondisional (lihat business rule \#1) | `max:500` |

### **Response — per skenario**

**Sukses — `200 OK`:**

{ "status": "ok", "data": null }

**Alasan wajib tapi tidak dikirim — `422 Unprocessable Entity`:**

{  
  "status": "error",  
  "message": "Alasan wajib diisi untuk menghapus setoran yang sudah submitted dan berisi entry.",  
  "errors": { "alasan": \["Alasan wajib diisi."\] }  
}

Muncul untuk baris submitted apapun — termasuk milik sendiri — yang tidak menyertakan alasan. Dicek **sebelum** pengecekan kapabilitas apapun.

**Ditolak kapabilitas — `403 Forbidden`:** baris submitted, alasan sudah diisi, tapi user login tidak punya `bisa_edit_setoran_semua_loket` — berlaku untuk siapapun, termasuk pemilik baris itu sendiri.

**Tidak ditemukan / disamarkan — `404 Not Found`:**

* `id` benar-benar tidak ada di database (`SetoranHarian::findOrFail($id)` gagal).  
* ATAU baris itu ada tapi berstatus `draft` **milik orang lain** (`abort(404)` eksplisit) — sengaja dibalas `404` (bukan `403`) supaya non-pemilik tidak tahu draft orang lain itu eksis sama sekali.

⚠️ **Kedua skenario di atas sama-sama `404`, TAPI teks `message`\-nya BEDA** (lihat §5 untuk mekanisme lengkapnya) — `id` tidak ditemukan di database menghasilkan `"Data tidak ditemukan."`, sedangkan draft milik orang lain menghasilkan `"Request tidak dapat diproses."` (pesan fallback generik). Ini murni akibat cara kode menolaknya beda (`findOrFail()` vs `abort(404)` polos tanpa pesan), bukan business rule yang disengaja untuk dibedakan pesannya — **tidak ada test yang menegaskan teks pesan ini**, jadi kalau FE menampilkan `message` mentah ke user, hasilnya tidak konsisten antar 2 skenario 404 yang sama-sama valid.

**Belum login — `401 Unauthorized`.**

**CSRF token tidak cocok/kadaluarsa — `419`:** khusus untuk request `DELETE` (method "penulis", bukan "pembaca") — lihat §5 untuk detail lengkap kenapa ini cuma relevan di endpoint ini, tidak di `index()`/`export()`.

⚠️ **Gap teknis terpisah, di luar business rule:** rute `DELETE /riwayat/{id}` **tidak** punya batasan `whereNumber('id')` di `routes/api.php` (beda dari rute Detail Setoran `/teller/setoran/{id}` yang punya). Kalau `{id}` yang dikirim bukan angka (mis. `/riwayat/abc`), Laravel gagal meng-cast ke parameter `int $id` di method controller dan menghasilkan error tak tertangani yang jatuh ke fallback handler → **`500 Internal Server Error`**, BUKAN `404`/`422` yang mungkin diasumsikan FE. Ini celah routing, bukan sesuatu yang FE perlu handle sebagai respons "normal" — layak diteruskan ke tim backend.

### **Business rules**

1. **Guard ditentukan MURNI oleh `status_workflow` baris itu** — bukan oleh siapa pemiliknya, bukan oleh jumlah entry di dalamnya:  
   * Baris `draft` → hanya pemiliknya sendiri yang bisa menyentuh. Pemilik boleh hapus langsung, **tanpa alasan, tanpa kapabilitas apapun**. Bukan pemilik → selalu `404`, tidak peduli kapabilitas apapun.  
   * Baris `submitted` → siapapun yang menghapus, **termasuk pemilik baris itu sendiri**, WAJIB (a) mengisi alasan DAN (b) punya `bisa_edit_setoran_semua_loket`. Tidak ada pengecualian pemilik di jalur ini. Teller biasa (tanpa kapabilitas ini) tidak akan pernah bisa menghapus setorannya sendiri begitu sudah submit.  
2. **Urutan pengecekan untuk baris submitted:** alasan dicek lebih dulu (`422` kalau kosong), baru setelah itu kapabilitas dicek (`403` kalau tidak punya) — supaya siapapun yang lupa isi alasan selalu dapat `422` yang konsisten, tidak buru-buru kena `403` cuma karena kebetulan juga tidak punya kapabilitas.  
3. **Jumlah entry di baris submitted (0 atau banyak) TIDAK mempengaruhi keputusan apapun** di endpoint ini — tidak ada pengecekan berbasis jumlah entry di kode.  
4. **Field `bisa_dihapus`** di response index/export sudah akurat mencerminkan aturan \#1 — bisa dipercaya sebagai prediksi hasil DELETE.  
5. **Efek hapus di database:** semua `setoran_detail` (entry anak) beserta file foto bukti fisiknya dihapus permanen (hard delete) lebih dulu, baru baris header `setoran_harian` di-soft-delete. Dijalankan dalam satu transaksi DB dengan row lock (`lockForUpdate`), mencegah race condition kalau ada request lain menyentuh baris yang sama bersamaan.  
6. **Selalu tercatat ke activity log** (jenis `setoran_dihapus`) untuk mode manapun (pemilik atau non-pemilik) — hanya ditulis kalau hapus benar-benar sukses (dijalankan di dalam blok transaksi, setelah delete berhasil). Detail yang dicatat: `tanggal_transaksi`, `loket_id`, `status_saat_dihapus`, `jumlah_entry_dihapus`, dan `alasan` (`null` kalau yang dihapus draft, karena draft tidak butuh alasan). `target_type` tersimpan sebagai string literal `"setoran_harian"` (harus persis string ini, bukan FQCN — cocok dengan `Relation::enforceMorphMap()` di `AppServiceProvider`).  
   * Catatan kecil (ranahnya Log Aktivitas, bukan Riwayat Setoran, disebut di sini cuma karena asalnya dari urutan kode `destroy()` ini): kolom `target_loket_id` di baris log ini kemungkinan besar selalu tersimpan `null`, karena `ActivityLog::catat()` mencoba `SetoranHarian::find($id)->loket_id` untuk menurunkannya, padahal dipanggil **setelah** `$setoranHarian->delete()` (soft-delete) — dan `find()` standar Eloquent tidak menemukan row yang sudah soft-deleted. `loket_id` aslinya tetap aman tersimpan di `detail_perubahan.loket_id`, jadi ini bukan kehilangan data, cuma kolom terpisah yang kemungkinan tidak terisi sebagaimana mestinya.  
7. Baris yang sudah dihapus (soft-delete) otomatis tidak muncul lagi di `GET /riwayat` — dan tanggal yang sama bisa diisi ulang dari nol (row baru, bukan dianggap "sudah pernah ada" selamanya).

---

## **3\. GET `/api/v1/teller/setoran/riwayat/export`**

### **Mekanis**

* **Method & Path:** `GET /api/v1/teller/setoran/riwayat/export`  
* **Auth:** wajib login. Tanpa sesi → `401`.  
* **Rate limit:** `throttle:10,1` (10 request/menit). Melebihi → `429`. *(Aktif dan diuji — request ke-11 dalam 1 menit dipastikan dapat 429.)*  
* **Response:** bukan JSON — file `.xlsx` (`Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`), nama file `riwayat-setoran-YYYYMMDD-HHMMSS.xlsx`, dikirim sebagai stream download.  
* **TIDAK dipaginasi seperti layar** — mengambil SEMUA baris yang cocok dengan filter aktif dalam satu request, tidak ada parameter `page` sama sekali di validasi endpoint ini.

### **Request — Query Parameters**

Sama persis dengan `GET /riwayat` **kecuali tidak ada `page`**:

| Field | Tipe | Wajib | Validasi |
| :---- | :---- | :---- | :---- |
| `tanggal_mulai` | string tanggal | tidak | `date_format:Y-m-d` |
| `tanggal_akhir` | string tanggal | tidak | `date_format:Y-m-d`, `after_or_equal:tanggal_mulai` |
| `status_workflow` | string | tidak | `draft` atau `submitted` saja |
| `status_keuangan` | string | tidak | `lunas` / `belum_lunas` / `gagal_dimuat` |
| `loket_id` | integer | tidak | harus terdaftar di `lokets` — kehadirannya memicu mode lintas-loket, gate sama seperti `index()` |

### **Response — per skenario**

* **Sukses — `200 OK`**, body \= file `.xlsx`.  
* **Gagal validasi — `422`:** sama seperti aturan `index()` (tanggal terbalik, nilai filter tidak dikenal, `loket_id` tidak terdaftar).  
* **Ditolak kapabilitas — `403`:** `loket_id` dikirim (valid) tapi tidak punya `bisa_edit_setoran_semua_loket`.  
* **Belum login — `401`.**  
* **Rate limit — `429`:** lebih dari 10 request/menit.

*(Bentuk body 422/403/401/429 di atas ikut envelope error global yang sama — lihat §5.)*

### **Struktur isi file Excel**

1. Baris 1: `FILTER PERIODE:` — isi `"<tanggal awal> s/d <tanggal akhir>"` (format `d M Y`) kalau ada filter tanggal (mulai atau akhir, salah satu cukup), atau `"Semua tanggal"` kalau tidak ada filter tanggal sama sekali.  
2. Baris 2 (**hanya muncul di mode lintas-loket**): `FILTER LOKET:` — nama loket yang sedang diekspor (fallback `"-"` kalau loket sudah terhapus).  
3. 1 baris kosong, lalu header tabel (2 baris): `Tanggal`, `Total Pendapatan (Rp)`, kolom gabungan `Metode Pembayaran` (merge 4 kolom di bawahnya: `Transfer`, `QRIS`, `Tunai`, `KPay`), `Selisih (Rp)`, `Status`.  
4. Baris data, 1 baris per dokumen setoran, kolom `Status` berisi `Draft` atau `Submitted` (status **workflow**, bukan status keuangan).  
5. Baris `TOTAL SEMUA` — jumlah (SUM) tiap kolom angka (Total Pendapatan, tiap metode pembayaran, Selisih). Kolom `Status` dikosongkan.  
6. Baris footnote (**hanya muncul kalau ada baris yang gagal fetch**): `"Catatan: N baris di atas gagal terhubung ke sistem SOPP. Kolom Total Pendapatan dan Selisih untuk baris tersebut ditandai 'Gagal dimuat', tidak diikutkan di Total Semua."`

### **Business rules**

1. **Tidak dipaginasi** — file Excel \= representasi lengkap dari filter yang aktif, bukan representasi 1 halaman layar. Tidak ada parameter `page` di validasi endpoint ini sama sekali.

2. **Guard performa 30 hari — BEDA syarat dari `index()`:** di `export()`, cap otomatis berlaku **setiap kali `tanggal_mulai` tidak dikirim** — terlepas dari `status_keuangan` diisi atau tidak, dan terlepas dari mode (personal maupun lintas-loket). Ini **lebih luas** dari `index()`, yang cap-nya cuma aktif kalau `status_keuangan` juga diisi. **FE perlu tahu kedua endpoint ini punya bound-rule yang tidak simetris** — jangan asumsikan hasil `index()` tanpa `tanggal_mulai` pasti sama cakupan tanggalnya dengan `export()` tanpa `tanggal_mulai` untuk kombinasi filter yang sama. Anchor cap sama seperti `index()`: dari `tanggal_akhir` kalau dikirim, kalau tidak dari hari ini.

3. **Baris dengan fetch bridge gagal TETAP ditulis, TIDAK di-skip** (⚠️ ini berubah dari versi lama yang men-skip baris sepenuhnya):

   * Kolom `Tanggal`, breakdown metode pembayaran (Transfer/QRIS/Tunai/KPay), dan `Status` (workflow) tetap terisi normal — semuanya berasal dari data `entries` baris itu sendiri, tidak butuh bridge sama sekali, jadi selalu valid apapun hasil fetch-nya.  
   * Kolom `Total Pendapatan (Rp)` dan `Selisih (Rp)` untuk baris ini ditandai teks `"Gagal dimuat"`, dan **tidak diikutkan** ke perhitungan `TOTAL SEMUA`.  
   * Jumlah baris yang kena kondisi ini dicatat ke footnote di bawah `TOTAL SEMUA` (hanya muncul kalau memang ada kasusnya).  
4. **Mode personal ikut sertakan baris `draft` ke `TOTAL SEMUA` tanpa pengecualian** kalau sedang tampil (tidak difilter keluar oleh `status_workflow`) — query personal `export()` memang tidak otomatis membatasi ke `submitted` saja (beda dari mode lintas-loket, yang query-nya sudah otomatis hanya `submitted`). Karena formula personal sekarang tidak lagi membedakan draft/submitted (lihat §1 rule \#2), baris draft yang tampil dihitung dengan cara yang sama persis dengan baris submitted.

5. **Field `dibuat_via_bantuan_pelunasan` sengaja TIDAK dimasukkan ke kolom Excel manapun** — spesifikasi kolom sudah final tanpa field ini, meski baris dengan flag ini tetap harus terbaca/terhitung normal di file (tidak boleh error/skip cuma karena flag ini `true`).

6. **Kolom Metode Pembayaran selalu 4 sub-kolom tetap** (Transfer/QRIS/Tunai/KPay) — sama seperti struktur nominal di response `index()`.

7. **Kolom `Status` di Excel \= status WORKFLOW** (`Draft`/`Submitted`), **bukan** `status_keuangan` (`lunas`/`belum_lunas`/`gagal_dimuat`) yang jadi sorotan utama di layar — jangan disamakan maknanya.

8. Sama seperti `index()`, aktivitas ekspor mode lintas-loket dicatat ke activity log (lihat §4) — mode personal tidak.

---

## **4\. Activity Log yang ikut tertulis dari chunk ini**

*(Bukan endpoint tersendiri, tapi efek samping yang wajib diketahui FE/QA karena mempengaruhi apa yang muncul di halaman Log Aktivitas.)*

| Jenis Aksi | Dipicu oleh | Kapan ditulis |
| :---- | :---- | :---- |
| `riwayat_lintas_loket_dilihat` | `GET /riwayat?loket_id=...` sukses | Hanya mode lintas-loket. Tidak ditulis untuk mode personal. Ditulis setelah lolos gate kapabilitas, sebelum query baris dijalankan — percobaan yang ditolak `403` **tidak** meninggalkan jejak log. |
| `riwayat_lintas_loket_diekspor` | `GET /riwayat/export?loket_id=...` sukses | Sama seperti di atas, untuk endpoint export. |
| `setoran_dihapus` | `DELETE /riwayat/{id}` sukses | Semua penghapusan (pemilik maupun non-pemilik), hanya kalau hapus benar-benar berhasil — berisi detail tanggal, loket, status saat dihapus, jumlah entry terhapus, dan alasan (kalau ada). |

Mode personal (index maupun export) **tidak pernah** menulis activity log apapun — teller melihat/mengunduh riwayatnya sendiri dianggap aktivitas rutin yang tidak perlu diaudit.

---

## **5\. Bentuk body setiap response error (envelope global — bukan spesifik Riwayat Setoran)**

⚠️ **Bagian ini penting dan sebelumnya tidak masuk draf saya** — ada `->withExceptions()` di `bootstrap/app.php` yang membungkus **SEMUA** exception dari route `/api/*` (di seluruh aplikasi, bukan cuma chunk ini) jadi 1 bentuk body yang seragam:

{ "status": "error", "message": "...", "errors": { ... } }

Ini **bukan** format default Laravel (Laravel default tidak punya key `status`, dan pesannya beda). Sudah diverifikasi langsung dari kode di `bootstrap/app.php` \+ `vendor/laravel/framework` (urutan `prepareException()` → `renderViaCallbacks()` dikonfirmasi dari source Laravel 12 yang ikut ke-bundle di zip), bukan tebakan.

Breakdown pesan `message` per tipe error, khusus untuk 3 endpoint Riwayat Setoran:

| Status | Dipicu oleh (di chunk ini) | `message` | `errors` |
| :---- | :---- | :---- | :---- |
| `422` | `$request->validate()` gagal (tanggal, enum, `loket_id`) di `index()`/`export()` | `"Validasi gagal."` | object berisi pesan per-field, **berbahasa Indonesia** (locale app \= `id`, dari `lang/id/validation.php`) |
| `422` | `alasan` kosong di `destroy()` baris submitted | `"Alasan wajib diisi untuk menghapus setoran yang sudah submitted dan berisi entry."` | `{"alasan": ["Alasan wajib diisi."]}` — **ini DITULIS LANGSUNG oleh controller** (`response()->json()` manual), bukan lewat exception, jadi satu-satunya 422 di chunk ini yang isinya custom dan tidak mengikuti pola "Validasi gagal." di atas |
| `401` | Tidak ada sesi valid | `"Belum login atau sesi berakhir."` | `{}` (object kosong) |
| `403` | `Gate::authorize('updateForOtherLoket', ...)` gagal | `"Anda tidak memiliki akses untuk melakukan aksi ini."` | `{}` |
| `404` | `SetoranHarian::findOrFail($id)` gagal (id tidak ada) | `"Data tidak ditemukan."` | `{}` |
| `404` | `abort(404)` polos (draft milik orang lain, di `destroy()`) | `"Request tidak dapat diproses."` (fallback generik, karena `abort(404)` di kode tidak diberi pesan) | `{}` |
| `429` | Rate limit terlampaui | `"Terlalu banyak percobaan. Coba lagi sebentar lagi."` | `{}` |
| `419` | CSRF token tidak cocok/kadaluarsa (`TokenMismatchException`) — **hanya bisa terjadi di `DELETE /riwayat/{id}`**, TIDAK di `index()`/`export()` (lihat catatan di bawah) | `"CSRF token mismatch."` | `{}` |

Catatan tambahan:

* Tidak ada satupun test di `RiwayatSetoranTest.php` yang menegaskan isi `message`/`errors` secara eksak (semua cuma `assertStatus(...)`) — jadi tabel di atas saya telusuri langsung dari alur exception handler (`bootstrap/app.php`) dan konfirmasi urutan eksekusinya dari source `vendor/laravel/framework`, bukan dari assertion test. Kalau ada perubahan pesan di masa depan, ini bagian yang paling rawan basi.  
* `errors: {}` di atas maksudnya object JSON kosong (`{}`), bukan array kosong (`[]`) — kodenya eksplisit pakai `(object) []`.  
* **Kenapa `419` gak pernah kelihatan di test:** `VerifyCsrfToken` middleware (aktif karena `statefulApi()`) otomatis di-skip kalau `runningUnitTests()` true — jadi test suite emang gak akan pernah menyentuh jalur ini sama sekali, meski di produksi ini nyata. Ini juga alasan kenapa temuan `419` ini gak akan ketemu cuma dengan baca test file, harus ditelusuri sampai ke middleware stack-nya.  
* **Kenapa cuma `DELETE` yang kena, `GET` (index/export) tidak:** `VerifyCsrfToken::isReading()` meng-exempt method `GET`, `HEAD`, `OPTIONS` dari pengecekan CSRF sama sekali — jadi `index()` dan `export()` (keduanya `GET`) **tidak mungkin** menghasilkan `419`, murni karena method HTTP-nya, bukan karena ada pengecualian khusus ditulis untuk chunk ini.  
* Implikasi praktis buat FE: kalau sesi cookie/CSRF token FE sudah basi (mis. tab dibiarkan terbuka lama tanpa refresh token), tombol hapus di Riwayat Setoran bisa gagal dengan `419` walau user masih kelihatan "login" (401 beda kasus dari ini) — FE perlu tahu ini beda skenario dari sesi habis biasa.

---

## **6\. Ringkasan kode error lintas-endpoint**

| Kode | Skenario | Endpoint |
| :---- | :---- | :---- |
| `401` | Belum login / sesi tidak valid | Ketiganya |
| `422` | Validasi input gagal (format tanggal, nilai enum salah, `loket_id` tidak terdaftar, `tanggal_akhir` \< `tanggal_mulai`) | `index`, `export` |
| `422` | `alasan` wajib tapi tidak dikirim untuk hapus baris submitted (berlaku ke siapapun termasuk pemilik) | `destroy` |
| `403` | Tidak punya kapabilitas `bisa_edit_setoran_semua_loket` — untuk `index`/`export` dipicu oleh `loket_id` (lintas-loket); untuk `destroy` dipicu oleh menghapus baris submitted apapun (termasuk milik sendiri) | `index`, `export`, `destroy` |
| `404` | `id` tidak ada di database (`message`: `"Data tidak ditemukan."`), ATAU baris draft milik orang lain (disamarkan sengaja, `message`: `"Request tidak dapat diproses."` — beda teks, lihat §5) | `destroy` |
| `429` | Melebihi rate limit (`index` 60/menit, `export` 10/menit; `destroy` **tidak ada limit**) | `index`, `export` |
| `419` | CSRF token tidak cocok/kadaluarsa — cuma bisa kena di method "penulis" (`DELETE`), method "pembaca" (`GET`) selalu exempt | `destroy` |
| `500` | ⚠️ Gap teknis: `{id}` di `DELETE /riwayat/{id}` bukan angka (rute tidak punya `whereNumber('id')`) — lihat §2 | `destroy` |
| `200` | Sukses | Ketiganya (`destroy` selalu `{status:"ok", data:null}`, `export` mengembalikan file bukan JSON) |

---

# Detail Setoran

# **Kontrak API — Detail Setoran**

---

## **0\. Response envelope (berlaku ke SELURUH API `/api/*`, bukan cuma Detail Setoran)**

*(BARU: dikonfirmasi langsung dari `bootstrap/app.php`, exception handler terpusat yang membungkus semua response error di seluruh aplikasi, bukan cuma chunk ini.)*

**Sukses**: `{ "status": "ok", "data": {...} }` — selalu bentuk ini, tidak ada variasi.

**Error**: selalu `{ "status": "error", "message": "<string>", "errors": {...} }`, tapi **`message` dan isi `errors` berbeda tergantung SUMBER error-nya** — FE perlu tahu perbedaan berikut:

| Sumber error | `message` | `errors` |
| :---- | :---- | :---- |
| Belum login / token tidak valid (`AuthenticationException`) → `401` | `"Belum login atau sesi berakhir."` | `{}` |
| Row tidak ditemukan sama sekali (`findOrFail()` gagal) → `404` | `"Data tidak ditemukan."` | `{}` (selalu objek kosong) |
| `abort(404)` polos di kode (dipakai buat kasus "row ada tapi status-nya draft") → `404` | **`"Request tidak dapat diproses."`** (fallback generik — bukan pesan khusus soal draft, karena `abort(404)` dipanggil tanpa argumen pesan) | `{}` |
| `Gate::authorize()` gagal (otorisasi non-pemilik, dipakai Detail Setoran GET/PUT — dikonfirmasi `DetailSetoranController` cuma pakai `Gate::authorize()` untuk 403, tidak pernah `abort(403,...)` langsung) → `403` | `"Anda tidak memiliki akses untuk melakukan aksi ini."` — **selalu teks ini persis**, tidak peduli ability mana yang gagal | `{}` |
| `abort(403, 'pesan custom')` dipanggil langsung di kode (dipakai endpoint lain, mis. Bukti Setoran §4 — BUKAN dipakai di Detail Setoran) → `403` | **Teks custom yang dikirim di kode, DIPERTAHANKAN APA ADANYA** — tidak ikut di-override ke pesan generik `Gate::authorize()` di atas, karena ini bukan `AuthorizationException`. **Jangan asumsikan semua 403 di seluruh API selalu pakai 1 teks generik** — tergantung endpoint-nya manggil `Gate::authorize()` atau `abort(403, ...)` langsung. | `{}` |
| Business-rule ditolak manual di controller (mis. id dobel, id bukan milik row) → `422` | Kalimat spesifik per skenario (lihat tabel kode error masing-masing endpoint) | `{}` (selalu objek kosong — **beda dari validasi field di bawah**) |
| Validasi field gagal (`$request->validate($rules)`, mis. field kosong/format salah) → `422` | **Selalu generik: `"Validasi gagal."`** — bukan teks spesifik soal field mana yang salah | **Terisi per-field**, keyed by path lengkap (mis. `"entries.0.metode_pembayaran": ["..."]`). Pesannya berbahasa Indonesia (locale app \= `id`, ada `lang/id/validation.php`), TAPI **tidak ada mapping nama field custom** — jadi label field di dalam teks pesan masih mentah (bentuk asli field path, bukan label yang sudah dirapikan/diterjemahkan ke bahasa manusia). **FE jangan mengandalkan teks di `errors.*` sebagai label field yang siap ditampilkan ke user.** |
| Debounce PUT kena rate limit — **manual `RateLimiter::attempt()` \+ `abort(response()->json(...))` di controller** (BUKAN middleware `throttle:x,y`) → `429` | `"Tunggu sebentar sebelum menyimpan lagi."` — response JSON dibuat manual jadi tidak lewat handler generik di bawah ini | `{}` |
| Middleware `throttle:x,y` di level route (dipakai route lain seperti `riwayat`/`export`, **TIDAK dipakai di route Detail Setoran maupun Bukti Setoran** — sudah dicek langsung di `routes/api.php`, tidak ada `throttle` middleware terpasang di kedua route ini) → `429` | `"Terlalu banyak percobaan. Coba lagi sebentar lagi."` — **beda teks dari debounce PUT di atas**, tapi baris ini murni informasi umum, tidak relevan untuk chunk Detail Setoran/Bukti Setoran karena route-nya tidak pakai middleware ini | `{}` |
| Optimistic lock mismatch → `409` | `"Data ini sudah diubah pihak lain, muat ulang dulu."` | `{}` |

**Konsekuensi penting buat FE**: **2 kasus 404 di endpoint ini (row tidak ada vs row draft) punya `message` yang BEDA**, walau status code-nya sama-sama 404 — jangan asumsikan teksnya seragam kalau FE mau menampilkan `message` langsung ke user. Begitu juga **2 kasus 422 (business-rule vs validasi field) punya bentuk `errors` yang beda** (kosong vs terisi per-field) — kalau FE mau menampilkan error per-field di form, cek dulu apakah `errors` kosong atau tidak, jangan asumsikan selalu ada isinya di setiap 422\.

**Catatan tambahan (bukan test-verified, murni dari struktur route)**: path `{id}` di route Detail Setoran punya constraint `->whereNumber('id')`. Kalau FE (secara tidak sengaja) kirim `id` non-numerik di URL, request itu **tidak pernah sampai ke controller sama sekali** — route-nya tidak match, dan Laravel mengembalikan `404` generik level-routing dengan `message: "Request tidak dapat diproses."` (message sama seperti kasus "row draft" di atas, tapi sumbernya beda: ini gagal di routing, bukan di logic controller). Tidak ada test eksplisit untuk skenario ini di test suite, jadi ini kesimpulan dari membaca constraint route-nya langsung, bukan dari assertion test.

---

## **1\. `GET /api/v1/teller/setoran/{id}` — Show**

### **Mekanis**

| Method | `GET` |
| :---- | :---- |
| Path | `/api/v1/teller/setoran/{id}` |
| Auth | Wajib (`auth:sanctum`) |
| Request | Tidak ada body/query param |
| Status sukses | `200` |

### **Response sukses (200)**

{  
  "status": "ok",  
  "data": {  
    "id": 123,  
    "tanggal\_transaksi": "2026-08-01",  
    "nama\_loket": "Loket Satu",  
    "nama\_teller": "Teller Satu",  
    "status\_workflow": "submitted",  
    "is\_editable": true,  
    "entries\_updated\_at": "2026-08-20T09:00:00+00:00",  
    "submitted\_at": "2026-08-20T09:00:00+00:00",  
    "entries": \[  
      {  
        "id": 1,  
        "metode\_pembayaran": "tunai",  
        "keterangan": null,  
        "nominal": 100000,  
        "foto\_bukti\_url": "https://.../api/v1/bukti-setoran/2026-08-01/1\_tunai\_....jpg",  
        "is\_konfirmasi": true,  
        "nama\_karyawan": null,  
        "identitas\_karyawan": null  
      }  
    \],  
    "ringkasan\_setoran": {  
      "total\_setor": 100000,  
      "nominal\_transfer": 0,  
      "nominal\_qris": 0,  
      "nominal\_tunai": 100000,  
      "nominal\_kpay": 0  
    },  
    "pendapatan": \[  
      { "vendor\_id": 1, "vendor\_name": "Vendor A", "kategori": "Pulsa, PLN", "total": 100000 }  
    \],  
    "total\_pendapatan": 100000,  
    "status\_keuangan": "lunas",  
    "selisih\_sendiri": 0,  
    "petugas": \[  
      { "nama": "Teller Satu", "aksi": "input", "waktu": "2026-08-20T09:00:00+00:00" }  
    \],  
    "dibuat\_via\_bantuan\_pelunasan": false  
  }  
}

### **Business rules per field (bukan sekadar tipe data)**

* **`nama_loket` / `nama_teller`** — fallback ke string `"-"` (bukan `null`) kalau loket atau user pemilik row sudah terhapus dari DB.  
* **`is_editable`** — computed murni untuk dikirim ke FE; **FE dilarang menghitung ulang logic ini sendiri**. Aturan: `false` kalau row bukan `submitted`; `true` selalu untuk pemilik row; untuk non-pemilik, ikut syarat otorisasi **PUT** (`bisa_edit_setoran_semua_loket` saja) — **BUKAN** syarat otorisasi GET yang sekarang lebih longgar. Konsekuensi penting: verifikator laporan (`bisa_akses_laporan` saja) tetap bisa GET (200) row orang lain, tapi `is_editable` di response-nya akan `false` karena dia tidak lolos syarat PUT.  
* **`status_keuangan`** — 3 nilai valid: `gagal_dimuat`, `lunas` (`selisih_sendiri === 0`), `belum_lunas` (`selisih_sendiri` ≠ 0, bukan null). **Tidak pernah nilai lain.** `gagal_dimuat` terjadi kalau `selisih_sendiri === null`, yang bisa disebabkan **2 hal berbeda** (lihat poin `selisih_sendiri` di bawah) — FE tidak perlu/tidak bisa membedakan keduanya dari response, keduanya sama-sama tampil sebagai `gagal_dimuat`.  
* **`total_pendapatan`** — `null` kalau `status_keuangan = gagal_dimuat` (BUKAN `0`, supaya FE tidak salah baca "pendapatan nol" padahal datanya memang gagal dimuat). **PERSONAL** — total pendapatan versi SOPP milik teller pemilik row ini saja (lihat `selisih_sendiri` di bawah untuk detail sumber data).  
* **`selisih_sendiri`** *(REVISI: sebelumnya bernama `selisih` dan loket-wide — field itu sudah dihapus total dari response ini)* — dihitung `total_pendapatan_PERSONAL_teller_pemilik_row − total_setor_row_ini` (`ringkasan_setoran.total_setor`). Sekarang **murni personal, tidak lagi digabung dengan teller lain di loket yang sama** — kebalikan dari perilaku lama. Datanya **live-fetch** ke bridge SOPP setiap kali GET/PUT dipanggil (bukan snapshot statis lagi), difilter berdasarkan identitas legacy (`user_id_sopp`) teller pemilik row \+ tanggal transaksi row ini. **`null`** kalau salah satu dari 2 kondisi ini terjadi: (1) fetch ke bridge gagal (network/exception), atau (2) teller pemilik row **tidak punya identitas legacy** sama sekali (`user_id_sopp` null — dalam praktiknya cuma bisa terjadi pada akun superadmin generik). Kalau selisih loket-wide (gabungan semua teller) dibutuhkan FE, itu **bukan lagi domain endpoint ini** — ada di modul lain (Card A/Dashboard/Pelunasan/Laporan).  
* **`ringkasan_setoran`** — **tidak berubah**, tetap PERSONAL (cuma entries milik row ini) dan tetap dihitung dari data lokal (`totalSetor()`), tidak tersentuh perubahan live-fetch di atas. **Jangan disamakan dengan `selisih_sendiri`** — dua konsep beda tapi sekarang sama-sama personal: `selisih_sendiri` menjawab "apakah pendapatan versi SOPP milik saya sudah pas sama setoran saya", `ringkasan_setoran` menjawab "berapa yang saya setorkan, dipecah per metode".  
* **`pendapatan`** — breakdown per-vendor, `null` kalau `selisih_sendiri` null (lihat kondisi di atas — sumber kegagalannya sama, satu fetch yang sama). *(REVISI: sebelumnya dibaca statis dari `snapshot_sopp` yang tersimpan saat submit — sekarang **live-fetch ke bridge SOPP tiap request**, lalu di-agregasi ulang di level personal.)* Field key tetap `kategori` (bukan `layanan`, tidak berubah) — tapi isinya sekarang **dihitung ulang saat request** dari nama-nama produk unik milik teller ini yang digabung koma (mis. `"Pulsa, PLN"`), bukan dikutip mentah dari snapshot.  
* **`foto_bukti_url`** — selalu dibentuk lewat endpoint terpisah `GET /api/v1/bukti-setoran/{path}` (perhatikan prefix **`v1`** — sebelumnya ada bug di mana URL ini dibangun hardcode tanpa prefix `v1` sehingga hasilnya 404 kalau diakses; sudah diperbaiki dengan named route `bukti-setoran.show`, dan dibangun lewat method bersama `SetoranDetail::toApiShape()` yang juga dipakai modul Pelunasan). `null` kalau entry tidak punya foto (mis. metode `kpay`).  
* **`petugas`** — daftar orang yang menyentuh **row ini saja** (tidak pernah bocor dari row lain), diurutkan berdasar waktu (ascending). Sumbernya dua level `ActivityLog` yang beda kelakuan:  
  * **`aksi: "input"`** — hanya dari event `submit_setoran` (bukan `buat_setoran`), maksimal 1 baris. Ini momen tunggal "row ini pertama kali jadi final" — sengaja bukan dari `buat_setoran` (yang fire per-entry, bisa berkali-kali selagi masih bolak-balik ngedraft, dan akan menumpuk beberapa baris "input" dengan jam nyaris sama kalau dipakai).  
  * **`aksi: "edit" | "hapus" | "pelunasan"`** — dari `buat_setoran` / `edit_setoran` / `hapus_setoran` / `buat_pelunasan` yang terjadi **setelah** `submitted_at`. Aksi yang terjadi **sebelum atau tepat pada** `submitted_at` (histori revisi draft di Card A) **disaring keluar seluruhnya** — itu histori "lagi mengisi form", bukan koreksi pasca-final. `buat_pelunasan` tidak pernah kena filter ini karena struktural hanya bisa terjadi setelah `submitted`. Entry yang sudah dihapus permanen (hard delete) tetap muncul di histori ini walau baris fisiknya sudah tidak ada.  
* **`dibuat_via_bantuan_pelunasan`** — diteruskan apa adanya dari kolom row.

### **Otorisasi GET — *(REVISI: sekarang BEDA dari PUT, sebelumnya sama persis)***

Urutan pengecekan **wajib** seperti ini (urutan ini menentukan status code yang keluar):

1. **Status row bukan `submitted` → `404`.** Berlaku untuk **siapa pun, termasuk pemilik row sendiri** — draft milik sendiri pun 404 di endpoint ini (dipaksa balik ke Card A). Ini prioritas \#1, dicek sebelum apa pun lainnya.  
2. Kalau `user_id` row \=== user yang login → **lolos, akses diberikan**.  
3. Kalau bukan pemilik → dicek ability **`viewForOtherLoket`** (khusus GET, beda dari ability PUT) — lolos kalau **`bisa_edit_setoran_semua_loket === true` ATAU `bisa_akses_laporan === true`** (union, salah satu cukup). Gagal keduanya → `403`.

### **Detail penting yang mudah salah tebak**

* **`is_superadmin` TIDAK memberi bypass sama sekali** di endpoint ini — ability ini sengaja dikecualikan dari bypass superadmin global. Superadmin tanpa kapabilitas eksplisit tetap kena `403`.  
* **`bisa_akses_laporan` SEKARANG CUKUP untuk GET** *(REVISI: sebelumnya tidak cukup, tetap 403\)* — verifikator laporan bisa buka halaman Detail Setoran (200) untuk row `submitted` milik teller/loket lain, demi kebutuhan navigasi read-only dari halaman Laporan. **Tapi ini VIEW-only** — `is_editable` akan bernilai `false` untuk user seperti ini kalau dia bukan pemilik row, dan percobaan PUT tetap 403 (lihat otorisasi PUT di bawah, ability-nya beda).

### **Kode error (GET)**

*(Shape envelope & perbedaan pesan per sumber error dijelaskan di §0 — tabel ini fokus ke KAPAN tiap kode muncul, bukan mengulang shape-nya.)*

| Status | Kapan | `message` (lihat §0 untuk shape lengkap) |
| :---- | :---- | :---- |
| `401` | Belum login. | `"Belum login atau sesi berakhir."` — ditangani middleware `auth:sanctum` \+ handler global, bukan kode chunk ini, tapi pesannya tetap konsisten §0. |
| `404` | Row `id` tidak ditemukan di DB. | `"Data tidak ditemukan."` |
| `404` | Row **ditemukan** tapi berstatus `draft` (berlaku ke siapa pun termasuk pemilik). | `"Request tidak dapat diproses."` — **beda pesan dari baris di atas**, walau sama-sama 404\. |
| `403` | Non-pemilik tanpa `bisa_edit_setoran_semua_loket` **dan** tanpa `bisa_akses_laporan` (termasuk superadmin tanpa kapabilitas eksplisit) mengakses row `submitted` milik orang lain. | `"Anda tidak memiliki akses untuk melakukan aksi ini."` |

---

## **2\. `PUT /api/v1/teller/setoran/{id}` — Update**

Semantik: **full replace** entries row ini dalam satu request — upsert (`id` ada → update, `id` tidak ada → create) **plus delete-by-omission** (entry lama yang tidak ikut dikirim ulang → **hard delete permanen**, beda dari draft Card A yang soft/reversible). Field `tanggal_transaksi` dan `loket_id` row **tidak bisa diubah** lewat endpoint ini (immutable, tidak ada field untuk itu di payload).

### **Mekanis**

| Method | `PUT` |
| :---- | :---- |
| Path | `/api/v1/teller/setoran/{id}` |
| Auth | Wajib (`auth:sanctum`) |
| Content-Type | `multipart/form-data` (karena ada file upload per-entry) |
| Status sukses | `200` |

### **Request contract**

{  
  "entries\_updated\_at": "2026-08-20T09:00:00+00:00",  
  "entries": \[  
    {  
      "id": 1,  
      "metode\_pembayaran": "tunai",  
      "nominal": 100000,  
      "keterangan": null,  
      "is\_konfirmasi": true  
    },  
    {  
      "metode\_pembayaran": "kpay",  
      "nominal": 20000,  
      "nama\_karyawan": "Budi",  
      "identitas\_karyawan": "12345"  
    },  
    {  
      "metode\_pembayaran": "transfer",  
      "nominal": 15000,  
      "foto\_bukti": "\<file jpg/jpeg/png, max 5120KB\>"  
    }  
  \]  
}

| Field | Wajib? | Aturan |
| :---- | :---- | :---- |
| `entries_updated_at` | **Wajib ADA di payload** (`present`), tapi boleh berisi `null` | Ini bukan sekadar validasi tipe — field harus **selalu di-echo balik oleh FE** persis dari nilai terakhir yang diterima dari `GET`, walau nilainya `null`. Kalau FE lupa sertakan field ini sama sekali dan DB-nya kebetulan `null`, request tetap lolos "diam-diam" (bahaya tersembunyi) — tapi kalau DB-nya sudah pernah terisi, ketiadaan field ini akan ketahuan lewat jalur `409` di bawah. **FE wajib selalu kirim field ini, jangan pernah di-omit.** |
| `entries` | Wajib, array, **min 1** | Beda dari Card A: di sini **tidak pernah ada mode draft-boleh-kosong** — kalau hasil akhir 0 entries, request ditolak. Invariant hasil: row `submitted` selalu punya ≥1 entry. |
| `entries[].id` | Opsional (int) | Ada → dianggap update entry lama. Tidak ada/kosong → dianggap entry baru. |
| `entries[].metode_pembayaran` | Wajib | Salah satu dari `transfer`, `qris`, `tunai`, `kpay`. |
| `entries[].nominal` | Wajib | Integer, min 1\. |
| `entries[].keterangan` | Opsional | String, nullable. |
| `entries[].is_konfirmasi` | Opsional | Boolean, default `false` kalau tidak dikirim. |
| `entries[].nama_karyawan` | **Kondisional** | **Wajib jika `metode_pembayaran = kpay`**, dan **terlarang (`prohibited`) jika metode bukan `kpay`** — bukan sekadar nullable biasa. |
| `entries[].identitas_karyawan` | **Kondisional** | Sama seperti `nama_karyawan` di atas. |
| `entries[].foto_bukti` (file) | **Kondisional, tergantung 3 kondisi** | Lihat tabel di bawah — ini bukan aturan tunggal, tergantung kombinasi metode baru vs metode lama entry. |

#### **Aturan `foto_bukti` per skenario (poin paling gampang salah)**

| Kondisi entry | Aturan `foto_bukti` |
| :---- | :---- |
| `metode_pembayaran` baru \= `kpay` | **`prohibited`** — kirim file di sini \= 422\. |
| Entry baru (`id` kosong) dan metode bukan `kpay` | **`required`** — entry baru non-kpay wajib ada foto. |
| Entry lama (`id` ada) yang metode **lama**\-nya `kpay`, lalu diganti ke non-kpay | **`required`** — pindah dari kpay ke metode lain wajib foto baru (karena kpay tidak pernah punya foto tersimpan). |
| Entry lama yang metode lama-nya **bukan** `kpay`, metode barunya juga bukan `kpay` | **Opsional** — tidak kirim foto \= foto lama dipertahankan; kirim foto baru \= foto lama dihapus & diganti. |

File (bila wajib/dikirim): harus `jpg`/`jpeg`/`png`, maksimal 5120 KB (5MB).

### **Response sukses (200)**

Shape **identik** dengan response `GET` (termasuk field `selisih_sendiri`/`pendapatan`/`total_pendapatan` yang sekarang personal & live-fetch — lihat §1) — `{ "status": "ok", "data": { ...detail terbaru... } }`, sudah termasuk `entries_updated_at` yang baru (ter-bump) dan `entries` hasil akhir.

### **Otorisasi PUT — tidak berubah, TETAP LEBIH KETAT dari GET**

Beda dari GET (yang sekarang bisa diakses `bisa_akses_laporan` juga), PUT **hanya** mengizinkan:

1. Status row bukan `submitted` → `404` (sama seperti GET).  
2. Pemilik row → lolos.  
3. Non-pemilik → **hanya** lolos kalau `bisa_edit_setoran_semua_loket === true`. **`bisa_akses_laporan` saja TIDAK CUKUP untuk PUT** — user seperti ini bisa GET (200) tapi PUT ke row yang sama tetap `403`. Superadmin tanpa kapabilitas eksplisit juga tetap `403` (tidak berubah).

### **Business rules inti (bahasa manusia)**

1. **Optimistic lock (`entries_updated_at`)** — sebelum menyimpan, sistem membandingkan `entries_updated_at` yang dikirim FE dengan nilai terbaru di DB **setelah row dikunci** (`lockForUpdate`, dibaca ulang di dalam transaksi — bukan dari hasil baca di awal request). Kalau **tidak cocok** → `409`, pesan "Data ini sudah diubah pihak lain, muat ulang dulu." Kalau nilai di DB memang `null` (row belum pernah disentuh observer sama sekali) → pengecekan **dilewati**, apa pun yang dikirim FE diterima.  
2. **Debounce klik ganda** — window 2 detik per (`user_id` \+ `row_id`). Klik "Simpan" dua kali berturut-turut dalam 2 detik pada row yang sama → request kedua `429`, pesan "Tunggu sebentar sebelum menyimpan lagi." **Validasi payload dijalankan DULU, baru debounce** — supaya request yang gagal validasi (422) tidak ikut membakar jatah debounce, sehingga user yang buru-buru membenarkan lalu retry tidak salah kena 429 padahal belum pernah berhasil kirim apa pun. **Ini independen dari optimistic lock** — debounce mencegah 1 orang double-klik; optimistic lock mencegah 2 orang berbeda saling menimpa. Keduanya menjaga hal yang berbeda.  
3. **Delete-by-omission \= HARD DELETE permanen.** Entry lama yang `id`\-nya tidak ikut dikirim ulang di payload akan **dihapus permanen** dari DB (bukan soft delete, tidak bisa dipulihkan). Foto fisik terkait juga ikut dihapus dari storage — **tapi baru dieksekusi setelah transaksi DB commit** (`DB::afterCommit`), supaya kalau transaksi gagal di tengah jalan, file fisik tidak ikut hilang padahal datanya batal berubah.  
4. **Ganti metode ke `kpay`** → foto lama (jika ada) dihapus dari storage, `foto_bukti_path` di-set `null`.  
5. **Rollback file baru bila batch gagal di tengah** — kalau memproses beberapa entries dalam satu request dan salah satu entry di tengah gagal validasi bisnis (mis. `id` bukan milik row ini → 422), file-file baru yang **sudah sempat ditulis ke storage** oleh entries sebelumnya di batch yang sama **ikut dihapus lagi** (rollback), dan seluruh transaksi DB dibatalkan. FE tidak akan melihat entry parsial tersimpan.  
6. **Setiap perubahan entry ter-log ke `ActivityLog`** dengan `detail_perubahan.setoran_harian_id` \= id row ini (field ini wajib ada, dipakai untuk membangun daftar `petugas` di endpoint GET tanpa perlu join lewat `target_id` — penting karena hard delete membuat join lewat `target_id` mustahil setelah row terhapus):  
   * Entry baru → `jenis_aksi: buat_setoran`.  
   * Entry lama diedit → `jenis_aksi: edit_setoran`, menyimpan snapshot `sebelum` dan `sesudah` (field: `metode_pembayaran`, `nominal`, `keterangan`, `is_konfirmasi`).  
   * Entry lama dihapus (omission) → `jenis_aksi: hapus_setoran`, menyimpan snapshot entry yang dihapus **dibungkus di dalam key `entry`** — bentuknya `detail_perubahan: { setoran_harian_id, entry: { metode_pembayaran, nominal, foto_bukti_path } }`, **bukan** field flat langsung di bawah `detail_perubahan` (beda dari `edit_setoran` yang pakai `sebelum`/`sesudah` flat).  
7. **`nama_karyawan`/`identitas_karyawan` selalu di-null-kan otomatis di DB kalau metode bukan `kpay`**, tidak peduli apa yang dikirim FE untuk field itu.

### **Kode error**

*(Shape envelope & perbedaan pesan per sumber error dijelaskan di §0.)*

| Status | Kapan | `errors` (lihat §0) |
| :---- | :---- | :---- |
| `401` | Belum login. `message`: `"Belum login atau sesi berakhir."` | `{}` |
| `404` | Row tidak ditemukan di DB. `message`: `"Data tidak ditemukan."` | `{}` |
| `404` | Row **ditemukan** tapi berstatus `draft` (berlaku ke siapa pun termasuk pemilik). `message`: `"Request tidak dapat diproses."` (beda dari baris di atas). | `{}` |
| `403` | Non-pemilik tanpa `bisa_edit_setoran_semua_loket` mencoba update row `submitted` orang lain. `message`: `"Anda tidak memiliki akses untuk melakukan aksi ini."` | `{}` |
| `422` (business-rule, `errors` SELALU kosong) | — Ada `id` entry yang dikirim **dua kali** dalam satu request — `message`: `"Satu entry (id) tidak boleh dikirim dua kali dalam satu request."`\<br\>— Ada `id` entry yang **bukan milik row ini** (atau tidak ditemukan) — `message`: `"Salah satu entry tidak ditemukan atau bukan milik setoran ini."` (Ini juga bisa muncul di tengah loop transaksi kalau entry lama hilang antara validasi awal dan eksekusi.) | `{}` |
| `422` (validasi field, `errors` **terisi per-field**) | — `entries` kosong (kurang dari 1 item).\<br\>— `entries_updated_at` tidak dikirim sama sekali di payload (field harus *present*).\<br\>— `metode_pembayaran`/`nominal` tidak valid (bukan salah satu enum / nominal \< 1).\<br\>— `foto_bukti` melanggar salah satu dari 4 kondisi tabel di atas (wajib tapi kosong, atau dikirim padahal terlarang).\<br\>— `nama_karyawan`/`identitas_karyawan` kosong padahal metode `kpay`, atau terisi padahal metode bukan `kpay`. Untuk semua baris ini, `message` di level atas selalu generik `"Validasi gagal."` — detail spesifiknya ada di `errors.<field_path>`, bukan di `message`. | `{"entries.N.<field>": ["..."]}` |
| `409` | `entries_updated_at` yang dikirim tidak cocok dengan nilai terbaru di DB (row sudah diubah pihak lain sejak FE terakhir memuat data). `message`: `"Data ini sudah diubah pihak lain, muat ulang dulu."` | `{}` |
| `429` | Request PUT kedua ke row yang sama dari user yang sama dalam window 2 detik setelah request pertama. `message`: `"Tunggu sebentar sebelum menyimpan lagi."` | `{}` |

---

## **3\. Ringkasan otorisasi — *(REVISI: GET dan PUT sekarang PUNYA ATURAN BERBEDA, tidak lagi satu diagram)***

**GET:**

status row \!== 'submitted'  →  404 (SIAPA PUN, termasuk pemilik)  
                ↓ (status submitted)  
user\_id \=== pemilik row?  →  YA  →  akses diberikan  
                ↓ TIDAK  
bisa\_edit\_setoran\_semua\_loket \=== true  ATAU  bisa\_akses\_laporan \=== true?  
                ↓ YA (salah satu saja cukup)          ↓ TIDAK (keduanya false)  
          akses diberikan (VIEW)                            403

**PUT:**

status row \!== 'submitted'  →  404 (SIAPA PUN, termasuk pemilik)  
                ↓ (status submitted)  
user\_id \=== pemilik row?  →  YA  →  akses diberikan  
                ↓ TIDAK  
bisa\_edit\_setoran\_semua\_loket \=== true?  →  YA  →  akses diberikan  
                ↓ TIDAK (bisa\_akses\_laporan TIDAK dihitung di sini)  
                403

Tidak ada bypass `is_superadmin` di kedua endpoint. Kapabilitas `bisa_akses_laporan` **hanya berpengaruh ke GET**, sama sekali tidak relevan untuk PUT.

---

## **4\. `GET /api/v1/bukti-setoran/{path}` — endpoint yang diserve lewat `foto_bukti_url`**

*(REVISI: sebelumnya section ini cuma placeholder "dibahas terpisah" — sekarang didokumentasikan penuh karena FE langsung konsumsi URL ini dari response Detail Setoran, dan source lengkap \+ `BuktiSetoranTest.php` (7 test) sudah bisa diverifikasi.)*

Endpoint ini **method & otorisasinya independen dari Detail Setoran** — jangan asumsikan aturan §1/§2 berlaku di sini, ini bukan endpoint yang sama.

| Method | `GET` |
| :---- | :---- |
| Path | `/api/v1/bukti-setoran/{path}` (path mencakup subfolder tanggal, mis. `2026-08-10/foto-123.jpg`) |
| Auth | Wajib (`auth:sanctum`) |
| Sukses | `200`, isi respons adalah **stream file gambar mentah** (bukan JSON) |
| Gagal | `404` kalau `path` tidak match `foto_bukti_path` manapun di tabel `setoran_detail`; `403` kalau tidak lolos otorisasi (lihat di bawah) |

### **Otorisasi (union 4 kondisi, salah satu cukup) — BEDA dari otorisasi GET Detail Setoran**

Lolos akses (200) kalau **salah satu** dari ini benar:

1. `user->is_superadmin === true` — **bypass penuh**, tidak seperti Detail Setoran yang justru sengaja mengecualikan `is_superadmin` dari bypass.  
2. `user->bisa_akses_laporan === true` — bypass penuh, **tidak peduli status row** (draft maupun submitted, tidak ada syarat tambahan).  
3. Pemilik row (`entry->setoranHarian->user_id === user->id`) — bypass penuh, **tidak peduli status row**.  
4. `user->bisa_edit_setoran_semua_loket === true` **DAN** `entry->setoranHarian->status === 'submitted'` — kombinasi kedua syarat wajib sama-sama benar; kapabilitas ini **tidak pernah membuka akses ke foto milik row `draft` orang lain**, walau row-nya baru submitted, kapabilitas ini tidak dicek statusnya kalau bukan kombinasi ini (foto draft orang lain tetap 403 walau dia punya `bisa_edit_setoran_semua_loket`).

Kalau keempatnya gagal → `403` dengan pesan `"Tidak memiliki akses ke foto bukti ini."`.

### **Detail yang mudah salah tebak**

* **`is_superadmin` di sini JUSTRU bypass**, kebalikan dari perilaku di Detail Setoran (§1/§2) yang sengaja mengecualikan superadmin dari bypass. Jangan generalisasi aturan otorisasi dari satu endpoint ke endpoint lain di modul yang sama.  
* Ada catatan audit historis di kode/test (`TEMUAN AUDIT 20 Agustus 2026`) yang bilang kondisi \#4 (`bisa_edit_setoran_semua_loket`) ini pernah tidak ada sama sekali — verifikator lintas-loket dulu tidak bisa lihat foto row yang justru jadi tanggung jawabnya untuk diedit, kecuali dia kebetulan juga punya `bisa_akses_laporan`. Sudah diperbaiki; sekarang kondisi \#4 ada.

### **Ringkasan otorisasi**

is\_superadmin \=== true?                                    → YA → 200  
bisa\_akses\_laporan \=== true?                                → YA → 200  
pemilik row (setoranHarian.user\_id \=== user.id)?            → YA → 200  
bisa\_edit\_setoran\_semua\_loket \=== true DAN status==='submitted'? → YA → 200  
                                                             → semua TIDAK → 403

## **5\. Riwayat perubahan dokumen**

* **30 Agustus 2026** — Koreksi `foto_bukti_url`: prefix path yang benar adalah `/api/v1/bukti-setoran/...` (bukan `/api/bukti-setoran/...` seperti draf sebelumnya). Ini bukan perubahan business rule — draf sebelumnya sempat mereplikasi bug URL yang memang ada di kode saat itu (hardcoded, kelewat prefix `v1`), yang kemudian ditemukan & diperbaiki developer saat memeriksa modul Bukti Setoran, lalu dikonfirmasi lewat test regresi baru di `DetailSetoranTest.php` dan `PelunasanTest.php`. Tidak ada perubahan lain ke mekanis, validasi, atau kode error di dokumen ini akibat perubahan tersebut.

* **2 September 2026** — Perubahan besar ke section finansial & otorisasi GET, dikonfirmasi dari `SelisihPerUserService.php`, `BridgeService.php`, `DetailSetoranController.php`, `SetoranHarianPolicy.php`, dan `DetailSetoranTest.php` versi terbaru:

  1. **Field `selisih` (loket-wide) DIHAPUS TOTAL**, diganti `selisih_sendiri` (personal, hitung sendiri vs setoran sendiri, tidak lagi digabung teller lain di loket yang sama).  
  2. **`pendapatan` & `total_pendapatan`** — nama field & shape tidak berubah, tapi **sumber datanya berubah total**: dari statis snapshot (`snapshot_sopp`, dibaca saat submit) menjadi **live-fetch ke bridge SOPP setiap request** (GET maupun PUT), difilter personal ke identitas teller pemilik row.  
  3. **`status_keuangan`** — kondisi `gagal_dimuat` sekarang berarti "fetch personal ke bridge gagal ATAU teller pemilik row tidak punya identitas legacy (`user_id_sopp` null)" — bukan lagi soal `snapshot_sopp` null.  
  4. **Otorisasi GET dan PUT sekarang BEDA** (sebelumnya identik): GET sekarang mengizinkan `bisa_akses_laporan` untuk VIEW read-only ke row `submitted` orang lain (sebelumnya 403 tetap). PUT **tidak berubah** — tetap hanya pemilik atau `bisa_edit_setoran_semua_loket`.  
* **Tidak berubah:** seluruh business rule PUT (validasi, hard delete, rollback file, debounce, optimistic lock, aturan `foto_bukti`), `ringkasan_setoran`, `petugas[]`, dan perbaikan URL foto dari revisi sebelumnya.

* **8 September 2026** — Recheck menyeluruh atas permintaan user (baca ulang seluruh `DetailSetoranController.php`, termasuk fungsi yang belum sempat ditampilkan detail sebelumnya, plus `BuktiSetoranController.php` & `BuktiSetoranTest.php` yang baru tersedia lengkap di `sopp_nyoba.zip`). Hasil: 2 perbaikan/penambahan, bukan perubahan behavior kode:

  1. **Koreksi shape `hapus_setoran`** — snapshot entry yang dihapus ternyata dibungkus di key `entry` (`detail_perubahan.entry.{metode_pembayaran,nominal,foto_bukti_path}`), bukan flat seperti yang tertulis sebelumnya. Ini kesalahan dokumentasi lama yang baru ketahuan sekarang, bukan perubahan kode.  
  2. **§4 dilengkapi penuh** — sebelumnya cuma placeholder "dibahas terpisah, tidak dicakup". Sekarang didokumentasikan lengkap (otorisasi 4-kondisi union, termasuk bahwa `is_superadmin` justru bypass di endpoint foto ini — kebalikan dari Detail Setoran) karena source lengkap \+ test-nya sudah bisa diverifikasi dan endpoint ini langsung dikonsumsi lewat `foto_bukti_url`.  
* **8 September 2026 (recheck kedua)** — Baca langsung `bootstrap/app.php` (exception handler global, sebelumnya belum pernah dibaca — semua klaim soal pesan error di dokumen ini sebelumnya ditarik dari nama test/komentar, bukan dari sumber envelope-nya langsung), `AppServiceProvider.php` (`Gate::before()`), `config/app.php` \+ `lang/id/validation.php` (locale), dan urutan middleware di `routes/api.php`. Temuan, semuanya penambahan dokumentasi (bukan perubahan kode):

  1. **Section baru §0** — envelope response ternyata tidak seragam seperti yang tersirat di tabel-tabel sebelumnya: **2 kasus 404 berbeda pesan** (row tidak ada vs row draft), **403 dari `Gate::authorize()` selalu 1 teks generik tapi `abort(403, 'pesan custom')` di endpoint lain (mis. Bukti Setoran) mempertahankan teksnya sendiri**, dan **422 punya 2 shape berbeda** (business-rule: `errors` selalu kosong; validasi field: `errors` terisi per-field, tapi `message` di level atas tetap generik `"Validasi gagal."`, dan label field di dalamnya masih mentah karena tidak ada mapping `attributes` custom).  
  2. **Dikonfirmasi langsung (bukan lagi inferensi dari komentar test)**: daftar pengecualian bypass superadmin di `Gate::before()` persis `createForOtherLoket`, `updateForOtherLoket`, `viewForOtherLoket`.  
  3. **Dikonfirmasi**: tidak ada throttle tambahan di level route untuk GET/PUT Detail Setoran (beda dari `riwayat` yang punya `throttle:60,1`) — proteksi PUT murni dari debounce 2 detik di controller.  
  4. Tabel kode error GET & PUT diupdate untuk merujuk shape di §0 dan membedakan 2 sub-kasus 404 serta 2 sub-kasus 422 yang sebelumnya digabung jadi satu baris seolah homogen.

# Ganti Password

# **API Contract — Chunk Atur Password**

Ini **bukan** fitur "ganti password" konvensional. Ini fitur "atur/set password", murni self-service, tanpa verifikasi password lama.

---

## **1\. Endpoint & Auth**

| Method | `PUT` |
| :---- | :---- |
| Path | `/api/v1/me/password` |
| Middleware | `auth:sanctum` (dari group), `throttle:5,1` (5 request/menit, per route) |
| Target | Selalu diri sendiri (`$request->user()`). **Tidak ada** parameter `{id}` di path — endpoint ini secara struktural tidak mungkin dipakai untuk set/ganti password user lain. |

**Auth requirement (Sanctum SPA, cookie-based, bukan Bearer token):**

* Butuh sesi login valid (`auth:sanctum`) → kalau tidak, **401**.  
* Karena app ini pakai `statefulApi()` (lihat `bootstrap/app.php`) \+ `SANCTUM_STATEFUL_DOMAINS` (`config/sanctum.php`), request yang mengubah state (PUT/POST) tetap butuh CSRF token dari cookie `XSRF-TOKEN` (hasil `GET /sanctum/csrf-cookie`) sebelum manggil endpoint ini. Kalau token CSRF hilang/salah → **419**.

---

## **2\. Request Contract**

**PUT /api/v1/me/password**  
Content-Type: application/json

{  
  "password": "string",  
  "password\_confirmation": "string"  
}

| Field | Wajib | Validasi (dari kode) |
| :---- | :---- | :---- |
| `password` | Ya | `required`, `string`, `min:8`, `confirmed` |
| `password_confirmation` | Ya (implisit lewat rule `confirmed`) | Harus identik dengan `password` |

Catatan validasi yang gak keliatan dari tipe data doang:

* **Tidak ada field password lama sama sekali.** Backend tidak menerima maupun memvalidasi field seperti `old_password`/`current_password`. Kalau FE tetap mengirim field itu, backend akan mengabaikannya sepenuhnya (bukan error, bukan dipakai).  
* **String isi spasi doang otomatis gagal `required`.** Middleware global (`TrimStrings` \+ `ConvertEmptyStringsToNull`) mengubah string spasi jadi `null` sebelum validasi jalan, jadi `password: " "` akan dianggap kosong dan gagal `required` — bukan lolos lalu dianggap password aneh.  
* **Password baru boleh identik dengan password lama.** Tidak ada rule yang membandingkan ke password lama, jadi set ulang dengan nilai yang sama tetap sukses (200), bukan error.  
* **Password lama boleh `null` (belum pernah diset).** Endpoint ini menerima baik pengisian pertama kali (password sebelumnya `NULL` — kondisi default user hasil SSO) maupun pengisian ulang, dengan validasi & response yang sama persis.

---

## **3\. Response Contract per Skenario**

Semua response error di app ini melewati satu global exception handler (`bootstrap/app.php`) yang memaksa envelope `{status, message, errors}` — **kecuali satu skenario yang dijelaskan di §5 (bug)**.

### **3.1 Sukses — `200 OK`**

{  
  "status": "ok",  
  "data": null  
}

* `data` **selalu `null`**, bukan objek user atau apa pun. FE tidak boleh berharap dapat data user baru dari response ini — kalau perlu data user ter-update (misal buat re-render halaman profil), harus fetch ulang lewat `GET /api/v1/me` secara terpisah.  
* Efek samping yang terjadi di balik layar (tidak terlihat di response, tapi penting buat FE tahu):  
  * Password di-hash otomatis (cast model `'password' => 'hashed'`), tidak pernah disimpan plaintext.  
  * Dicatat ke `activity_logs` (`jenis_aksi: 'atur_password'`, `target_type: 'users'`, `target_id`: id user itu sendiri). Detail yang disimpan cuma flag `password_sebelumnya_kosong` (true/false) — **tidak pernah** menyimpan password (plaintext maupun hash) dalam bentuk apa pun.  
  * **Sesi/token login lain TIDAK di-invalidate.** Kalau user login dari device/tab lain, sesi itu tetap jalan setelah password diganti di sini (sudah dikonfirmasi lewat test, bukan asumsi).

### **3.2 Tidak login — `401 Unauthorized`**

{  
  "status": "error",  
  "message": "Belum login atau sesi berakhir.",  
  "errors": {}  
}

Muncul kalau `auth:sanctum` gagal (sesi tidak ada/expired).

### **3.3 CSRF token hilang/salah — `419`**

{  
  "status": "error",  
  "message": "CSRF token mismatch.",  
  "errors": {}  
}

Muncul kalau request dikirim tanpa siklus `GET /sanctum/csrf-cookie` yang benar (lihat §1).

### **3.4 Superadmin — `403 Forbidden`**

{  
  "status": "error",  
  "message": "Superadmin tidak bisa mengubah password lewat halaman ini.",  
  "errors": {}  
}

* Berlaku total, tanpa pengecualian. Satu-satunya jalur ganti password superadmin adalah `artisan tinker` manual, di luar API ini sama sekali — jadi FE **tidak perlu** (dan tidak bisa) menyediakan alur ini untuk akun superadmin.  
* **Urutan penting:** pengecekan superadmin ini terjadi **sebelum** validasi field. Jadi kalau superadmin kirim payload yang juga invalid (misal password kosong), yang muncul tetap **403**, bukan 422\.

### **3.5 Validasi gagal — `422 Unprocessable Entity`**

Envelope umum:

{  
  "status": "error",  
  "message": "Validasi gagal.",  
  "errors": {  
    "password": \["\<pesan sesuai rule yang gagal\>"\]  
  }  
}

Error selalu muncul di key `password` (bukan `password_confirmation`), untuk semua kasus berikut — pesan persis dari `lang/id/validation.php` (locale app \= `id`, tidak ada override nama atribut custom, jadi label pakai "Password" apa adanya):

| Kondisi | Pesan di `errors.password` |
| :---- | :---- |
| Field tidak dikirim / kosong / isi spasi doang | `"Password wajib diisi."` |
| Kurang dari 8 karakter | `"Password minimal berisi 8 karakter."` |
| `password_confirmation` tidak dikirim atau tidak cocok | `"Konfirmasi password tidak cocok."` |

### **3.6 Klik dobel — `429 Too Many Requests`**

{  
  "status": "error",  
  "message": "Tunggu sebentar sebelum menyimpan lagi.",  
  "errors": {}  
}

Muncul kalau ada request kedua ke endpoint ini dari user yang sama dalam jendela 2 detik setelah request pertama (terlepas request pertama itu sukses atau tidak — lihat mekanisme di §4). **Sudah dikonfirmasi lewat eksekusi test sungguhan** (`php artisan test`) di environment ini, bukan cuma baca kode — lihat §5 untuk detail kenapa ini butuh verifikasi ekstra dan apa yang sempat salah aku simpulkan sebelumnya.

---

## **4\. Urutan Eksekusi (penting buat FE simulasikan skenario gabungan)**

1. Middleware `auth:sanctum` → gagal \= **401**.  
2. Middleware `throttle:5,1` (5 request/menit ke endpoint ini, per klien) → gagal \= **429** (lihat format resmi di §5, ini beda mekanisme dari guard klik-dobel).  
3. Controller: cek `is_superadmin` → gagal \= **403** (ini dicek **sebelum** validasi field).  
4. `$request->validate()` → gagal \= **422**.  
5. Guard klik-dobel (`RateLimiter`, key per user, 1 kali per 2 detik) → gagal \= **dimaksudkan 429, tapi lihat §5**.  
6. Simpan password \+ catat log dalam satu `DB::transaction` → sukses \= **200**.

Konsekuensi praktis: kalau user klik tombol simpan 2x dengan cepat **sambil** field-nya invalid, yang muncul di kedua request adalah 422 (guard klik-dobel di step 5 tidak pernah tersentuh, karena `validate()` di step 4 sudah throw duluan). Guard klik-dobel baru relevan kalau datanya valid.

---

## **5\. Koreksi dari draft sebelumnya (ralat, sudah diverifikasi eksekusi)**

Draft dokumen ini sebelumnya sempat menyimpulkan bahwa response klik-dobel kemungkinan keluar sebagai **500 generik**, bukan 429 — kesimpulan itu **salah**, dan sudah diralat di §3.6 di atas. Ditulis di sini biar jelas apa yang salah dan kenapa, plus apa yang sudah benar-benar diverifikasi sekarang.

**Alasan kesimpulan awal salah:** penelusuran sebelumnya cuma sampai ke `Illuminate\Foundation\Exceptions\Handler::render()` — closure global di `bootstrap/app.php` memang tidak punya pengecekan eksplisit untuk `HttpResponseException` (exception yang dilempar `abort(response()->json(...), kode)`), jadi kelihatannya bakal jatuh ke fallback 500\. **Yang kelewat:** `Illuminate\Routing\Route::run()` (`vendor/laravel/framework/src/Illuminate/Routing/Route.php`) sudah menangkap `HttpResponseException` **duluan**, sebelum request itu sempat sampai ke global exception handler sama sekali:

} catch (HttpResponseException $e) {  
    return $e-\>getResponse();  
}

Jadi `abort(response()->json([...], 429))` di controller memang didesain Laravel untuk short-circuit langsung ke response yang dibungkusnya, gak pernah masuk ke `bootstrap/app.php` — closure global itu cuma kebagian exception yang **bukan** hasil `abort(Response)`, seperti `ValidationException`, `AuthenticationException`, exception biasa dari `abort(kode_integer, 'pesan')`, dsb.

**Cara verifikasi kali ini (bukan cuma baca kode lagi):**

1. Install PHP 8.3 \+ ekstensi (`sqlite3`, `mbstring`, `xml`, `curl`) langsung di sandbox ini.  
2. Jalankan `php artisan test --filter=PengaturanPasswordTest` di kode zip yang persis sama seperti yang dikasih — **14/14 test hijau**, termasuk `test_klik_dobel_hampir_bersamaan_kena_429`.  
3. Karena "test hijau" doang belum cukup buat mastiin body/status persis (test bisa aja salah asersi tapi tetap hijau kalau ada bug lain), aku bikin test terpisah (throwaway, sudah dihapus lagi setelah selesai) yang men-dump mentah-mentah `status()` dan `json()` asli dari tiap skenario, termasuk klik-dobel. Hasilnya persis sama seperti yang ditulis di §3 dokumen ini: request pertama 200, request kedua (dalam 2 detik) beneran 429 dengan body `{"status":"error","message":"Tunggu sebentar sebelum menyimpan lagi.","errors":{}}`.  
4. File project di zip asli tidak diubah permanen — perubahan instrumentasi sempat ditaruh di `bootstrap/app.php` cuma buat debug, langsung dikembalikan persis seperti semula (dicek `diff` kosong) setelah selesai.

**Kesimpulan final (terverifikasi, bukan tebakan lagi):** §3.6 di atas akurat. Tidak ada bug status-code di jalur ini. Satu-satunya hal yang tadinya aku tulis "perlu dikonfirmasi" sekarang sudah confirmed sesuai kode \+ hasil eksekusi asli.

Efek lain yang juga terkonfirmasi: request kedua yang ditolak (dalam jendela 2 detik) tidak menyentuh database sama sekali — password tetap yang lama, tidak ada partial write.

---

## **6\. Kode Error — Ringkasan Lengkap**

| Skenario | Status | Kapan muncul |
| :---- | :---- | :---- |
| Sukses | 200 | Password valid, bukan superadmin, tidak kena rate limit apapun |
| Belum login / sesi habis | 401 | `auth:sanctum` gagal |
| CSRF token hilang/salah | 419 | Request PUT tanpa siklus `sanctum/csrf-cookie` yang benar |
| Superadmin | 403 | User yang login `is_superadmin = true` — dicek sebelum validasi field |
| Password kosong/spasi doang | 422 | `errors.password` \= "Password wajib diisi." |
| Password \< 8 karakter | 422 | `errors.password` \= "Password minimal berisi 8 karakter." |
| Konfirmasi tidak cocok/tidak dikirim | 422 | `errors.password` \= "Konfirmasi password tidak cocok." |
| Lebih dari 5 request/menit ke endpoint ini | 429 | Middleware route `throttle:5,1` — pesan "Terlalu banyak percobaan. Coba lagi sebentar lagi." (jalur ini **tidak** kena bug §5, karena `ThrottleRequestsException` dicek eksplisit di handler) |
| Klik dobel dalam 2 detik | 429, pesan "Tunggu sebentar sebelum menyimpan lagi." (terverifikasi eksekusi, lihat §5) | Guard `RateLimiter` per user, hanya kalau data valid & superadmin-check lolos |

---

## **7\. Business Rules Inti (bahasa manusia)**

1. **Ini halaman "atur password", bukan "ganti password".** Tidak pernah ada field/verifikasi password lama, baik saat pengisian pertama kali maupun pengisian ulang. Kalau lupa password, jalur pemulihannya bukan lewat halaman ini — user tetap bisa masuk lewat SSO dari sistem SOPP lama, tidak ada mekanisme "forgot password" (reset link/OTP) di sistem baru ini.  
2. **Cuma bisa buat diri sendiri.** Tidak ada cara — lewat endpoint ini — bagi siapa pun (termasuk admin/Bu Wiwik) untuk mengatur password milik user lain.  
3. **Superadmin dikecualikan total** dari fitur self-service ini; akun itu memang bukan buat dipakai sehari-hari, dan gantinya cuma lewat tinker manual di server, bukan lewat UI apa pun.  
4. **Sesi lain tidak ikut logout** setelah password diganti — ini keputusan sadar, bukan celah yang belum ditangani, jadi FE tidak perlu menampilkan pesan "sesi lain akan keluar" atau semacamnya.  
5. **Ganti password dicatat sebagai jejak audit**, tapi isinya cuma menandai apakah ini pengisian pertama kali atau pengisian ulang — tidak pernah menyimpan nilai password dalam bentuk apa pun (plaintext maupun hash) di log.

# Dashboard Admin

# **Kontrak API — Dashboard Admin**

---

## **1\. Ringkasan endpoint**

| Method | `GET` |
| :---- | :---- |
| **Path** | `/api/v1/admin/dashboard` |
| **Auth** | Wajib — header `Authorization: Bearer <token>` (Sanctum). Tanpa token valid → **401** sebelum kode controller sempat jalan sama sekali. |
| **Otorisasi tambahan** | Wajib salah satu dari 3 kapabilitas (lihat §3). Gagal → **403**. |
| **Rate limit** | Cuma berlaku kalau `refresh=true` dikirim (lihat §5). |
| **Response** | Selalu JSON, tidak ada pagination — semua loket relevan buat 1 tanggal dikirim sekaligus. |

Urutan pengecekan di server (penting buat FE tau mana yang duluan gagal): **auth token → otorisasi kapabilitas → validasi query param → rate-limit refresh (kalau `refresh=true`) → ambil data**. Jadi kalau token invalid DAN param `tanggal` juga salah format, yang muncul tetap 401 (bukan 422\) — validasi param gak pernah sempat jalan kalau auth udah gagal duluan.

---

## **2\. Request contract**

### **Query parameter**

| Param | Wajib? | Tipe / format | Default | Validasi |
| :---- | :---- | :---- | :---- | :---- |
| `tanggal` | Tidak | String `YYYY-MM-DD` | Hari ini (Asia/Jakarta) | Harus format `Y-m-d` persis, dan **tidak boleh tanggal di masa depan** (`before_or_equal:today`). Query string kosong (`?tanggal=`) diperlakukan SAMA seperti tidak dikirim sama sekali (dipakaikan default hari ini, bukan error). |
| `refresh` | Tidak | Boolean-ish (`true`/`1`/`false`/`0`/tidak dikirim) | `false` | Tidak ada validasi format ketat — dibaca lewat `$request->boolean()`, jadi nilai apapun selain yang dikenali "true" (`1`,`true`,`on`,`yes`, case-insensitive) dianggap `false`. |

Tidak ada body request — semuanya lewat query string. Tidak ada parameter lain (tidak ada filter loket, tidak ada filter teller, tidak ada pagination param — beda dari endpoint Laporan).

### **Tidak ada batas bawah tanggal**

Beda dari endpoint Laporan (yang punya batas rentang \~366 hari), endpoint ini **tidak membatasi seberapa jauh ke belakang** `tanggal` boleh diminta. Cuma dilarang ke masa depan.

---

## **3\. Siapa yang boleh mengakses (otorisasi)**

Setelah lolos auth token, harus lolos salah satu dari 3 syarat ini (OR, bukan AND):

1. `is_superadmin = true`, ATAU  
2. `bisa_akses_laporan = true` (kapabilitas yang di-assign manual per-user lewat menu Hak Akses), ATAU  
3. `bisa_edit_setoran_semua_loket = true`.

Kalau user tidak punya satupun dari 3 kapabilitas di atas → **403**, walau dia tetap user yang sah/berhasil login. Kombinasi ini FINAL dan tidak berubah tergantung parameter apapun di request (beda dengan endpoint lain yang mungkin punya gate lebih granular per aksi).

---

## **4\. Response contract — sukses (200)**

### **Bentuk lengkap**

{  
  "status": "ok",  
  "data": {  
    "tanggal": "2026-09-08",  
    "bridge\_gagal": false,  
    "ringkasan": {  
      "total\_loket": 3,  
      "sudah\_submit": 1,  
      "belum\_setor": 1,  
      "ada\_selisih": 1  
    },  
    "items": \[  
      {  
        "loket\_id": 1,  
        "nama\_loket": "Loket A",  
        "status": "submitted",  
        "total\_pendapatan\_sopp": 100000,  
        "total\_setor": 80000,  
        "selisih": 20000,  
        "teller": \["Zaki"\],  
        "detail\_teller": \[  
          { "nama": "Zaki", "setoran\_id": 1, "status\_workflow": "submitted" }  
        \]  
      },  
      {  
        "loket\_id": 2,  
        "nama\_loket": "Loket B",  
        "status": "draft",  
        "total\_pendapatan\_sopp": 0,  
        "total\_setor": null,  
        "selisih": null,  
        "teller": \[\],  
        "detail\_teller": \[  
          { "nama": "Andi", "setoran\_id": 2, "status\_workflow": "draft" }  
        \]  
      },  
      {  
        "loket\_id": 3,  
        "nama\_loket": "Loket C",  
        "status": "belum\_input",  
        "total\_pendapatan\_sopp": 25000,  
        "total\_setor": null,  
        "selisih": null,  
        "teller": \[\],  
        "detail\_teller": \[  
          { "nama": "Citra Bridge", "setoran\_id": null, "status\_workflow": "belum\_input" }  
        \]  
      }  
    \]  
  }  
}

*(Body ini bukan contoh karangan — persis output dari test run beneran di sandbox, 3 loket dengan 3 kondisi berbeda sengaja dipilih supaya semua kombinasi kelihatan sekaligus.)*

### **Field-by-field**

| Field | Tipe | Bisa `null`? | Penjelasan |
| :---- | :---- | :---- | :---- |
| `status` (top-level) | string | Tidak | Selalu `"ok"` untuk response sukses. |
| `data.tanggal` | string `Y-m-d` | Tidak | Tanggal yang efektif dipakai (hasil default kalau `tanggal` tidak dikirim). |
| `data.bridge_gagal` | boolean | Tidak | `true` kalau request ke sistem lama (bridge) gagal TOTAL untuk tanggal ini (lihat §5.7). Flag GLOBAL untuk seluruh response, bukan per-loket. |
| `data.ringkasan.total_loket` | integer | Tidak | Jumlah loket yang tampil di `items[]` — BUKAN jumlah semua loket terdaftar di sistem. |
| `data.ringkasan.sudah_submit` | integer | Tidak | Jumlah loket dengan `status="submitted"`. |
| `data.ringkasan.belum_setor` | integer | Tidak | Jumlah loket dengan `status="belum_input"`. |
| `data.ringkasan.ada_selisih` | integer | Tidak | Jumlah loket dengan `selisih` BUKAN `null` DAN BUKAN `0`. |
| `data.items[].loket_id` | integer | Tidak | ID loket. |
| `data.items[].nama_loket` | string | **Ya** | `null` kalau `loket_id` ini gak ketemu di tabel `lokets` lokal (data loket ketinggalan sinkron) — jarang terjadi tapi FE harus siap. |
| `data.items[].status` | string enum | Tidak | Salah satu dari `"draft"`, `"submitted"`, `"belum_input"`. Lihat aturan prioritas di §5.2. |
| `data.items[].total_pendapatan_sopp` | integer | **Ya** — tapi HANYA kalau `bridge_gagal=true` | Lihat catatan penting di §5.3 — `0` dan `null` punya arti BEDA di sini. |
| `data.items[].total_setor` | integer | **Ya** — kalau tidak ada baris `submitted` di loket ini | Lihat §5.4. |
| `data.items[].selisih` | integer (bisa negatif) | **Ya** — kalau salah satu dari 2 field di atas null | `total_pendapatan_sopp - total_setor`. |
| `data.items[].teller` | array of string | Tidak (bisa array kosong `[]`) | Nama teller yang SUDAH submit di loket ini, unik, terurut alfabetis. |
| `data.items[].detail_teller[].nama` | string | **Ya** | Nama teller (dari data lokal atau dari sistem lama). |
| `data.items[].detail_teller[].setoran_id` | integer | **Ya** — `null` kalau `status_workflow="belum_input"` | ID baris `setoran_harian`, dipakai FE buat navigasi ke halaman Detail Setoran. |
| `data.items[].detail_teller[].status_workflow` | string enum | Tidak | `"draft"`, `"submitted"`, atau `"belum_input"`. |

**Field yang SENGAJA TIDAK ADA** di `detail_teller[]`: tidak ada nominal/jumlah dalam bentuk apapun (lihat §5.5).

### **Urutan array**

* `items[]` terurut **ascending by `loket_id`** (bukan alfabetis nama, bukan besar-kecil nominal).  
* `detail_teller[]` di dalam tiap item punya urutan sendiri, lihat §5.5.

---

## **5\. Business rules inti (bahasa manusia)**

Bagian ini isinya kondisi-kondisi yang **tidak kelihatan dari tipe data doang** — ini yang paling penting buat FE (dan siapapun yang integrasi ke endpoint ini) baca sampai habis.

### **5.1 Loket mana saja yang muncul di `items[]`**

Loket muncul kalau **salah satu** dari dua hal ini benar untuk tanggal yang diminta:

* Ada minimal 1 baris data di sistem lama (bridge) untuk loket itu, ATAU  
* Ada minimal 1 baris `setoran_harian` LOKAL untuk loket itu — **apapun statusnya, termasuk yang masih draft**.

Loket yang gak punya aktivitas di keduanya **tidak muncul sama sekali** di `items[]` (bukan muncul dengan nilai kosong/nol). Ini kenapa `ringkasan.total_loket` bisa lebih kecil dari jumlah total loket yang terdaftar di sistem.

### **5.2 Aturan `status` per loket — draft selalu menang**

Kalau di 1 loket ada CAMPURAN baris draft dan submitted dari teller-teller berbeda:

* **Ada minimal 1 draft (siapapun tellernya) → status loket \= `"draft"`**, walau ada teller lain di loket yang sama yang sudah `"submitted"`.  
* Baru kalau TIDAK ADA draft sama sekali, dicek: ada `submitted` → `"submitted"`.  
* Kalau loket itu gak punya baris lokal sama sekali (tapi tetap muncul karena ada di bridge) → `"belum_input"`.

Urutan prioritas: **`draft` \> `submitted` \> `belum_input`** — dari "paling belum selesai" ke "paling selesai".

### **5.3 `total_pendapatan_sopp` — `0` dan `null` PUNYA ARTI BEDA (paling gampang salah tebak)**

* **`null`**: bridge (sistem lama) gagal TOTAL dipanggil untuk tanggal ini. Berlaku ke SEMUA loket di response yang sama (karena `bridge_gagal` itu flag global).  
* **`0`** (angka, bukan null): bridge BERHASIL dipanggil, tapi loket ini kebetulan tidak punya baris data apapun di bridge untuk tanggal ini — biasanya karena loket ini cuma punya aktivitas draft yang belum pernah "kelihatan" di sistem lama. Ini **bukan error dan bukan "tidak diketahui"** — ini beneran nol karena bridge memang tidak punya catatan.

FE **tidak boleh** menganggap `0` di field ini sebagai sinyal error atau data-belum-ada — cek `bridge_gagal` di level atas untuk tahu apakah data pendapatan SOPP bisa dipercaya sama sekali untuk request ini.

### **5.4 `total_setor` — `null` kalau belum ada yang submit, walau ada draft dengan nominal**

* **`null`**: TIDAK ADA baris `submitted` sama sekali di loket ini untuk tanggal ini — walaupun ada baris `draft` yang sudah diisi nominalnya. Nominal draft **tidak pernah** ikut dihitung ke `total_setor`, berapapun besarnya.  
* **Angka** (termasuk `0` kalau memang begitu hasilnya): dihitung dari SUM nominal semua `SetoranDetail` milik baris `setoran_harian` yang **statusnya `submitted`**, digabung dari **SEMUA teller** yang submit di loket+tanggal itu (bukan per-teller — endpoint ini tidak punya filter per-teller sama sekali, beda dari Laporan).

### **5.5 `selisih` dan `detail_teller` — 2 hal soal privasi & urutan**

* `selisih` \= `total_pendapatan_sopp - total_setor`, **HANYA dihitung kalau dua-duanya bukan null**. Kalau salah satu null, `selisih` juga null (tidak bisa "menebak" salah satu sisi).  
* `detail_teller[]` **tidak pernah membawa nominal apapun** — baik dari baris draft maupun submitted. Ini SENGAJA: siapapun yang bisa lihat dashboard ini bisa tahu BAHWA seorang teller sedang draft (nama & status-nya kelihatan), tapi TIDAK bisa tahu BERAPA nominal yang sedang di-draft-kan orang itu.  
* Isi `detail_teller[]` \= gabungan (a) semua baris lokal apapun statusnya (draft & submitted) \+ (b) teller dari sistem lama yang punya data di bridge tapi BELUM punya baris lokal sama sekali di tanggal ini (masuk sebagai `status_workflow="belum_input"`, `setoran_id=null`).  
  * Kalau 1 teller yang sama muncul di bridge maupun sudah punya baris lokal, dia **hanya muncul 1 kali** (dari data lokal) — tidak dobel.  
* **Urutan** `detail_teller[]`: grup "belum selesai" (`draft` \+ `belum_input`, digabung TANPA dibedakan urutan lebih lanjut antara keduanya) selalu di depan, baru grup `submitted` di belakang. Di dalam masing-masing grup, urutan alfabetis by nama (case-insensitive).

### **5.6 Ringkasan 4 kartu — loket draft tidak masuk 2 dari 4 hitungan**

* `sudah_submit` dan `belum_setor` **TIDAK menghitung loket berstatus `draft`**. Jadi kalau `total_loket=10`, `sudah_submit=4`, `belum_setor=3`, sisa 3 loket lainnya berstatus `draft` — jumlah itu HARUS dihitung manual oleh FE sebagai `total_loket - sudah_submit - belum_setor` kalau butuh ditampilkan, karena tidak ada field terpisah untuk "jumlah draft" di `ringkasan`.  
* `ada_selisih` mengecualikan loket yang selisihnya `null` (tidak diketahui) MAUPUN yang selisihnya persis `0`.

### **5.7 Efek `bridge_gagal=true` ke seluruh response**

Kalau sistem lama gagal dihubungi untuk tanggal ini (dan tidak ada cache lama yang bisa dipakai):

* Loket yang tampil di `items[]` HANYA yang punya baris lokal (union di §5.1 otomatis mengecil, karena sisi bridge kosong).  
* SEMUA loket yang tampil akan punya `total_pendapatan_sopp: null` dan `selisih: null`.  
* `total_setor` TETAP bisa terisi normal (murni dari DB lokal, tidak tergantung bridge).  
* `detail_teller` di semua loket TIDAK akan punya entry `status_workflow="belum_input"` (karena tidak ada data bridge untuk dibandingkan) — cuma berisi baris lokal (draft/submitted).

### **5.8 Data ini DIJAMIN sama dengan halaman Laporan (mode harian) untuk tanggal yang sama**

Dashboard Admin dan halaman Laporan (mode=harian, lihat kontrak terpisah untuk chunk itu) membaca dari **cache key yang sama persis** untuk sumber data bridge-nya. Kalau kedua halaman dibuka untuk tanggal yang sama, angka pendapatan SOPP-nya dijamin identik secara teknis (baca cache entry yang sama), bukan cuma "logikanya mirip". Implikasinya: kalau salah satu halaman di-refresh (`refresh=true`), cache-nya ikut ke-update untuk halaman satunya juga.

### **5.9 Tombol Refresh — window 20 detik, per kombinasi user+tanggal**

Kalau `refresh=true`:

* Cache untuk tanggal itu diabaikan, sistem lama dipanggil ulang, dan hasil barunya ditulis ulang ke cache (TTL di-reset).  
* Dibatasi **1 kali per 20 detik**, per kombinasi `(user yang login, tanggal yang diminta)`. Refresh untuk tanggal LAIN oleh user yang sama, atau oleh user LAIN untuk tanggal yang sama, TIDAK ikut kena batas dari refresh sebelumnya.  
* Kalau `refresh` tidak dikirim / `false`, **tidak ada pembatasan apapun** dari sisi ini — endpoint bisa dipanggil berkali-kali tanpa jeda.

### **5.10 Efek penghapusan setoran (fitur Riwayat, chunk lain) ke Dashboard Admin — PENTING**

Model `SetoranHarian` pakai **soft delete**. Endpoint hapus di Riwayat (`DELETE /teller/setoran/riwayat/{id}`, chunk lain) mengizinkan baris **`submitted` SEKALIPUN** dihapus (dengan alasan wajib \+ otorisasi tambahan). Begitu dihapus:

* Baris itu langsung **tidak ikut kehitung SAMA SEKALI** di query Dashboard Admin (soft-delete \= otomatis ke-exclude dari query manapun, tanpa perlu kode tambahan apapun di controller ini).  
* **Kalau teller yang datanya dihapus itu KEBETULAN masih punya rekam jejak di sistem lama (bridge)**: teller itu TIDAK hilang dari `detail_teller`, tapi **statusnya berubah dari `"submitted"` (dengan `setoran_id` terisi) balik jadi `"belum_input"` (`setoran_id: null`)** — persis seolah-olah dia belum pernah input sama sekali di sistem baru. `total_setor` & `selisih` di loket itu ikut jadi `null` lagi (balik kayak kondisi awal sebelum dia submit).  
* **Kalau tidak ada rekam jejak di bridge sama sekali** DAN baris yang dihapus itu satu-satunya aktivitas loket tersebut di tanggal itu (tidak ada baris lokal lain, tidak ada baris bridge): **loket itu hilang total dari `items[]`** (tidak muncul dengan status apapun, `ringkasan.total_loket` ikut berkurang) — bukan cuma "kembali ke belum\_input", tapi benar-benar tidak tampil.  
* Tidak ada mekanisme undo/restore di sistem ini — begitu dihapus, dari sudut pandang Dashboard Admin efeknya permanen (walau di database baris itu masih ada dengan `deleted_at` terisi, untuk keperluan audit log).

Implikasi buat FE: kalau ada laporan "kok data teller X yang tadi udah submit sekarang ilang/balik ke belum\_input di Dashboard Admin", ini **bukan bug** — kemungkinan besar baris itu baru saja dihapus lewat fitur Riwayat oleh teller yang bersangkutan atau admin lain. (Fakta ini terverifikasi lewat pengetesan langsung di sandbox, dua skenario: bridge masih punya rekam vs bridge kosong sama sekali — dua-duanya menghasilkan efek berbeda seperti dijelaskan di atas.)

### **5.11 Cache & kesegaran data (kalau tidak refresh)**

* Tanggal yang sudah lewat ≥2 hari (dihitung dari hari ini, di zona waktu Asia/Jakarta) dianggap "tutup buku" — cache-nya bertahan **30 hari**.  
* Tanggal hari ini, kemarin, atau 2 hari lalu (masih "grace period") — cache-nya cuma bertahan **10 menit**, jadi otomatis diambil ulang dari sistem lama tanpa perlu klik Refresh manapun setelah 10 menit berlalu.

---

## **6\. Kode error — daftar lengkap semua kemungkinan gagal**

| Status | Kapan muncul | Body response (persis, sudah diverifikasi) |
| :---- | :---- | :---- |
| **401** | Tidak ada token / token invalid / sesi habis. Dicek PALING PERTAMA, sebelum apapun yang lain. | `{"status":"error","message":"Belum login atau sesi berakhir.","errors":{}}` |
| **403** | Token valid, tapi user tidak punya satupun dari 3 kapabilitas di §3. | `{"status":"error","message":"Tidak memiliki akses ke halaman ini.","errors":{}}` |
| **422** | `tanggal` dikirim tapi formatnya bukan `Y-m-d`. | `{"status":"error","message":"Validasi gagal.","errors":{"tanggal":["Tanggal tidak cocok dengan format Y-m-d."]}}` |
| **422** | `tanggal` valid formatnya tapi tanggalnya di MASA DEPAN. | `{"status":"error","message":"Validasi gagal.","errors":{"tanggal":["Tanggal harus berisi tanggal sebelum atau sama dengan today."]}}` |
| **429** | `refresh=true` dikirim, dan user yang sama sudah refresh untuk tanggal yang sama dalam 20 detik terakhir. | `{"status":"error","message":"Tunggu beberapa detik sebelum refresh lagi.","errors":{}}` |
| **200** (bukan error, tapi kondisi degradasi) | Sistem lama (bridge) gagal dihubungi & tidak ada cache lama yang valid. | Tetap **200**, BUKAN status error — lihat §5.7 buat efeknya ke isi response. FE harus cek `data.bridge_gagal`, bukan menunggu status HTTP non-200. |
| **500** | Error tak terduga di server (bug, exception yang tidak ditangani). | `{"status":"error","message":"Terjadi kesalahan pada server.","errors":{}}` — pesan SENGAJA generik, tidak membocorkan detail teknis. |
| **503** | Aplikasi sedang mode maintenance (`php artisan down`) — level SELURUH aplikasi, bukan spesifik endpoint ini. Lihat §7. | Halaman maintenance bawaan Laravel, bukan JSON `{status,message,errors}` seperti error lain di tabel ini (middleware ini jalan SEBELUM masuk ke exception handler kustom `bootstrap/app.php`). |

### **Catatan penting soal pesan error 429**

Pesan `"Tunggu beberapa detik sebelum refresh lagi."` ini SPESIFIK cuma buat rate-limit refresh dashboard/laporan (dari trait internal `ThrottlesBridgeRefresh`). Ini BEDA dari pesan generik `"Terlalu banyak percobaan. Coba lagi sebentar lagi."` yang dipakai kalau ada rate-limit di LEVEL ROUTE (middleware `throttle:`, dipakai misalnya di endpoint login) — endpoint Dashboard Admin ini **tidak** dipasangi middleware `throttle:` level route, jadi 429 yang mungkin muncul dari endpoint ini SELALU pesan yang pertama.

### **Yang TIDAK PERNAH muncul dari endpoint ini**

* **404** — tidak ada resource by-ID yang bisa "tidak ditemukan" di endpoint ini (tidak ada `{id}` di path).  
* **400** — tidak dipakai di project ini sama sekali untuk kasus manapun.

---

## **7\. Catatan tambahan (level aplikasi, bukan spesifik chunk ini — tapi relevan)**

Dari pengecekan `bootstrap/app.php`, ada middleware GLOBAL (berlaku ke semua endpoint, bukan cuma Dashboard Admin) yang mempengaruhi behavior yang sudah disebut di atas:

* **`ConvertEmptyStringsToNull`** — ini alasan TEKNIS kenapa `?tanggal=` (string kosong) diperlakukan sama seperti tidak dikirim sama sekali (§2): middleware global ini otomatis mengubah SEMUA input string kosong jadi `null` sebelum masuk ke validasi, jadi rule `nullable` di `tanggal` langsung meloloskannya dan jatuh ke default hari ini. Ini bukan logic khusus yang ditulis di controller ini — berlaku ke semua endpoint di aplikasi.  
* **`TrimStrings`** — semua input string di-trim whitespace-nya otomatis di level global sebelum validasi. Jadi `?tanggal= 2026-09-08` (ada spasi) akan tetap tervalidasi normal.  
* **`PreventRequestsDuringMaintenance`** — kalau aplikasi lagi dalam mode maintenance (`php artisan down`), SEMUA endpoint termasuk ini akan merespons **503**, dan itu terjadi SEBELUM request sempat sampai ke middleware `auth:sanctum` maupun controller manapun. Ini juga bukan sesuatu yang bisa terjadi karena kondisi data/bisnis — murni status operasional server.

Tidak ada middleware CORS/proxy/dsb. lain yang ditemukan mempengaruhi bentuk response endpoint ini secara spesifik di luar 2 poin di atas.

---

# Laporan

# **Kontrak API — Laporan** 

---

## **0\. Konsep dasar yang wajib dipahami sebelum baca endpoint**

### **0.1 Otorisasi (sama di ketiga endpoint)**

Akses dicek via `authorizeAksesHalaman()` — method yang sama persis, di-duplikasi (bukan trait) di kedua controller:

boleh akses kalau: user-\>is\_superadmin \=== true  ATAU  user-\>bisa\_akses\_laporan \=== true

* Tidak login sama sekali → 401\.  
* Login tapi dua-duanya false → 403\.  
* **`is_superadmin` TIDAK otomatis lolos gate `bisa_akses_laporan`** secara terpisah — tapi karena dicek pakai `||`, superadmin tetap lolos lewat kondisi pertama walau `bisa_akses_laporan`\-nya `false` eksplisit di DB (dites eksplisit: `test_boleh_diakses_superadmin_walau_bisa_akses_laporan_false`).  
* Sebaliknya, user biasa yang **bukan** superadmin tapi `bisa_akses_laporan=true` tetap lolos (tidak butuh jadi superadmin).

### **0.2 Tiga mode filter, satu param berbeda tiap mode**

Filter divalidasi di satu tempat (`ValidatesFilterLaporan` trait), dipakai identik oleh ketiga endpoint — "download \= apa yang ditampilkan" berarti resolusi tanggal/mode **wajib sama** di ketiganya, tidak boleh drift.

| Mode | Param wajib | Param yang di-exclude (dibuang total, bukan divalidasi) | Diterjemahkan jadi |
| :---- | :---- | :---- | :---- |
| `harian` | `tanggal` (Y-m-d) | `bulan`, `tanggal_mulai`, `tanggal_akhir` | `tanggal_mulai = tanggal_akhir = tanggal` |
| `bulanan` | `bulan` (Y-m, mentah dari dropdown FE) | `tanggal`, `tanggal_mulai`, `tanggal_akhir` | `tanggal_mulai` \= awal bulan kalender, `tanggal_akhir` \= akhir bulan kalender |
| `rentang` | `tanggal_mulai` (Y-m-d) \+ `tanggal_akhir` (Y-m-d, `>= tanggal_mulai`) | `tanggal`, `bulan` | dipakai apa adanya |

**PENTING — field yang di-exclude BENAR-BENAR dibuang, bukan cuma diabaikan setelah divalidasi:**

* Mode `harian` dengan `tanggal_akhir` yang lebih awal dari `tanggal`, atau bahkan bukan format tanggal sama sekali (`tanggal_akhir=bukan-tanggal`) — **tetap 200 OK**, karena field itu di-exclude dari validasi sebelum sempat dicek `date_format`/`after_or_equal`. Jangan asumsikan FE "aman" ngirim `tanggal_akhir` asal-asalan di mode lain — pengecualian ini **spesifik mode=harian saja**; mode `rentang` tetap divalidasi ketat.  
* Mode `bulanan` dengan `tanggal_mulai` manual ikut dikirim FE (mis. sisa state lama) — diabaikan total, tidak menggantikan `bulan`.

**Bug rollover yang sudah diperbaiki (relevan buat FE kalau ada regresi serupa di masa depan):** penerjemahan `bulan` (YYYY-MM) ke tanggal wajib memaksa `day=1` sebelum di-parse. Kalau hari ini tanggal 31 dan bulan target cuma 28/30 hari, parsing yang salah bisa diam-diam geser ke bulan lain sama sekali (Feb → Maret). Sudah difix \+ ada test regresi (`test_mode_bulanan_bug_rollover_hari_ini_tanggal_31_tidak_menggeser_bulan_target`) — FE tidak perlu kompensasi apapun, disebut di sini murni sebagai konteks kenapa field ini sensitif.

### **0.3 `loket_id` — penentu Bentuk A vs B vs C**

| `loket_id` | mode | Bentuk | Isi |
| :---- | :---- | :---- | :---- |
| kosong (null) | apa saja | **A** | 1 baris per LOKET, ter-agregat atas seluruh periode yang diminta |
| terisi | `harian` | **C** | 1 baris ringkasan (1 hari) \+ rincian transaksi per metode pembayaran |
| terisi | `bulanan` / `rentang` | **B** | 1 baris per TANGGAL dalam periode |

* `loket_id` divalidasi harus ada di tabel `lokets` — kalau tidak ditemukan → **422** (bukan 403/404).  
* Deteksi Bentuk A/S3-vs-S1S5 (dan B-vs-C) **murni dari `mode`**, bukan dari kebetulan lebar tanggal (mis. `rentang` dengan `tanggal_mulai == tanggal_akhir` tetap Bentuk A/B biasa, TIDAK ikut ke jalur "1 hari" Bentuk A yang berbeda sumber datanya, dan tetap Bentuk B walau kebetulan cuma 1 hari — bukan Bentuk C). Ini penting kalau FE pernah berasumsi "mode=rentang 1 hari \= sama kayak mode=harian" — **tidak sama**, keduanya bisa menghasilkan bentuk/field berbeda kalau suatu saat ada perbedaan lain ditambahkan.

### **0.4 `teller_id`**

* Tipe **string** (bukan integer) — ini id legacy sistem lama (`user_id_sopp`), bukan primary key lokal.  
* **Wajib kosong kalau `loket_id` kosong** — mengirim `teller_id` tanpa `loket_id` → **422** pada field `teller_id` (bukan `loket_id`). Ini berlaku juga di `/download` walau `/download` punya guard `loket_id` wajib sendiri — kalau keduanya (`teller_id` ada, `loket_id` tidak ada) sama-sama kosong, error yang balik adalah soal `teller_id` (guard umum jalan lebih dulu dari guard khusus download).  
* Kalau terisi bersama `loket_id`, dipakai untuk memfilter baris/drill-down ke 1 teller saja di Bentuk B/C. **Bentuk A tidak punya konsep filter per-teller** (karena `loket_id`\-nya kosong, `teller_id` otomatis prohibited).

### **0.5 Refresh manual (`?refresh=true`)**

* Tersedia di **ketiga** endpoint (`index`, `export`, `download`), pola sama persis dengan `SetoranEntryController`/`PelunasanController` — bukan mekanisme baru khusus Laporan.  
* Kegunaan: data sistem lama (bridge) di-cache; refresh memaksa fetch ulang bypass cache untuk kombinasi filter yang sedang dibuka.  
* **Rate limit: 1 kali per 10 detik**, per kombinasi `user_id + mode + loket_id (atau 'semua' kalau kosong) + tanggal_mulai + tanggal_akhir`. Melebihi → **429**.  
* **Budget rate-limit ini DIBAGI lintas `index`/`export`/`download`** untuk kombinasi filter yang identik — refresh dari layar Laporan lalu langsung refresh dari tombol Export dengan filter yang sama akan kena 429 di percobaan kedua, bukan dapat budget terpisah.  
* Request **tanpa** `refresh=true` sama sekali **tidak kena rate limit ini** — hanya aksi refresh eksplisit yang dibatasi, traffic baca biasa bebas.  
* **PENTING soal `/export`**: karena 1 request export bisa memecah jadi banyak sheet per bulan (lihat §3.3), 1 klik refresh di `/export` bisa memicu bridge refetch SEBANYAK jumlah bulan dalam rentang (bisa \~12x untuk rentang setahun) dalam **satu** request HTTP — throttle di atas hanya mencegah spam klik-nya, bukan mengecilkan beban di dalam 1 klik itu sendiri.  
* Tidak ada rate limit umum lain (bukan `throttle:X,1` Laravel biasa) yang terpasang di route Laporan — beda dari chunk Riwayat yang punya limit per-menit di level route. Satu-satunya limit di Laporan adalah refresh 10-detik ini.

> **⚠️ PERLU VERIFIKASI EMPIRIS — bug kandidat, belum dijalankan test-nya:** `ThrottlesBridgeRefresh` melempar `abort(response()->json([...], 429))` — karena argumennya objek `Response` (bukan integer kode), ini jadi exception `HttpResponseException`, bukan `HttpException`. Handler exception global di `bootstrap/app.php` cuma punya branch eksplisit untuk `ValidationException`/`AuthenticationException`/`ModelNotFoundException`/`ThrottleRequestsException` (built-in Laravel, beda class)/`AccessDeniedHttpException`/`HttpExceptionInterface` — **`HttpResponseException` tidak match manapun** (dicek: class ini `extends RuntimeException`, bukan implementasi `HttpExceptionInterface`), sehingga jatuh ke fallback terakhir di closure tsb dan balik **500** (`"Terjadi kesalahan pada server"`), bukan **429** seperti yang dimaksud. Test terkait (`test_refresh_mode_sama_tanggal_sama_KEDUA_kali_tetap_kena_429` dan yang serupa di export/download) mengasumsikan 429 — kalau analisis ini benar, test itu seharusnya gagal. Belum bisa dibuktikan 100% empiris di sesi ini (PHP tidak tersedia untuk menjalankan test), jadi **FE sebaiknya jangan hardcode asumsi 429 untuk skenario refresh** sampai ini dikonfirmasi jalanin test asli — kalau ternyata memang 500, FE perlu treat percobaan refresh kedua yang gagal sebagai error generik (retry-able), bukan pesan spesifik "tunggu beberapa detik".

### **0.6 Kegagalan bridge (sistem lama) BUKAN error HTTP**

Kalau bridge (sistem SOPP lama) gagal diambil datanya, response **tetap 200 OK** — bukan 500/503. Flag `bridge_gagal: true` muncul di payload, dan field finansial yang terdampak (`vendors`, `total_pendapatan_sopp`, `selisih`) diisi `null` (bukan 0, bukan angka partial) untuk baris/periode yang terdampak. Detail granularitas per Bentuk ada di §1–§3.

---

## **1\. `GET /api/v1/admin/laporan` (index — tampilan layar)**

### **Mekanis**

* **Method & Path**: `GET /api/v1/admin/laporan`  
* **Auth**: wajib login (`auth:sanctum`). Tanpa sesi valid → **401**.  
* **Otorisasi**: lihat §0.1 → gagal **403**.  
* **Rate limit**: tidak ada limit umum per-menit di route ini. Hanya `?refresh=true` yang dibatasi 1x/10 detik (lihat §0.5) → **429**.

### **Request (query params)**

| Field | Tipe | Wajib | Validasi |
| :---- | :---- | :---- | :---- |
| `mode` | string | ya | `harian` | `bulanan` | `rentang` |
| `tanggal` | string tanggal | wajib **hanya** kalau `mode=harian` | `Y-m-d` |
| `bulan` | string | wajib **hanya** kalau `mode=bulanan` | `Y-m` |
| `tanggal_mulai` | string tanggal | wajib **hanya** kalau `mode=rentang` | `Y-m-d` |
| `tanggal_akhir` | string tanggal | wajib **hanya** kalau `mode=rentang` | `Y-m-d`, `>= tanggal_mulai` (lihat pengecualian mode=harian di §0.2) |
| `loket_id` | integer | tidak | harus ada di tabel `lokets` — kalau tidak → **422** |
| `teller_id` | string | tidak, **terlarang kalau `loket_id` kosong** | lihat §0.4 |
| `page` | integer | tidak | minimal 1, default 1 — **hanya berpengaruh kalau hasilnya Bentuk B** (lihat §1.2) |
| `refresh` | boolean | tidak | lihat §0.5 |

**Batas rentang**: `tanggal_mulai` s/d `tanggal_akhir` hasil resolusi (apapun mode-nya) maksimal **366 hari**. Lebih dari itu → **422** pada field `tanggal_akhir`. Persis 366 hari diterima. *(Batas ini KHUSUS `index()` — `/export` dan `/download` punya batas lebih ketat, lihat §3.)*

### **Response — sukses (200 OK)**

{  
  "status": "ok",  
  "data": { "bentuk": "A" | "B" | "C", "...": "lihat §1.1/1.2/1.3" }  
}

#### **1.1 Bentuk A (`loket_id` kosong) — 1 baris per loket**

{  
  "bentuk": "A",  
  "vendors": \["Finnet", "PUSKUD", "..."\],  
  "bridge\_gagal": false,  
  "items": \[  
    {  
      "loket\_id": 1,  
      "nama\_loket": "Loket A",  
      "vendors": { "Finnet": 500000, "PUSKUD": 0 },  
      "metode\_setoran": { "transfer": 0, "qris": 0, "tunai": 0, "kpay": 0 },  
      "total\_pendapatan\_sopp": 500000,  
      "total\_setor": 0,  
      "selisih": 500000,  
      "status\_keuangan": "belum\_lunas",  
      "drill\_down\_teller": null  
    }  
  \],  
  "total\_row": { "...": "shape sama seperti 1 item, kolom pertama diganti label" }  
}

Catatan field:

* `vendors` **selalu lengkap** semua key yang ada di `vendors` root (nihil \= `0`, tidak sparse) — **kecuali** kalau bridge gagal untuk baris itu, jadi `null` (bukan 0 semua) — lihat §0.6.  
* `drill_down_teller`: **hanya applicable & terisi kalau `mode=harian`** (S3) — array `[{nama, setoran_id, status_workflow}]` per loket. Kalau `mode=bulanan`/`rentang` (S1/S5, span banyak hari), field ini **`null`** (bukan array kosong) — sengaja dibedakan dari "applicable tapi kebetulan nihil".  
  * `status_workflow` bernilai `"submitted"` untuk baris yang sudah ada row lokal, atau **`"belum_input"`** untuk teller yang submit di sistem lama tapi belum punya row lokal sama sekali di tanggal itu (`setoran_id: null` pada kasus ini). *(Open item dari kode: nilai placeholder ini belum final dikonfirmasi ke FE — pakai dengan hati-hati.)*  
* Loket yang tampil **dinamis** — hanya loket yang punya transaksi (bridge atau setoran lokal) di periode ini, bukan seluruh isi tabel `lokets`.  
* `total_row` dihitung dari **SELURUH** item (tidak ada paging di Bentuk A). Kalau ada **satu saja** item dengan `total_pendapatan_sopp: null` (bridge gagal), maka `total_row.total_pendapatan_sopp` dan `.selisih` **JUGA null** (tidak menjumlahkan yang tersedia dan pura-pura lengkap) — laporan finansial, angka partial dianggap lebih berbahaya daripada terang-terangan "tidak lengkap". Aturan sama untuk `vendors` di `total_row`.

#### **1.2 Bentuk B (`loket_id` terisi, mode `bulanan`/`rentang`) — 1 baris per tanggal**

{  
  "bentuk": "B",  
  "nama\_loket": "Loket A",  
  "vendors": \["Finnet", "..."\],  
  "bridge\_gagal": false,  
  "items": \[  
    {  
      "tanggal": "2026-09-01",  
      "vendors": { "Finnet": 100000 },  
      "metode\_setoran": { "transfer": 0, "qris": 0, "tunai": 0, "kpay": 0 },  
      "total\_pendapatan\_sopp": 100000,  
      "total\_setor": 0,  
      "selisih": 100000,  
      "status\_keuangan": "belum\_lunas",  
      "drill\_down\_teller": \[ { "nama": "...", "setoran\_id": 5, "status\_workflow": "submitted" } \]  
    }  
  \],  
  "total\_row": { "...": "sama shape 1 item, dihitung dari SELURUH periode (bukan 1 halaman)" },  
  "teller\_tersedia": \[ { "teller\_id": "U001", "nama": "..." } \],  
  "teller\_tanpa\_atribusi\_bridge": \[ { "teller\_id": "U002", "nama": "..." } \],  
  "meta": {  
    "current\_page": 1,  
    "per\_page": 8,  
    "total\_items": 10,  
    "total\_pages": 2  
  }  
}

Catatan field:

* Ini **satu-satunya Bentuk yang dipaginasi** — 8 item/halaman. `page` dari query dipakai persis di sini (`meta.current_page`); tidak dikirim → default 1\.  
* `drill_down_teller` di sini **selalu applicable** (1 baris \= 1 hari) dan **wajib pakai versi yang sudah difilter `teller_id`** (kalau `teller_id` aktif) — bukan versi sebelum filter. Kalau `teller_id` aktif, field ini otomatis collapse jadi 0 atau 1 elemen saja (data sudah di-scope ke 1 orang), bukan tetap menampilkan semua teller lalu FE yang menyaring.  
* `vendors`/`total_pendapatan_sopp`/`selisih` di-null-in **per baris tanggal** kalau bridge gagal **untuk hari itu** — granularitas per-hari, bukan per-periode seperti Bentuk A (1 hari gagal tidak menghitamkan hari lain yang berhasil dalam periode yang sama).  
* `total_row`, `teller_tersedia`, `teller_tanpa_atribusi_bridge` **selalu dihitung dari SELURUH periode**, tidak dari isi 1 halaman — pindah halaman tidak mengubah angka-angka ini.  
* `teller_tersedia`: daftar teller yang terlihat di data **bridge** untuk seluruh periode ini (dropdown filter teller di FE diambil dari sini, bukan dari tabel user lokal).  
* `teller_tanpa_atribusi_bridge`: teller yang **punya row setoran lokal** submitted di periode ini tapi **tidak muncul** di data bridge (mis. data lama belum sinkron) — dipakai FE untuk menandai teller yang "hilang" dari sisi rekonsiliasi.  
* `meta: null` kalau dipanggil lewat `/export`/`/download` (paginasi dimatikan) — **tidak relevan untuk `index()`**, `index()` selalu paginasi. Disebut di sini karena shape objek Bentuk B dipakai bersama.

#### **1.3 Bentuk C (`loket_id` terisi \+ mode `harian`) — 1 hari, rincian penuh**

{  
  "bentuk": "C",  
  "nama\_loket": "Loket A",  
  "tanggal": "2026-09-01",  
  "vendors": { "Finnet": 100000 },  
  "metode\_setoran": { "transfer": 50000, "qris": 0, "tunai": 0, "kpay": 0 },  
  "total\_pendapatan\_sopp": 100000,  
  "total\_setor": 50000,  
  "selisih": 50000,  
  "status\_keuangan": "belum\_lunas",  
  "bridge\_gagal": false,  
  "rincian\_transaksi": {  
    "transfer": \[ { "id": 1, "metode\_pembayaran": "transfer", "keterangan": "...", "nominal": 50000, "foto\_bukti\_url": "https://...", "is\_konfirmasi": false, "nama\_karyawan": null, "identitas\_karyawan": null } \],  
    "qris": \[\], "tunai": \[\], "kpay": \[\]  
  },  
  "teller\_tersedia": \[ { "teller\_id": "U001", "nama": "..." } \],  
  "teller\_tanpa\_atribusi\_bridge": \[\]  
}

Catatan field:

* **Tidak ada `drill_down_teller`** di Bentuk C — keputusan final, tidak ada atribusi teller per baris transaksi di sini.  
* `rincian_transaksi`: dikelompokkan **per metode pembayaran saja** (bukan per teller). Shape tiap entry sama seperti field entry di Card A/Detail Setoran (`SetoranDetail::toApiShape()`), termasuk `foto_bukti_url`.  
* **`kpay` tidak pernah punya `foto_bukti_url`** — selalu `null` untuk metode ini (aturan sistem: kpay tidak mensyaratkan bukti foto). Sebaliknya, `nama_karyawan`/`identitas_karyawan` **hanya** terisi untuk entry `kpay`, `null` untuk 3 metode lain.  
* `vendors` di sini objek langsung (bukan dibungkus di `items[]`) karena memang cuma 1 baris.

### **Response — gagal**

| Status | Skenario |
| :---- | :---- |
| **401** | Tidak ada sesi valid |
| **403** | Login tapi tidak punya `is_superadmin` maupun `bisa_akses_laporan` |
| **422** | `mode` bukan salah satu dari 3 nilai valid; param wajib mode tsb tidak dikirim/format salah (`tanggal`/`bulan`/`tanggal_mulai`\+`tanggal_akhir`); `tanggal_akhir < tanggal_mulai` (mode `rentang`/`bulanan` saja — mode `harian` dikecualikan, lihat §0.2); rentang hasil resolusi \> 366 hari; `loket_id` dikirim tapi tidak terdaftar di `lokets`; `teller_id` dikirim tanpa `loket_id` |
| **429** | `refresh=true` dikirim 2x dalam window 10 detik untuk kombinasi filter yang sama (lihat §0.5) — body: `{"status":"error","message":"Tunggu beberapa detik sebelum refresh lagi.","errors":{}}` |

### **Aturan bisnis lain (index khusus)**

* **Activity log** `laporan_dilihat` **selalu** ditulis setiap request sukses (200) — beda dari chunk Riwayat yang cuma log mode lintas-loket, Laporan mencatat semua akses termasuk Bentuk A/personal. Detail yang dicatat: `mode`, `loket_id`, `tanggal_mulai`, `tanggal_akhir`, `teller_id`, `refresh` (boolean). **Ditulis walau bridge gagal** (kegagalan bridge bukan alasan skip log — request tetap dianggap "berhasil dibuka").

---

## **2\. `GET /api/v1/admin/laporan/export` (unduh semua-loket boleh, xlsx)**

### **Mekanis**

* **Method & Path**: `GET /api/v1/admin/laporan/export`  
* **Auth & Otorisasi**: identik §0.1/index → **401**/**403**.  
* **Response sukses**: bukan JSON — file **.xlsx** (`Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`), dikirim sebagai `streamDownload`.  
* **`loket_id` BOLEH kosong** di endpoint ini (beda dari `/download`) — Bentuk A (semua loket) adalah skenario sah untuk export.

### **Request**

Filter query params **sama persis** dengan `index()` (§1, tabel request) **KECUALI**:

* **Tidak ada `page`** — export selalu mengambil **seluruh** hasil filter, tidak dipaginasi (`paginasi=false` diteruskan ke service; kalau hasilnya Bentuk B, `meta: null` internal dan **semua** tanggal ikut ke file, bukan cuma 8 pertama — dites eksplisit `test_export_paginasi_false_semua_tanggal_ikut_bukan_cuma_8`).  
* **Batas rentang LEBIH KETAT: maksimal 90 hari** (bukan 366 seperti index) — lihat §3.1 kenapa beda dan detail errornya.

### **Nama file**

`laporan_{loket-atau-'semua-loket'}_{periode}.xlsx` — contoh: `laporan_semua-loket_2026-09-01_sd_2026-09-05.xlsx`, atau `laporan_2026-09-01.xlsx` untuk mode harian (periode tunggal, tanpa `_sd_`).

### **Struktur isi file — lihat §3.2/3.3 (dipakai bersama `/download`)**

### **Response — gagal**

| Status | Skenario |
| :---- | :---- |
| **401** | Tidak ada sesi valid |
| **403** | Bukan superadmin & bukan `bisa_akses_laporan` |
| **422** | Sama seperti index (mode/format/loket\_id tidak valid) **DITAMBAH** batas rentang **90 hari** (bukan 366\) — lihat §3.1 |
| **429** | `refresh=true` 2x beruntun dalam 10 detik untuk kombinasi filter yang sama — **budget dibagi dengan `index()`/`download()`** (§0.5) |

### **Aturan bisnis lain (export khusus)**

* **Activity log** `laporan_diekspor` ditulis 1x per request (bukan per sheet), detail termasuk `jumlah_sheet`.  
* Menambahkan **kolom "Loket"** di Bentuk A (§3.2) — satu-satunya endpoint yang menampilkan kolom ini.

---

## **3\. `GET /api/v1/admin/laporan/download` (unduh 1 loket wajib, xlsx)**

### **Mekanis**

* **Method & Path**: `GET /api/v1/admin/laporan/download`  
* **Auth & Otorisasi**: identik §0.1 → **401**/**403**.  
* **Berbagi \~99% logic dengan `/export`** lewat method internal yang sama — satu-satunya beda: kolom "Loket" dibuang, dan `loket_id` **wajib**.

### **Request**

Sama seperti `/export` (§2), **KECUALI**:

* **`loket_id` WAJIB diisi** — kalau kosong → **422** pada field `loket_id`, pesan: *"Wajib memilih loket untuk mengunduh laporan."* Guard ini dicek **setelah** validasi filter umum lolos (lihat urutan di bawah).  
* Karena `loket_id` selalu terisi, hasil `/download` **selalu Bentuk B atau C** — Bentuk A **tidak pernah** terjadi lewat endpoint ini (kolom "Loket" pada Bentuk A jadi tidak relevan/tidak pernah tereksekusi lewat `/download`).

**Urutan pengecekan validasi (penting kalau 2 pelanggaran terjadi bersamaan):**

1. Validasi filter umum (`ValidatesFilterLaporan`) — termasuk `teller_id` prohibited tanpa `loket_id`.  
2. Guard `loket_id` wajib (khusus `/download`).  
3. Guard batas rentang 90 hari (khusus export/download).

Konsekuensi: request `mode=harian&tanggal=...&teller_id=U001` (loket\_id kosong) ke `/download` tetap balik **422 pada field `teller_id`** (bukan `loket_id`) — guard umum menang duluan meski keduanya sama-sama salah (dites eksplisit: `test_download_teller_id_tanpa_loket_id_tetap_ditolak_422`).

### **Nama file**

Sama pola dengan `/export`, tapi segmen loket **selalu** nama loket asli (tidak pernah `semua-loket`, karena `loket_id` wajib terisi).

### **Response — gagal**

| Status | Skenario |
| :---- | :---- |
| **401** | Tidak ada sesi valid |
| **403** | Bukan superadmin & bukan `bisa_akses_laporan` |
| **422** | Sama seperti `/export` **DITAMBAH** `loket_id` tidak dikirim sama sekali |
| **429** | Sama seperti `/export`, budget dibagi lintas ketiga endpoint |

### **Aturan bisnis lain (download khusus)**

* **Activity log** `laporan_diunduh` (beda jenis aksi dari `laporan_diekspor`), auto-carry `teller_id` yang aktif di filter (tidak butuh logic tambahan — sama field yang divalidasi trait yang sama).

---

## **3.1 Batas rentang KHUSUS export & download — 90 hari (bukan 366\)**

`index()` memakai batas 366 hari (§1). `/export` dan `/download` memakai batas **90 hari**, **lebih ketat**, dicek di titik yang sama-sama dipakai kedua endpoint (bukan 2 tempat terpisah — tidak mungkin drift).

* Kenapa beda: `index()` dipaginasi & boleh gagal-sebagian per halaman tanpa mem-block user. `/export`/`/download` adalah 1 request **sinkron** yang harus selesai utuh sebelum file bisa diunduh — kalau kelamaan, tidak ada "coba lagi nanti", langsung terlihat gagal/hang.  
* Lebih dari 90 hari → **422** pada field `tanggal_akhir`, pesan: *"Rentang unduhan maksimal 90 hari sekali unduh. Pecah jadi beberapa kali unduh untuk periode lebih panjang."*  
* Persis 90 hari diterima.  
* **Angka 90 ini eksplisit ditandai SEMENTARA di kode** (diturunkan dari benchmark manual, belum ada angka pasti dari server produksi) — FE sebaiknya menampilkan pesan generik dari response, jangan hardcode asumsi "90 hari akan selalu jadi limit final".  
* `index()` **tidak** terpengaruh batas ini sama sekali — rentang 91–366 hari tetap **200 OK** di `index()`, tapi **422** kalau dicoba lewat `/export`/`/download` dengan filter yang sama persis.

## **3.2 Struktur isi file Excel (export & download)**

* **Header filter** (baris atas, sebelum tabel data):  
  * `FILTER PERIODE:` — 1 tanggal (kalau rentang efektif 1 hari) atau `"<awal> s/d <akhir>"` (format tanggal Indonesia, mis. "10 Sep 2026").  
  * `FILTER LOKET:` — **hanya muncul kalau `loket_id` terisi** (jadi selalu muncul di `/download`, kondisional di `/export`).  
  * `FILTER TELLER:` — **hanya muncul kalau `teller_id` terisi**. Isinya id legacy mentah (`teller_id`), bukan nama — nama lengkap ada di response `index()` untuk ditampilkan FE, file ini cukup untuk jejak audit.  
* **Bentuk A** (kalau applicable): kolom pertama `Loket` (di `/export` semua-loket) atau dikosongkan-strukturnya-tetap-ada (di `/download`, walau Bentuk A tidak pernah nyata terjadi lewat sana) — lalu kolom vendor dinamis, 4 kolom metode (Transfer/QRIS/Tunai/KPay), Total Pendapatan, Total Setor, Selisih.  
* **Bentuk B**: kolom pertama `Tanggal` menggantikan `Loket`, sisanya sama strukturnya.  
* **Bentuk C**: 1 baris ringkasan (shape sama seperti Bentuk B, cuma 1 baris) diikuti section `RINCIAN {Metode}` per metode yang **punya entry** (metode kosong di-skip, bukan ditulis section kosong) — kolom: Nominal, Keterangan, Nama Karyawan, Identitas Karyawan, Konfirmasi (Ya/Tidak), Link Bukti. **Tidak ada kolom atribusi teller** di rincian ini (konsisten dengan keputusan final tidak ada drill-down per-transaksi di Bentuk C).  
* **Baris `TOTAL SEMUA`**: selalu menggantikan kolom pertama (nama loket / tanggal), berisi SUM tiap kolom angka. Cell yang bernilai `null` (bridge gagal untuk baris/periode itu) ditulis sebagai teks **`"Gagal dimuat"`**, bukan angka 0 atau sel kosong.  
* **Catatan bridge gagal**: kalau `bridge_gagal: true` untuk sheet itu, ditambahkan 1 baris catatan di bawah tabel: *"Catatan: sebagian data dari sistem SOPP lama gagal dimuat untuk periode ini. Baris/kolom yang terdampak ditandai 'Gagal dimuat', tidak diikutkan di Total Semua."*

## **3.3 Split sheet per bulan kalender (export & download)**

* **Bukan opsional** — kalau rentang yang diminta menyebrangi lebih dari 1 bulan kalender, file **harus** berisi 1 sheet per bulan (nama sheet: `"<Nama Bulan> <Tahun>"`, contoh `"September 2026"`), bukan 1 sheet gepeng isi semua tanggal.  
* Sheet pertama/terakhir dipotong pas ke batas rentang asli yang diminta (bukan selalu tanggal 1 s/d akhir bulan penuh); sheet di tengah (kalau ada) selalu 1 bulan kalender utuh. Contoh: 15 Jan–10 Mar → 3 sheet: `[15–31 Jan]`, `[1–28 Feb]`, `[1–10 Mar]`.  
* **Setiap sheet dihasilkan dari pemanggilan data yang independen per bulan** — konsekuensinya:  
  * **Bentuk A**: `total_row` per sheet murni total bulan **itu saja** — BUKAN akumulasi seluruh rentang yang diminta. Daftar vendor dan daftar loket yang muncul juga bisa **berbeda per sheet** (loket/vendor yang aktif di satu bulan belum tentu aktif di bulan lain).  
  * `TOTAL SEMUA` di tiap sheet artinya "semua baris di sheet ini (1 bulan)", **bukan** semua bulan dalam file.  
* Request yang secara alami hanya 1 bulan (termasuk `mode=harian`/`mode=bulanan`, yang memang selalu dalam 1 bulan kalender) otomatis menghasilkan 1 sheet saja, tetap diberi nama bulan (bukan judul generik).  
* **Edge case chunk 1-hari yang kebetulan jatuh di ujung bulan**: Bentuk/mode routing tiap sheet ditentukan dari `mode` filter **asli** (verbatim), bukan dari kebetulan lebar tanggal chunk itu. Jadi rentang 31 Jan–5 Mar dengan `loket_id` terisi tetap menghasilkan sheet Januari sebagai **Bentuk B** (bukan salah route ke Bentuk C hanya karena chunk itu kebetulan cuma 1 hari).

---

## **4\. Ringkasan kode error lintas-endpoint**

| Kode | Skenario | Endpoint |
| :---- | :---- | :---- |
| 401 | Belum login / sesi tidak valid | index, export, download |
| 403 | Bukan superadmin & `bisa_akses_laporan=false` | index, export, download |
| 422 | `mode` invalid; param wajib per-mode tidak dikirim/salah format; `tanggal_akhir < tanggal_mulai` (kecuali mode harian, lihat §0.2); `loket_id` dikirim tapi tidak terdaftar; `teller_id` dikirim tanpa `loket_id` | index, export, download |
| 422 | Rentang hasil resolusi \> **366 hari** | index saja |
| 422 | Rentang hasil resolusi \> **90 hari** | export, download saja |
| 422 | `loket_id` tidak dikirim sama sekali | download saja |
| 429 | `refresh=true` 2x dalam window 10 detik untuk kombinasi `user+mode+loket_id+tanggal_mulai+tanggal_akhir` yang sama — budget dibagi lintas ketiga endpoint | index, export, download (hanya kalau `refresh=true` dikirim) |
| 200 | Sukses — index balikin JSON (`bentuk: A/B/C`); export/download balikin file `.xlsx`. **Kegagalan bridge TIDAK menurunkan ini ke error code** — tetap 200 dengan `bridge_gagal:true` dan field finansial yang terdampak bernilai `null`. | index, export, download |

---

# Hak Akses

# **Kontrak API — Chunk Hak Akses** 

---

## **0\. Ringkasan Umum**

| Base path | `/api/v1` |
| :---- | :---- |
| Middleware | `auth:sanctum` (harus login) — **tidak ada rate-limit/throttle** di kedua endpoint ini |
| Otorisasi tambahan | Dicek manual di dalam controller (bukan middleware terpisah): **`is_superadmin === true` ATAU `bisa_atur_hak_akses === true`**. Gagal → 403\. |
| Endpoint | `GET /api/v1/admin/hak-akses`, `PUT /api/v1/admin/hak-akses` |

**Cara login/auth-nya (berlaku ke semua endpoint di app ini, bukan spesifik Hak Akses):** `bootstrap/app.php` mengaktifkan `statefulApi()`, dan `config/cors.php` men-set `supports_credentials: true` \+ mengizinkan path `sanctum/csrf-cookie`. Ini konfigurasi Laravel Sanctum mode **SPA/stateful** — artinya FE yang jalan dari domain yang terdaftar di `SANCTUM_STATEFUL_DOMAINS` (di `.env` yang ada di zip ini isinya `localhost:5173,localhost:8000` — nilai dev, kemungkinan beda di environment lain) bisa login pakai **cookie session** (ambil CSRF cookie dulu dari `/sanctum/csrf-cookie`, lalu request biasa dengan cookie, TANPA perlu attach header `Authorization: Bearer <token>` manual). Client di luar domain terdaftar (misal mobile app) tetap pakai cara lama: Bearer token biasa. Endpoint Hak Akses sendiri gak punya logic auth khusus di luar `auth:sanctum` — jadi otomatis ikut dua-duanya, tergantung dari domain mana FE-nya jalan.

**Konsep penting yang jadi dasar seluruh kontrak ini:**

* Gak ada kolom `role` yang tersimpan di database. "Role" itu murni hasil turunan dari kombinasi 5 kolom boolean kapabilitas (dan `is_superadmin`). Field `role`/`aktif` yang sempat diusulkan FE **sudah dibatalkan total** (bukan ditunda) — dua-duanya **tidak pernah** diterima atau dikembalikan endpoint ini.  
* Hanya **5 kolom kapabilitas** yang bisa diubah lewat endpoint ini: `bisa_akses_laporan`, `bisa_atur_hak_akses`, `bisa_lihat_log`, `bisa_input_setoran_semua_loket`, `bisa_edit_setoran_semua_loket`.  
* `is_superadmin` **tidak pernah** bisa diubah lewat endpoint ini (dan tidak lewat endpoint manapun di aplikasi ini) — cuma bisa di-set langsung di database (seeder/tinker).

### **⚠️ Envelope response error itu GLOBAL, berlaku sama di SEMUA endpoint `/api/*` (bukan spesifik Hak Akses)**

Ini penting ditulis eksplisit karena ngubah cukup banyak detail di bagian "kode error" bawah: ada **satu exception handler pusat** di `bootstrap/app.php` yang membungkus ULANG setiap error dari route manapun di bawah `/api/*` (jadi bukan Hak Akses doang — berlaku juga ke semua chunk lain) jadi SATU bentuk envelope yang konsisten:

{ "status": "error", "message": "\<pesan\>", "errors": { } }

Aturannya:

* Kalau errornya berasal dari **validasi** (baik validasi struktur payload otomatis via `$request->validate()`, MAUPUN validasi manual yang sengaja di-throw sebagai `ValidationException::withMessages([...])` di controller) → `message` SELALU string generik **`"Validasi gagal."`**, dan detail pesan sebenarnya ada **di dalam `errors`**, per-field, formatnya `{"path.field": ["pesan 1", "pesan 2", ...]}` (tiap key isinya array, walau cuma 1 pesan). Status selalu `422`.  
* Kalau errornya berasal dari `abort(403, 'pesan custom')` / exception HTTP lain yang bukan validasi → `message` diisi **persis** teks yang dilempar di kode, dan `errors` **selalu object kosong `{}`**.  
* Kalau errornya dari sesi tidak login (`AuthenticationException`, dilempar middleware `auth:sanctum`) → `message` selalu **`"Belum login atau sesi berakhir."`**, `errors` kosong `{}`, status `401`.  
* Response yang di-`return response()->json([...])` LANGSUNG dari dalam controller (bukan lewat exception) — ini yang dipakai `HakAksesController` untuk kasus self-edit/duplikat/stale — **tidak disentuh** exception handler ini sama sekali, karena itu bukan exception, jadi bentuknya persis apa yang ditulis di kode controller (kebetulan sudah mengikuti pola envelope yang sama: `{status, message, errors}`).

**Kesimpulan praktis buat FE:** SEMUA response error di endpoint Hak Akses (dan endpoint lain di app ini) selalu punya bentuk `{"status": "error", "message": string, "errors": object}` — tidak pernah ada bentuk lain. Cara baca yang benar: **kalau `errors` isinya kosong `{}`, baca pesannya dari `message`. Kalau `errors` ada isinya, baca pesan per-field dari situ** (dan `message` di kasus itu cuma teks generik "Validasi gagal.", jangan ditampilkan apa adanya ke user). Response sukses TIDAK memakai envelope ini — bentuknya `{"status": "ok", "data": ...}` (tanpa `message`, tanpa `errors`).

---

## **1\. `GET /api/v1/admin/hak-akses` — Daftar user & kapabilitasnya**

### **Mekanis**

**Auth:** wajib login \+ (`is_superadmin` atau `bisa_atur_hak_akses`).

**Query param:**

| Field | Tipe | Wajib | Keterangan |
| :---- | :---- | :---- | :---- |
| `page` | integer, min 1 | Tidak | Default halaman 1 kalau tidak dikirim |

⚠️ **Tidak ada param `per_page`.** Ukuran halaman **selalu tetap 20 item**, dikunci di server (`PER_PAGE = 20`, ditandai di kode sebagai "FINAL, keputusan BE"), tidak bisa dioverride dari FE dengan cara apapun.

**Response sukses — `200`:**

{  
  "status": "ok",  
  "data": {  
    "items": \[  
      {  
        "id": 12,  
        "nama": "Teller Satu",  
        "username": "U00231",  
        "loket": "Loket Satu",  
        "is\_superadmin": false,  
        "bisa\_akses\_laporan": false,  
        "bisa\_atur\_hak\_akses": false,  
        "bisa\_lihat\_log": false,  
        "bisa\_input\_setoran\_semua\_loket": false,  
        "bisa\_edit\_setoran\_semua\_loket": false  
      }  
    \],  
    "meta": {  
      "current\_page": 1,  
      "per\_page": 20,  
      "total\_items": 47,  
      "total\_pages": 3  
    }  
  }  
}

### **Notes bisnis-logic (penting, gak keliatan dari tipe field doang)**

1. **Superadmin TIDAK PERNAH muncul di `items`**, walau mereka tetap tercatat sebagai user aktif di sistem. Filter ini dilakukan di query DB (`WHERE is_superadmin = false`), bukan cuma disembunyikan di tampilan. Konsekuensi langsung: field `is_superadmin` di tiap item **selalu bernilai `false`** — kalau suatu saat FE lihat item dengan `is_superadmin: true` di response ini, itu tanda ada bug, bukan hal yang normal terjadi.  
2. **`username` punya urutan fallback:** diisi dari `user_id_sopp` kalau ada isinya; kalau `user_id_sopp` kosong (`null`), baru fallback ke kolom `username` asli. Praktiknya: user yang berasal dari SSO SOPP lama (mayoritas teller) akan tampil `user_id_sopp`\-nya di sini; user yang dibuat manual tanpa asal-usul SSO akan tampil `username` aslinya.  
3. **`loket`** adalah nama loket (string), bukan ID. Bernilai `null` kalau user itu tidak terhubung ke loket manapun (`loket_id` kosong).  
4. **Urutan list: alfabetis berdasarkan `nama`** (bukan berdasarkan ID atau tanggal dibuat/tanggal berubah).  
5. Semua 5 field kapabilitas boolean yang dikembalikan di sini **BUKAN nilai kolom mentah 1-ke-1** — untuk 3 dari 5 kapabilitas (`bisa_akses_laporan`, `bisa_atur_hak_akses`, `bisa_lihat_log`) nilainya sebenarnya `is_superadmin OR kolom_asli`. Tapi karena poin 1 di atas (superadmin gak pernah nongol di list ini), `is_superadmin` di sisi kiri OR itu **selalu `false`** untuk siapapun yang ada di list — jadi secara praktis, apa yang FE lihat di list ini **selalu sama** dengan nilai kolom asli di database. Ini murni catatan konsistensi, bukan sesuatu yang perlu ditangani khusus oleh FE.

### **Kode error per skenario**

| Skenario | Status | Body persis |
| :---- | :---- | :---- |
| Tidak login / token tidak valid | `401` | `{"status":"error","message":"Belum login atau sesi berakhir.","errors":{}}` |
| Login tapi bukan superadmin & bukan `bisa_atur_hak_akses` | `403` | `{"status":"error","message":"Tidak memiliki akses ke halaman ini.","errors":{}}` |
| `page` dikirim tapi bukan integer / kurang dari 1 | `422` | `{"status":"error","message":"Validasi gagal.","errors":{"page": [...]}}` — isi pesan di dalam array `errors.page` mengikuti teks bawaan Laravel dari `lang/id/validation.php` (app locale project ini \= `id`), TIDAK ada override khusus untuk field ini, jadi teks persisnya ikut format standar terjemahan Laravel (bukan pesan custom buatan controller) |

---

## **2\. `PUT /api/v1/admin/hak-akses` — Ubah kapabilitas user**

### **Mekanis**

**Auth:** sama persis dengan GET di atas (`is_superadmin` atau `bisa_atur_hak_akses`).

**Request body:**

{  
  "perubahan": \[  
    { "id": 12, "nama": "Teller Satu", "tipe": "bisa\_akses\_laporan", "dari": false, "ke": true }  
  \]  
}

| Field | Tipe | Wajib | Keterangan |
| :---- | :---- | :---- | :---- |
| `perubahan` | array | ✅ | Minimal 1 item. Array kosong ditolak. |
| `perubahan[].id` | integer | ✅ | ID user target (bukan `user_id_sopp`/`username`). |
| `perubahan[].nama` | string, nullable | Tidak | **Cuma dipakai buat teks pesan error/log**, TIDAK pernah dipakai untuk mencari/mencocokkan record. Kalau `nama` yang dikirim FE beda dengan nama asli user tsb di DB, tidak menyebabkan error — tapi lihat catatan soal pesan konflik 409 di bawah. |
| `perubahan[].tipe` | string | ✅ | Harus salah satu dari 5 whitelist (lihat §0). Di luar itu ditolak. |
| `perubahan[].dari` | boolean | ✅ | Nilai kapabilitas yang **FE percaya saat ini berlaku** — dipakai sebagai stale-check, lihat Business Rule \#2. Boleh dikirim sebagai `1`/`0` (bukan cuma literal `true`/`false`), tetap diterima sebagai boolean yang setara. |
| `perubahan[].ke` | boolean | ✅ | Nilai baru yang diminta. |

> Catatan kecil: `TrimStrings` & `ConvertEmptyStringsToNull` itu middleware **global bawaan Laravel** yang tetap aktif di route API ini (project gak menonaktifkannya di `bootstrap/app.php`). Jadi kalau `tipe` kebetulan kekirim dengan spasi di pinggir (`" bisa_akses_laporan "`), itu **otomatis ke-trim duluan sebelum divalidasi** — tetap dianggap valid, bukan error.

### **Business Rules (WAJIB dipahami — ini yang gak keliatan dari tipe field doang)**

1. **All-or-nothing, per SATU request PUT.** Kalau ada SATU SAJA item di array `perubahan` yang gagal — apapun sebabnya (duplikat, tipe gak dikenal, self-edit, target superadmin, id gak ketemu, atau `dari` basi) — **SELURUH batch ditolak**, tidak ada satupun item lain yang ikut ke-apply, sekalipun item-item lain itu sendiri valid. FE **tidak boleh** menganggap ini sebagai partial-success dan tidak perlu retry per-item — kalau gagal, kirim ulang seluruh batch setelah masalahnya diperbaiki/di-reload.

2. **`dari` adalah pengecekan basi-data (optimistic lock), bukan sekadar metadata pelengkap.** Server mengunci row user target di database (row lock) lalu **membaca ulang nilai kolom saat itu juga**, dan membandingkannya dengan `dari` yang dikirim FE. Kalau tidak sama → `409`, dan **tidak ada satupun kolom yang berubah** (termasuk item-item lain di batch yang sama, karena aturan \#1). Implikasi langsung buat FE: field `dari` **wajib** selalu diisi dari nilai kapabilitas terakhir yang FE tampilkan di layar (hasil GET terakhir), bukan di-hardcode atau diasumsikan. Kalau ada admin lain yang mengubah data yang sama lebih dulu, request FE akan kena `409` dan FE harus muat ulang data sebelum mencoba lagi.

3. **Tidak bisa mengubah hak akses milik sendiri — berlaku untuk SIAPA PUN, termasuk superadmin.** Kalau ada satu item dengan `id` sama dengan id user yang sedang login, **seluruh batch** ditolak dengan `403` (bukan cuma item itu yang diabaikan).

4. **Superadmin tidak pernah bisa jadi target perubahan.** Ini dijaga dua kali: superadmin memang sudah tidak muncul di GET (jadi FE seharusnya tidak pernah punya kesempatan mengirim id superadmin secara normal), TAPI kalau ada id superadmin yang tetap dikirim (manual/manipulasi/id basi), server tetap menolaknya secara eksplisit di endpoint ini juga.

5. **`id` yang tidak ditemukan di database ditolak eksplisit**, bukan diam-diam diabaikan/dilewati.

6. **Duplikat pasangan (`id`, `tipe`) yang identik dalam satu batch ditolak.** Tapi mengirim `id` yang sama dengan `tipe` yang **berbeda** dalam satu batch itu **sah** — contoh: mengubah `bisa_akses_laporan` DAN `bisa_lihat_log` untuk user yang sama dalam satu kali PUT itu diperbolehkan dan akan sama-sama diterapkan.

7. **Response sukses SELALU `{"status": "ok", "data": null}`** — server tidak pernah mengembalikan data user yang baru diubah. Ini keputusan yang disepakati: karena kombinasi aturan \#1 dan \#2 sudah menjamin bahwa kalau request-nya sukses, state FE (sebelum submit) pasti identik dengan state DB (sebelum diubah) — jadi FE cukup meng-apply nilai `ke` ke state lokalnya sendiri begitu menerima sukses, tidak perlu (dan tidak akan mendapat) data balikan untuk sinkronisasi ulang.

8. **1 baris log aktivitas dicatat PER ITEM PERUBAHAN**, bukan 1 log gabungan per request PUT. Kalau satu kali klik "Simpan" mengubah 2 kapabilitas untuk 1 user, itu akan tercatat sebagai 2 entri log terpisah (relevan kalau FE nanti menampilkan riwayat perubahan hak akses lewat halaman lain).

### **Kode error per skenario**

Semua body di bawah mengikuti envelope global yang dijelaskan di §0 (`{"status":"error","message":...,"errors":...}`). Kolom "asal" menandai apakah `message` top-level itu **generik** ("Validasi gagal.", detail sebenarnya ada di `errors`) atau **spesifik** (pesan asli dari kode, `errors` pasti kosong `{}`).

| \# | Skenario | Status | Asal | `message` top-level | Isi `errors` |
| :---- | :---- | :---- | :---- | :---- | :---- |
| 1 | Tidak login / token tidak valid | `401` | — | `"Belum login atau sesi berakhir."` | `{}` |
| 2 | Login tapi bukan superadmin & bukan `bisa_atur_hak_akses` | `403` | Spesifik (`abort()`) | `"Tidak memiliki akses ke halaman ini."` | `{}` |
| 3 | `perubahan` tidak dikirim / kosong / bukan array | `422` | Generik (validasi struktur) | `"Validasi gagal."` | `{"perubahan": [...]}` — teks pesan ikut format standar Laravel (`lang/id/validation.php`), bukan custom |
| 4 | Salah satu item kurang field wajib (`id`/`tipe`/`dari`/`ke`), atau tipe datanya salah (mis. `dari` bukan boolean/1/0, `id` bukan integer, `nama` bukan string) | `422` | Generik (validasi struktur) | `"Validasi gagal."` | `{"perubahan.N.field": [...]}`, teks standar Laravel juga |
| 5 | `tipe` dikirim tapi bukan salah satu dari 5 whitelist (termasuk kirim `role`, `aktif`, atau nama field lain/typo) | `422` | Generik (`ValidationException::withMessages()` manual) | `"Validasi gagal."` | `{"perubahan.N.tipe": ["Field '{tipe}' tidak dikenali atau tidak bisa diubah lewat halaman ini."]}` — teks ini PERSIS dari kode, bukan standar Laravel |
| 6 | Ada item dengan `id` \= id user yang sedang login (self-edit) | `403` | Spesifik (`return response()->json()` langsung) | `"Tidak bisa mengubah hak akses milik sendiri."` | `{}` |
| 7 | `id` tidak ditemukan user-nya di database | `422` | Generik (`ValidationException::withMessages()` manual) | `"Validasi gagal."` | `{"perubahan.N.id": ["User dengan id {id} tidak ditemukan."]}` |
| 8 | `id` ditemukan tapi user tsb berstatus `is_superadmin` | `422` | Generik (`ValidationException::withMessages()` manual) | `"Validasi gagal."` | `{"perubahan.N.id": ["Kapabilitas superadmin tidak bisa diubah lewat halaman ini."]}` |
| 9 | Ada pasangan (`id`, `tipe`) yang identik dikirim lebih dari 1 kali dalam satu batch | `422` | Spesifik (`return response()->json()` langsung) | `"Satu field (id + tipe) tidak boleh dikirim dua kali dalam satu request."` | `{}` |
| 10 | `dari` yang dikirim FE tidak sama dengan nilai kolom TERKINI di database (dicek ulang setelah row dikunci) | `409` | Spesifik (`return response()->json()` langsung) | `"Data untuk user '{nama}' sudah berubah sejak halaman dimuat, silakan muat ulang."` — `{nama}` diambil dari kolom `nama` **di database milik user target**, BUKAN dari field `nama` yang dikirim FE di payload item tersebut | `{}` |
| — | Sukses, tidak ada masalah di semua item | `200` | — | `{"status": "ok", "data": null}` (bukan envelope error, lihat §0) | — |

**Cara baca cepat buat FE:** cek `errors` dulu — kalau isinya object kosong `{}`, tampilkan `message` apa adanya ke user (itu pesan final, sudah manusiawi). Kalau `errors` ada isinya, JANGAN tampilkan `message` (isinya cuma "Validasi gagal." generik) — ambil pesan dari dalam `errors[<key>][0]` per field yang bermasalah.

### **Urutan pengecekan (kalau satu batch punya lebih dari satu masalah sekaligus)**

Yang muncul di response adalah yang **paling awal terdeteksi** menurut urutan eksekusi kode berikut — bukan acak, dan bukan urutan berdasarkan nomor skenario di tabel atas:

1. **Validasi struktur payload** (skenario \#3, \#4) — lewat `$request->validate()`, dijalankan paling awal sebelum kode lain apapun.  
2. **Duplikat pasangan (id, tipe) di SELURUH batch** (skenario \#9) — dicek sekali di awal, sebelum loop item manapun jalan.  
3. **Loop per item, berurutan sesuai posisi index di array** (`0, 1, 2, ...`) — untuk tiap index, `tipe` dicek dulu (skenario \#5), BARU kalau lolos, self-edit dicek (skenario \#6), baru lanjut ke index berikutnya. Jadi kalau item index 0 kena self-edit, item index 1 yang punya `tipe` salah TIDAK PERNAH sempat diperiksa/dilaporkan — yang balik ke FE tetap error item index 0\. Kedua pengecekan ini murni dari payload, belum menyentuh database.  
4. **Setelah SEMUA id di-lookup dari DB sekaligus** (satu query `whereIn`) — loop lagi per index dengan urutan sama: id tidak ditemukan (\#7) dicek dulu, baru superadmin-target (\#8), per index berurutan.  
5. **Baru setelah semua di atas lolos untuk SELURUH item**, baru masuk ke database transaction \+ row lock, dan stale-check `dari` (\#10) dijalankan — ini pengecekan paling terakhir. Artinya: walau payload lolos semua validasi struktur & semantik, request masih bisa gagal di titik ini kalau datanya sudah keburu berubah sejak terakhir di-GET.

---

## **Catatan Lintas-Chunk (info tambahan, bukan bagian kontrak endpoint ini)**

* Setiap perubahan lewat `PUT` di atas otomatis membuat entri di tabel log aktivitas (`jenis_aksi: "ubah_kapabilitas"`, target berupa user yang diubah). Chunk Hak Akses ini **sudah benar** dan tidak perlu perubahan apapun untuk mendukung ini.  
* Field `dari`/`ke` di request PUT bertipe boolean murni untuk 5 kapabilitas ini — tidak ada mekanisme "ubah beberapa kapabilitas jadi satu nilai gabungan" atau semacamnya; setiap kapabilitas selalu diperlakukan sebagai satu unit boolean independen, walau dikirim dalam satu batch bersama kapabilitas lain.

# Log Aktivitas

# **Kontrak API — Log Aktivitas**

---

## **1\. Endpoint**

|  |  |
| ----- | ----- |
| **Method** | `GET` |
| **Path** | `/api/v1/admin/log-aktivitas` |
| **Auth** | Wajib login (`auth:sanctum`). Selain itu wajib kapabilitas `bisa_lihat_log` — **lihat §3 soal siapa yang boleh akses.** |
| **Rate limit** | Tidak ada throttle khusus di route ini (beda dari `/teller/setoran/riwayat` atau `/auth/login` yang di-throttle). |

---

## **2\. Request Contract**

Semua parameter dikirim lewat **query string**, tidak ada request body (ini endpoint `GET`).

| Parameter | Wajib? | Tipe | Aturan validasi | Default kalau tidak dikirim |
| ----- | ----- | ----- | ----- | ----- |
| `page` | Tidak | integer | Minimal `1` | `1` |
| `per_page` | Tidak | integer | Minimal `1`, **maksimal `100`** | `10` |
| `search` | Tidak | string | Maksimal `100` karakter | — (tidak ada filter search) |
| `aksi` | Tidak | string | Harus salah satu dari daftar `jenis_aksi` resmi — **lihat tabel lengkap di §7** | — (tidak ada filter aksi) |
| `tanggal_mulai` | Tidak | string tanggal | Format wajib `YYYY-MM-DD` | — |
| `tanggal_akhir` | Tidak | string tanggal | Format wajib `YYYY-MM-DD`, **dan harus \>= `tanggal_mulai`** (kalau `tanggal_mulai` juga dikirim) | — |

**Tidak ada parameter `loket_id`.** Endpoint ini tidak punya filter loket langsung — satu-satunya cara menyaring per loket adalah lewat `search` dengan nama loketnya (partial text match, lihat §9), bukan lewat ID.

**Catatan penting soal validasi:**

* `tanggal_mulai` boleh dikirim sendirian tanpa `tanggal_akhir` (filter "dari tanggal X sampai sekarang"), dan sebaliknya.  
* Filter tanggal pakai **`whereDate` pada `created_at`** — artinya perbandingan tanggal kalender murni, bukan rentang timestamp. Kirim `tanggal_akhir=2026-08-20` akan mencakup SELURUH hari itu (00:00:00 s/d 23:59:59), bukan cuma sampai tengah malam.  
* Validasi jalan **sebelum** guard kapabilitas dicek? **Tidak** — urutan di kode: guard akses (`403`) dicek dulu, baru validasi query param (`422`). Jadi user yang gak punya akses akan selalu dapat `403` walau query param-nya juga invalid, bukan `422`.

---

## **3\. Business Rule — Siapa yang Boleh Akses**

Guard akses **bukan** cek `is_superadmin || bisa_lihat_log` yang ditulis manual, tapi lewat method terpusat `kapabilitasUntukApi()['bisa_lihat_log']`. Efeknya buat FE:

* User dengan kolom `bisa_lihat_log = true` → boleh akses.  
* **Superadmin selalu boleh akses, walau kolom `bisa_lihat_log` di database-nya `false`.** Jangan pakai nilai mentah kolom itu buat nentuin apakah tombol/menu "Log Aktivitas" ditampilkan ke superadmin — cek status superadmin juga.  
* Teller biasa tanpa kapabilitas ini → `403`.  
* Belum login sama sekali → `401` (bukan `403`).

---

## **4\. Response Contract — Sukses (200)**

### **Shape**

{  
  "status": "ok",  
  "data": {  
    "items": \[  
      {  
        "id": 123,  
        "waktu": "2026-09-02T09:00:00+07:00",  
        "user": "Budi Santoso",  
        "role": "teller",  
        "loket": "Loket A",  
        "aksi": "edit\_setoran",  
        "target": "Detail Setoran \#45 (transfer)",  
        "perubahan": \[  
          { "label": "nominal", "before": "100000", "after": "150000" }  
        \]  
      }  
    \],  
    "meta": {  
      "current\_page": 1,  
      "per\_page": 10,  
      "total\_items": 1,  
      "total\_pages": 1  
    }  
  }  
}

### **Field-by-field — tipe & aturan yang gak keliatan dari tipe data doang**

| Field | Tipe | Business rule |
| ----- | ----- | ----- |
| `id` | integer | ID baris `activity_logs`, urut naik sesuai waktu tercatat. |
| `waktu` | string, ISO 8601 | Selalu `created_at`, TIDAK ADA `updated_at` — tabel ini **append-only**, satu baris log tidak pernah diedit setelah dibuat. Timezone aplikasi adalah `Asia/Jakarta` (`+07:00`), offset ini akan selalu muncul di string ISO 8601-nya. |
| `user` | string | Nama **PELAKU** aksi (siapa yang melakukan, bukan siapa yang kena). Kalau user-nya entah kenapa tidak ketemu (harusnya nyaris tidak pernah terjadi karena FK `restrictOnDelete`), fallback ke string `"-"`. |
| `role` | string (`"superadmin"` | `"verifikator"` | `"teller"`) | **DIBEKUKAN saat kejadian**, bukan dihitung ulang dari kondisi user SAAT INI. Kalau seorang teller kemudian dipromosikan jadi verifikator, log-log LAMA dia tetap tercatat `role: "teller"` — tidak berubah retroaktif. Aturan penurunan role: `is_superadmin` → `superadmin`; salah satu dari 5 kapabilitas (`bisa_akses_laporan`, `bisa_atur_hak_akses`, `bisa_lihat_log`, `bisa_input_setoran_semua_loket`, `bisa_edit_setoran_semua_loket`) bernilai `true` → `verifikator`; selain itu → `teller`. |
| `loket` | string | null | **Loket milik TARGET (data yang kena aksi), BUKAN loket milik pelaku.** Contoh: verifikator berbasis Loket A yang mengedit setoran milik teller di Loket B → field `loket` berisi `"Loket B"`, bukan `"Loket A"`. Nilai ini juga DIBEKUKAN saat kejadian (via kolom `target_loket_id`), jadi tetap akurat walau data targetnya sendiri belakangan dihapus permanen. `null` untuk aksi yang sifatnya system-wide / tidak terikat loket tertentu (contoh: `login_manual`, `logout_manual`, `laporan_dilihat`, `laporan_diekspor`, `laporan_diunduh`, `riwayat_lintas_loket_dilihat`, `riwayat_lintas_loket_diekspor`). |
| `aksi` | string | Nilai mentah `jenis_aksi` — lihat daftar lengkap & artinya di §7. |
| `target` | string | null | Deskripsi ringkas objek yang kena aksi. Format PERSIS per tipe target — lihat §5. `null` untuk aksi tanpa target spesifik (login/logout/lihat laporan/dst). |
| `perubahan` | array of `{label, before, after}` | Detail before/after. **Isi & bentuknya beda total tergantung `jenis_aksi`** — ini bagian paling penting, lihat §6. `before`/`after` SELALU bertipe `string | null`, TIDAK PERNAH boolean/number mentah — boolean diformat jadi `"Ya"`/`"Tidak"`, angka jadi string. |
| `meta.current_page` | integer | Halaman yang sedang ditampilkan. |
| `meta.per_page` | integer | Ukuran halaman yang benar-benar dipakai (setelah default 10 diterapkan kalau tidak dikirim). |
| `meta.total_items` | integer | Total baris yang cocok filter (sebelum pagination). |
| `meta.total_pages` | integer | Total halaman. |

**Urutan hasil:** SELALU `created_at` descending (terbaru duluan). Tidak ada opsi sorting lain — parameter sorting tidak didukung sama sekali, kirim apapun akan diabaikan.

**Kalau tidak ada log sama sekali / tidak ada yang cocok filter:** tetap `200`, dengan `items: []` dan `meta.total_items: 0` — BUKAN `404`.

### **⚠️ Field yang SENGAJA TIDAK ADA di response**

Response item **hanya** berisi 8 field di atas — dicek langsung dari kode, bukan asumsi. Field berikut **tidak pernah** dikirim, walau datanya ada di database:

* **`target_id` / `target_type` mentah** — tidak diekspos sama sekali. Kalau FE butuh ID numerik buat bikin fitur "klik baris log → buka detail setoran/user terkait", ID itu **tidak tersedia sebagai field terpisah**. Satu-satunya tempat angka ID muncul adalah tertanam di dalam teks `target` (`"Detail Setoran #45 (transfer)"`), dan itu HANYA untuk `target_type = setoran_detail`. Nge-parse angka dari string ini untuk dipakai sebagai ID sebenarnya **tidak disarankan** — bukan kontrak resmi, formatnya bisa berubah kapan saja tanpa dianggap breaking change.  
* **`user_id` pelaku** (ID numerik) — hanya nama (`user`) yang dikirim, bukan ID.  
* **`actor_loket_id`** (loket milik PELAKU) — kolom ini ada di database tapi tidak pernah diekspos ke response. Yang diekspos cuma `loket` (loket TARGET). Kalau FE butuh tahu loket asal si pelaku aksi, endpoint ini tidak bisa menjawabnya.

---

## **5\. Business Rule — Format Field `target`**

`target` di-resolve dari `target_type` mentah di database (nilainya SELALU string literal pendek — `'setoran_harian'`, `'setoran_detail'`, `'users'` — **BUKAN** nama class PHP lengkap seperti `App\Models\SetoranHarian`).

| `target_type` | Format `target` | Fallback kalau row aslinya sudah tidak ada |
| ----- | ----- | ----- |
| `setoran_harian` | `"Setoran {tanggal_transaksi}"` (contoh: `"Setoran 2026-08-15"`) | Row bisa hilang dari lookup langsung karena aksi `setoran_dihapus` (soft-delete). Fallback pakai `tanggal_transaksi` yang sudah dibekukan di `detail_perubahan` milik log itu sendiri — hasil akhirnya tetap sama persis (`"Setoran {tanggal}"`), FE tidak perlu tahu bedanya. |
| `setoran_detail` | `"Detail Setoran #{id} ({metode_pembayaran})"` (contoh: `"Detail Setoran #45 (transfer)"`) | Row **hard-delete beneran** kalau aksinya `hapus_setoran` (bukan soft-delete). Fallback pakai snapshot `metode_pembayaran` yang dibekukan di `detail_perubahan.entry`. Kalau snapshot itu somehow tidak ada, `target` jadi `null` sepenuhnya (bukan string setengah-lengkap). |
| `users` | `"User: {nama}"` (contoh: `"User: Budi Santoso"`) | Tidak ada mekanisme fallback untuk user (asumsi: user tidak pernah benar-benar dihapus dari sistem, konsisten dengan FK `restrictOnDelete` di migration). |
| lainnya / `null` | `null` | — |

---

## **6\. Business Rule — Isi `perubahan[]` per `jenis_aksi` (BAGIAN PALING PENTING)**

**Ini bukan diff generik.** Setiap `jenis_aksi` punya perlakuan berbeda-beda yang HARUS diketahui FE supaya tidak salah asumsi bentuk datanya seragam.

### **6.1 Kelompok "diff sebelum/sesudah" — `edit_setoran`, `submit_setoran`, `ubah_kapabilitas`**

Hanya field yang **NILAINYA BENERAN BEDA** antara sebelum & sesudah yang muncul sebagai baris di `perubahan[]`. Field yang nilainya sama persis di kedua sisi **tidak muncul sama sekali** — bukan muncul dengan before==after.

Contoh nyata (dari test): edit setoran yang mengubah `nominal` dari 100000 ke 150000 tapi `metode_pembayaran` tetap `tunai` → `perubahan` cuma berisi **1 baris** (`nominal`), baris `metode_pembayaran` tidak ada.

Boolean diformat `"Ya"`/`"Tidak"` (contoh: `is_konfirmasi` dari `false` ke `true` → `before: "Tidak"`, `after: "Ya"`).

Kasus khusus `ubah_kapabilitas`: `label` yang muncul adalah **nama kolom kapabilitas itu sendiri** (contoh: `bisa_lihat_log`, `bisa_akses_laporan`), bukan label yang sudah diterjemahkan ke bahasa manusia — kalau FE mau menampilkan label yang lebih ramah, mapping nama-kolom-ke-label-tampilan harus dikerjakan di sisi FE.

### **6.2 `hapus_setoran` — field yang hilang, arah SELALU before→null**

Karena datanya **hard-delete beneran** (row fisik lenyap dari database), semua field snapshot ditampilkan sebagai "sesuatu yang HILANG": `before` \= nilai terakhir sebelum dihapus, `after` \= **selalu `null`**.

Field yang mungkin muncul (hanya yang ada nilainya di snapshot): `metode_pembayaran`, `nominal`, `foto_bukti_path`.

### **6.3 `setoran_dihapus` — mirip 6.2, TAPI dengan 1 pengecualian arah dan 1 field yang sengaja disembunyikan**

* `tanggal_transaksi`, `status_saat_dihapus`, `jumlah_entry_dihapus` → sama pola dengan 6.2: `before` \= nilai, `after` \= `null`.  
* **`alasan` DIBALIK ARAHNYA**: kalau ada alasan penghapusan yang diisi user, muncul sebagai baris dengan `label: "Alasan Penghapusan"`, `before: null`, `after: "{teks alasan}"`. Ini BUKAN bug — alasan itu informasi BARU yang ditambahkan user saat menghapus, bukan data yang hilang, jadi arahnya sengaja dibalik.  
* **Kalau `alasan` kosong/tidak diisi (contoh: hapus setoran berstatus `draft` yang mungkin tidak wajib isi alasan), baris `"Alasan Penghapusan"` TIDAK MUNCUL SAMA SEKALI** di `perubahan[]` — bukan muncul dengan `after: null`.  
* **`loket_id` TIDAK PERNAH muncul sebagai baris di `perubahan[]`**, walau ada di data mentah `detail_perubahan` — informasi loket sudah disediakan lewat kolom `loket` terpisah (§4), sengaja tidak diduplikasi di sini.

### **6.4 `mutasi_loket_terdeteksi` — selalu tepat 1 baris, isinya nama loket (bukan ID)**

Selalu menghasilkan **persis 1 baris** dengan `label: "Loket"`. `before`/`after` sudah di-resolve dari ID loket mentah ke **nama loket** (`nama_loket`) — FE tidak perlu resolve ID sendiri.

`before` bisa `null` kalau user baru pertama kali mendapat loket (belum pernah punya loket sebelumnya) — bukan error, itu kondisi valid untuk user yang baru diprovisioning.

### **6.5 Kelompok "selalu kosong" — `perubahan: []`**

Aksi berikut **SELALU** menghasilkan `perubahan` array kosong — ini keputusan desain, bukan data yang hilang:

| `jenis_aksi` | Kenapa selalu kosong |
| ----- | ----- |
| `buat_setoran` | Aksi "create", tidak ada state "sebelum" untuk dibandingkan. |
| `buat_pelunasan` | Sama, aksi create. |
| `auto_provisioning` | User baru diprovisioning otomatis lewat SSO, tidak ada state sebelumnya. |
| `vendor_baru_terdeteksi` | Event deteksi vendor baru dari data bridge, bukan perubahan state. |
| `login_manual`, `login_gagal`, `logout_manual` | Event sesi, bukan perubahan data. |
| `riwayat_lintas_loket_dilihat`, `riwayat_lintas_loket_diekspor` | Aksi lihat/ekspor data, bukan mengubah apapun. |
| `atur_password` | **Sengaja** — `detail_perubahan` untuk aksi ini **tidak pernah berisi hash atau plaintext password apapun** (alasan keamanan, mencegah kebocoran lewat log/query). Satu-satunya info yang tersimpan (`password_sebelumnya_kosong`, penanda "pertama kali diisi" vs "diganti ulang") sengaja tidak diekspos ke response ini. |
| `laporan_dilihat`, `laporan_diekspor`, `laporan_diunduh` | Aksi lihat/ekspor laporan, bukan mengubah data — mirip `riwayat_lintas_loket_*`. |

⚠️ **Perhatian FE:** jangan pernah nge-hardcode asumsi "kalau `perubahan` kosong berarti tidak ada detail apapun yang relevan" untuk generalisasi ke `jenis_aksi` baru yang mungkin ditambahkan nanti — tabel di atas cuma valid untuk daftar `jenis_aksi` yang ada SEKARANG. `jenis_aksi` baru di masa depan perlu dicek satu-satu apakah masuk kelompok mana.

---

## **7\. Referensi Lengkap `jenis_aksi`**

19 nilai valid untuk parameter filter `?aksi=`, dan chunk mana yang menuliskannya:

| `jenis_aksi` | Ditulis dari | `target_type` | Kelompok `perubahan[]` (§6) |
| ----- | ----- | ----- | ----- |
| `login_manual` | Login | — (null) | Kosong |
| `login_gagal` | Login gagal | — (null) | Kosong |
| `logout_manual` | Logout | — (null) | Kosong |
| `buat_setoran` | Input Setoran (Card A) & Detail Setoran | `setoran_detail` | Kosong |
| `edit_setoran` | Input Setoran (Card A) & Detail Setoran | `setoran_detail` | Diff (6.1) |
| `hapus_setoran` | Input Setoran (Card A) & Detail Setoran | `setoran_detail` | Hapus (6.2) |
| `submit_setoran` | Input Setoran (Card A) — submit draft→submitted | `setoran_harian` | Diff (6.1) |
| `setoran_dihapus` | Riwayat Setoran — hapus riwayat | `setoran_harian` | Khusus (6.3) |
| `buat_pelunasan` | Pelunasan | `setoran_detail` | Kosong |
| `riwayat_lintas_loket_dilihat` | Riwayat Setoran — lihat lintas loket | — (null) | Kosong |
| `riwayat_lintas_loket_diekspor` | Riwayat Setoran — ekspor lintas loket | — (null) | Kosong |
| `auto_provisioning` | SSO — user baru terdeteksi | `users` | Kosong |
| `mutasi_loket_terdeteksi` | SSO — user pindah loket | `users` | 1 baris "Loket" (6.4) |
| `vendor_baru_terdeteksi` | Bridge/sinkronisasi data lama — vendor baru terdeteksi | — (null) | Kosong |
| `ubah_kapabilitas` | Hak Akses | `users` | Diff (6.1) |
| `atur_password` | Ganti Password (halaman akun sendiri) | `users` (diri sendiri) | Kosong (sengaja) |
| `laporan_dilihat` | Laporan | — (null) | Kosong |
| `laporan_diekspor` | Laporan — ekspor | — (null) | Kosong |
| `laporan_diunduh` | Laporan — unduh | — (null) | Kosong |

Kirim `?aksi=` dengan nilai di luar 19 ini → `422` (lihat §8).

---

## **8\. Response Contract — Error**

Semua error API (bukan cuma di endpoint ini) dibungkus format seragam lewat exception handler global:

{  
  "status": "error",  
  "message": "...",  
  "errors": { }  
}

### **8.1 `401 Unauthenticated`**

Belum login / sesi habis.

{  
  "status": "error",  
  "message": "Belum login atau sesi berakhir.",  
  "errors": {}  
}

### **8.2 `403 Forbidden`**

User sudah login tapi tidak punya kapabilitas `bisa_lihat_log` (dan bukan superadmin — lihat §3).

{  
  "status": "error",  
  "message": "Tidak memiliki akses ke log aktivitas.",  
  "errors": {}  
}

### **8.3 `422 Unprocessable Entity` — Validasi query param gagal**

Envelope-nya:

{  
  "status": "error",  
  "message": "Validasi gagal.",  
  "errors": { "\<nama\_field\>": \["\<pesan\>"\] }  
}

Pesan aktual per skenario (bahasa Indonesia, sudah diverifikasi langsung dari validator — bukan perkiraan):

| Skenario | Field di `errors` | Pesan |
| ----- | ----- | ----- |
| `aksi` bukan salah satu dari 19 nilai valid | `aksi` | `"Aksi yang dipilih tidak valid."` |
| `page` kurang dari 1 | `page` | `"Page minimal bernilai 1."` |
| `per_page` lebih dari 100 | `per_page` | `"Per page maksimal bernilai 100."` |
| `per_page` bukan angka bulat | `per_page` | `"Per page harus berupa bilangan bulat."` |
| `tanggal_mulai`/`tanggal_akhir` salah format (bukan `YYYY-MM-DD`) | `tanggal_mulai` atau `tanggal_akhir` | `"Tanggal mulai tidak cocok dengan format Y-m-d."` (nama field menyesuaikan) |
| `tanggal_akhir` lebih awal dari `tanggal_mulai` | `tanggal_akhir` | `"Tanggal akhir harus berisi tanggal setelah atau sama dengan tanggal mulai."` |
| `search` lebih dari 100 karakter | `search` | `"Search maksimal berisi 100 karakter."` |

**Catatan:** `errors` bisa berisi lebih dari satu field sekaligus kalau lebih dari satu parameter invalid dalam satu request yang sama (perilaku standar Laravel validator — semua rule dicek, bukan berhenti di kesalahan pertama).

---

## **9\. Business Rule — Query Param `search`**

Search **tidak** mencari ke string tampilan `target`/`user` yang sudah diformat, tapi langsung ke data mentah di database. Perilakunya beda total tergantung apakah input berupa angka murni atau teks:

### **9.1 Input angka murni (semua karakter digit)**

Dicocokkan ke **ID `setoran_harian`**, lewat 2 jalur:

1. Baris yang `target_type = 'setoran_harian'` DAN `target_id` sama persis dengan angka itu.  
2. Baris yang `target_type = 'setoran_detail'` DAN field `setoran_harian_id` di dalam `detail_perubahan`\-nya sama dengan angka itu.

**Efek praktis:** search `"42"` akan mengembalikan log submit setoran \#42 itu sendiri **DAN SEMUA** log `buat_setoran`/`edit_setoran`/`hapus_setoran` untuk entry-entry di bawah setoran \#42 — termasuk yang metode pembayarannya berbeda-beda (tunai, transfer, kpay dst digabung semua), karena semuanya terhubung lewat `setoran_harian_id` yang sama.

### **9.2 Input teks (bukan angka murni)**

Dicocokkan (OR) ke 3 kemungkinan:

1. **Nama PELAKU** aksi (`user.nama`, partial match).  
2. **Nama loket TARGET** (`targetLoket.nama_loket`, partial match).  
3. Untuk baris `target_type = 'setoran_detail'` saja: field `metode_pembayaran` di dalam `detail_perubahan` (partial match) — mengecek 2 kemungkinan shape sekaligus (`detail_perubahan.metode_pembayaran` untuk `buat_setoran`/`edit_setoran`, ATAU `detail_perubahan.entry.metode_pembayaran` untuk `hapus_setoran`), karena kedua jenis aksi itu memang menyimpan field ini di lokasi berbeda dalam JSON-nya.

⚠️ **PENTING — beda dari asumsi umum:** search nama **HANYA mencocokkan nama PELAKU aksi, TIDAK PERNAH nama pengguna yang jadi TARGET aksi.** Contoh: kalau Superadmin "Admin Utama" melakukan `auto_provisioning` terhadap user baru "Budi Santoso", search `"Budi"` akan **TIDAK ketemu** log itu (0 hasil) — search `"Admin"` baru ketemu. Ini terverifikasi eksplisit lewat test, bukan asumsi.

Search kosong / hanya spasi → filter search diabaikan sepenuhnya (dianggap sama dengan tidak mengirim parameter ini).

---

## **10\. Ringkasan Perilaku yang Mudah Disalahpahami (checklist cepat buat FE)**

* \[ \] `loket` \= loket TARGET, bukan loket admin/pelaku yang sedang login.  
* \[ \] `role` \= snapshot beku saat kejadian, BUKAN role user itu sekarang. Dua log dari user yang sama bisa punya `role` berbeda kalau rolenya berubah di antara dua kejadian itu.  
* \[ \] `perubahan[]` kosong itu **bukan** berarti "gagal ambil data" — untuk 13 dari 19 jenis aksi, kosong memang perilaku yang benar (lihat §6.5).  
* \[ \] `target_type`/nilai `aksi` mentah di database SELALU string pendek literal (`setoran_harian`, dst), tidak pernah nama class PHP.  
* \[ \] Search nama cuma match PELAKU, bukan target.  
* \[ \] Default `per_page` adalah **10**, bukan angka lain, kalau parameter tidak dikirim.  
* \[ \] Urutan hasil SELALU terbaru dulu, tidak bisa diubah lewat parameter apapun.  
* \[ \] Guard 403 dicek SEBELUM validasi query param — kombinasi "user tanpa akses \+ query param invalid" selalu menghasilkan 403, bukan 422\.  
* \[ \] Untuk baris `setoran_dihapus`, jangan tampilkan `loket_id` dari `perubahan[]` karena memang tidak akan pernah ada di situ — pakai kolom `loket` yang terpisah.  
* \[ \] Tidak ada `target_id`/`target_type` mentah atau `user_id` pelaku di response — kalau butuh fitur "klik log → buka data terkait", ID itu tidak disediakan endpoint ini.  
* \[ \] Tidak ada filter `loket_id`. Filter loket cuma bisa lewat `search` teks (partial match nama loket TARGET), dan `loket` PELAKU sama sekali tidak bisa difilter maupun ditampilkan lewat endpoint ini.

---

## **11\. Perbaikan yang Sudah Diterapkan (untuk konteks — bukan bagian kontrak, tapi relevan biar FE tahu perilaku ini FINAL)**

Sebelum dokumen ini dibuat, 2 hal berikut sempat salah di kode dan **sudah diperbaiki serta diverifikasi lewat 544 test yang PASS** di seluruh proyek:

1. `ubah_kapabilitas` sebelumnya selalu menghasilkan `perubahan: []` walau datanya ada (kelewat gak dimasukkan ke logic diff) — **sudah diperbaiki**, sekarang benar mengikuti §6.1.  
2. Filter `?aksi=` sebelumnya menolak (422) 5 nilai yang sebenarnya valid (`ubah_kapabilitas`, `atur_password`, `laporan_dilihat`, `laporan_diekspor`, `laporan_diunduh`) — **sudah diperbaiki**, sekarang semua 19 nilai di §7 diterima.

Kontrak di dokumen ini mendokumentasikan perilaku SETELAH kedua perbaikan itu diterapkan.