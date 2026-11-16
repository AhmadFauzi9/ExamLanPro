# 09 - Security Audit

Berdasarkan audit read-only terhadap kode sumber ExamLAN Pro, berikut adalah ringkasan temuan keamanan, mekanisme pertahanan yang sudah ada, dan risiko yang teridentifikasi.

## 1. Pertahanan yang Sudah Diimplementasikan
1. **Pemisahan Logika Penilaian:** Penilaian dilakukan 100% di server. Klien hanya mengirimkan pilihan indeks. Ini mencegah manipulasi skor di sisi klien.
2. **Eliminasi Kunci Jawaban di Klien:** API secara aktif membuang field `answer`, `answers`, dan `explanation` sebelum data dikirim ke browser siswa. Kunci jawaban tidak dapat diintip melalui *Network Tab* atau memori JavaScript.
3. **Keamanan Sesi Siswa (Stateless):** Menggunakan token acak 64 karakter yang di-hash dengan SHA-256 di sisi server. Token asli tidak disimpan di database, sehingga kebocoran file JSON tidak langsung membuat sesi aktif bisa dibajak.
4. **Keamanan Dashboard Guru:**
   - Menggunakan Password Hashing (bcrypt).
   - Penanganan Session Fixation via `session_regenerate_id()`.
   - Proteksi XSS pada cookie via flag `HttpOnly`.
   - Rate limiting pada percobaan login untuk mencegah *Brute Force*.
   - Kewajiban ganti password default saat login pertama.
5. **Integritas File Database:** Penggunaan `LOCK_EX` pada `file_put_contents` menjamin integritas data saat terjadi penulisan bersamaan oleh banyak siswa di jaringan LAN.

---

## 2. Temuan Keamanan & Celah Potensial
1. **Risiko "View Source" pada Path Database:**
   - **Masalah:** Berkas `db_kuis.json` berada di root direktori web. Jika web server (Apache) tidak dikonfigurasi dengan benar, siapa pun dapat mengunduh berkas ini dengan mengakses `http://ip-server/db_kuis.json` dan mendapatkan seluruh data siswa, log audit, hash password guru, dan kunci jawaban semua kuis.
   - **Rekomendasi:** Tambahkan file `.htaccess` di root untuk menolak akses langsung ke file `.json` atau pindahkan file JSON ke luar direktori `public_html`.
2. **Ketergantungan CDN Eksternal:**
   - **Masalah:** Aplikasi memuat Tailwind, FontAwesome, dan SheetJS dari CDN internet.
   - **Dampak:** Jika Lab Komputer benar-benar offline (tanpa gateway internet), tampilan aplikasi akan berantakan dan fitur impor Excel akan gagal total.
   - **Rekomendasi:** Lokalisasi seluruh aset CSS/JS ke folder `assets/`.
3. **Race Condition pada Pembacaan Database:**
   - **Masalah:** Fungsi `loadDb()` tidak menggunakan Shared Lock (`LOCK_SH`).
   - **Dampak:** Pada beban sangat tinggi, ada kemungkinan kecil `file_get_contents` membaca berkas kosong saat proses `file_put_contents` (yang menggunakan `LOCK_EX`) sedang memotong (*truncate*) isi berkas.
4. **Kurangnya Validasi Tipe Data pada API:**
   - Beberapa endpoint API menerima input JSON dan langsung memasukkannya ke dalam database tanpa validasi tipe data yang ketat (misal: memastikan `score` adalah angka, bukan string).
5. **Penyimpanan Foto Siswa (Base64):**
   - Meskipun memudahkan backup, menyimpan ribuan foto Base64 di dalam satu file JSON akan membuat ukuran file membengkak drastis (bisa mencapai puluhan/ratusan MB), yang akan memperlambat waktu baca-tulis PHP secara signifikan dan meningkatkan risiko kegagalan I/O.

---

## 3. Status Fitur "Server Key"
- **Status Aktual:** Fitur ini telah **DIHAPUS SEPENUHNYA** dari logika aplikasi dan UI.
- **Pengganti:** Autentikasi kini mengandalkan sistem Sesi PHP standar yang jauh lebih aman dan lazim digunakan di web server. Tidak ada lagi ketergantungan pada string "Server Key" yang statis.
