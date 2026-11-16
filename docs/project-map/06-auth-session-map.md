# 06 - Auth & Session Map

Sistem autentikasi dan manajemen sesi pada **ExamLAN Pro** terbagi menjadi dua mekanisme yang sangat berbeda untuk menjamin kinerja maksimal dalam jaringan lokal: sesi guru berbasis PHP Session native dan sesi siswa berbasis Bearer Token yang disimpan secara stateless di database JSON.

## 1. Autentikasi & Sesi Guru (Stateful Session)
Keamanan dashboard guru ditingkatkan secara signifikan dengan menghapus sepenuhnya skema otentikasi client-side atau penggunaan hardcoded password di Javascript.

### A. Alur Validasi Kredensial
- **Password Hashing:** Sandi guru disimpan di `db_kuis.json` dalam bentuk hash Bcrypt (`teacher_password_hash`). Verifikasi menggunakan fungsi aman `password_verify()`.
- **Sesi PHP di Folder Terisolasi:**
  - Sesi diinisiasi menggunakan `session_save_path(__DIR__ . '/sessions')`.
  - Durasi masa aktif cookie sesi disetel ketat via `session_set_cookie_params()` dengan konfigurasi:
    - `lifetime`: Sesi browser (`0` detik).
    - `path`: `/` (berlaku global).
    - `httponly`: `true` (mencegah skrip jahat / XSS mencuri session ID via `document.cookie`).
    - `secure`: `true` jika menggunakan HTTPS (diatur dinamis lewat deteksi `$secureCookie`).
    - `samesite`: `'Lax'` (mencegah serangan CSRF).

### B. Proteksi Sesi Guru & Rate Limiting
1. **Rate Limiting Sederhana:**
   - Server mencatat kegagalan login guru pada array sesi `$_SESSION['teacher_login_limit']`.
   - Jika salah password sebanyak 5 kali berturut-turut, login diblokir selama 5 menit (300 detik) dengan mengembalikan status HTTP 429.
2. **Session Regeneration:**
   - Begitu password terverifikasi sukses, server memicu `session_regenerate_id(true)` sebelum menyimpan status autentikasi. Ini merupakan langkah preventif mutlak untuk menangkal serangan *Session Fixation* di mana penyerang menetapkan ID sesi tertentu ke browser korban.
3. **Session Idle Timeout:**
   - Setiap request API terbatas guru (`teacherOnly()`) memeriksa penanda waktu `$_SESSION['teacher_last_activity']`.
   - Jika selisih waktu saat ini dengan aktivitas terakhir melebihi 1800 detik (30 menit), sesi dihapus secara sepihak oleh server (`session_unset()`, `session_destroy()`) dan server mengembalikan HTTP 403 Forbidden. Jika masih aktif, penanda waktu diperbarui ke detik saat ini.

---

## 2. Autentikasi Sesi Siswa (Stateless Bearer Token)
Berbeda dengan guru, siswa **TIDAK** menggunakan sesi PHP native (`session_start()`).

### Mengapa Siswa Tidak Menggunakan PHP Session?
1. **Masalah Penguncian Berkas (Session File Locking):**
   - PHP native mengunci berkas sesi secara eksklusif selama skrip berjalan.
   - Jika 40 siswa melakukan polling status atau sinkronisasi jawaban secara terus menerus (setiap beberapa detik), web server Apache akan mengalami kemacetan parah (*bottleneck*) karena request tertahan menunggu giliran pelepasan kunci berkas sesi masing-masing.
2. **Kestabilan Jaringan LAN:** Sesi berbasis cookie sering kali terputus di jaringan nirkabel lokal (WiFi sekolah) yang tidak stabil.

### Implementasi Token Sesi Siswa (Active Attempt Token)
Sebagai gantinya, ExamLAN Pro menggunakan Bearer Token berkeamanan tinggi:
1. **Generasi Token:** Saat siswa berhasil masuk lewat `start_attempt`, server membuat string acak sepanjang 64 karakter kriptografis aman (`bin2hex(random_bytes(32))`).
2. **Hashing Token di DB:**
   - Token asli dikirim ke browser siswa (tidak pernah disimpan mentah di server).
   - Server hanya menyimpan hash SHA-256 dari token tersebut sebagai kunci indeks di array `active_attempts` pada database `db_kuis.json`:
     `$key = hash('sha256', $token);`
   - Ini mematuhi prinsip kriptografi modern: jika database JSON berhasil diunduh peretas, peretas tetap tidak bisa membajak ujian siswa karena mereka tidak mengetahui token asli yang dikirim ke klien siswa.
3. **Penyimpanan Klien:** Token disimpan di `localStorage` siswa (`examlan_active_attempt`). Setiap interaksi pengerjaan (menyimpan jawaban, memulihkan progres, mengakhiri ujian) mewajibkan siswa menyertakan token asli ini pada bodi POST atau query parameter GET.
4. **Validasi Token di Backend:**
   - Server mengambil token dari request, membuat hash SHA-256-nya, lalu mencocokkannya langsung dengan indeks `db_kuis.json['active_attempts']`.
   - Jika cocok, progres ujian siswa tersebut dimuat dan diverifikasi batas waktunya.
5. **Review Token:** Saat ujian sukses dikumpulkan, token ujian dihapus, dan server menerbitkan `review_token` acak baru khusus untuk prapengujian polling pembahasan kunci jawaban.
