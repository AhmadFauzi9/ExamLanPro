# 08 - Dependency Map

Aplikasi **ExamLAN Pro** dirancang untuk bekerja secara mandiri (self-contained) dengan ketergantungan minimal terhadap pihak ketiga. Hal ini krusial agar aplikasi tetap dapat berjalan normal di lingkungan Lab Komputer yang tidak memiliki akses internet (Full Offline LAN).

## 1. Ketergantungan Backend (Server-Side)
Backend tidak memiliki dependensi pustaka luar (Zero Dependency PHP).
- **Runtime:** PHP 7.4 atau versi yang lebih baru (disarankan menggunakan XAMPP/Laragon terbaru).
- **Ekstensi PHP Wajib:**
  - `json`: Untuk memproses database `db_kuis.json`.
  - `session`: Untuk manajemen sesi guru.
  - `hash`: Untuk hashing password (bcrypt) dan token sesi (sha256).
  - `date`: Untuk validasi jadwal ujian.
  - `mbstring` (opsional): Untuk penanganan string Unicode pada soal.

---

## 2. Ketergantungan Frontend (Client-Side)
Frontend memuat beberapa library melalui CDN. **PENTING:** Untuk penggunaan offline total, file-file di bawah ini harus diunduh dan disimpan secara lokal (local assets), namun saat ini kode merujuk pada URL eksternal:

### A. CSS & UI Framework
- **Tailwind CSS v3 (CDN):** Digunakan untuk styling responsif dan tata letak modern.
  - URL: `https://cdn.tailwindcss.com`
- **FontAwesome v6 (CDN):** Digunakan untuk set ikon antarmuka.
  - URL: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`
- **Google Fonts (Inter):** Font sistem utama.
  - URL: `https://fonts.googleapis.com/css2?family=Inter:...`

### B. Library JavaScript (Functional)
- **SheetJS (xlsx.full.min.js):** Digunakan pada dashboard guru untuk memproses impor/ekspor data Excel secara langsung di browser tanpa beban di server.
  - URL: `https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js`
- **Native Browser APIs:** Aplikasi sangat bergantung pada fitur browser modern:
  - `Fetch API`: Komunikasi asinkron dengan `api.php`.
  - `LocalStorage` & `SessionStorage`: Cache jawaban offline dan persistensi sesi siswa.
  - `FileReader`: Untuk memproses unggahan foto siswa dan file template soal.
  - `Base64 encoding`: Untuk penyimpanan gambar profil siswa di dalam JSON.

---

## 3. Keterkaitan Antar File (Inter-file Dependency)
- **`index.html` -> `api.php`:** Semua data kuis dan pengiriman jawaban bergantung pada ketersediaan file `api.php` di root.
- **`guru/index.html` -> `api.php`:** Dashboard guru mengakses API yang berada satu tingkat di atas direktori guru menggunakan URL relatif `../api.php`.
- **`api.php` -> `db_kuis.json`:** Backend akan gagal berfungsi (HTTP 500) jika berkas JSON ini hilang atau tidak memiliki izin tulis (*write permission*).
- **`api.php` -> `sessions/`:** Backend mewajibkan folder ini ada dan dapat ditulisi untuk menyimpan data sesi guru.
- **`api.php` -> `backups/`:** Dibutuhkan untuk fungsi pencadangan otomatis.
