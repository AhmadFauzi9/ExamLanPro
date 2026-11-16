# 04 - API Map

Backend API dijalankan secara sentral melalui berkas `api.php`. Seluruh interaksi menggunakan protokol HTTP dengan format pertukaran data JSON. Otorisasi rute dikelompokkan menjadi jalur publik (Siswa) dan jalur terbatas (Guru).

## 1. Rantai Middleware & Validasi Keamanan (api.php)
Sebelum memproses parameter `action`, `api.php` melakukan beberapa rutinitas wajib:
1. **Pemeriksaan Sesi PHP:** Membuka sesi di direktori khusus `./sessions/` dengan pengaturan cookie yang ketat.
2. **CORS Safe Handler:** Membuka header CORS secara kondisional jika terdeteksi asal request yang aman (tidak memakai `*` yang dilarang jika cookie disertakan).
3. **Penyaringan Otorisasi (`teacherOnly()`):**
   - Fungsi ini memicu pengecekan variabel sesi `$_SESSION['teacher_authenticated']` dan durasi aktivitas (`$_SESSION['teacher_last_activity']` maksimal 30 menit).
   - Apabila pemeriksaan gagal, API segera menghentikan eksekusi dengan mengirimkan header HTTP 403 Forbidden dan respons JSON error.

---

## 2. Pemetaan Lengkap Endpoint API

| Method | Action (Query Parameter) | Otorisasi | Parameter Body / Input | Deskripsi Respons & Efek Samping |
| :--- | :--- | :--- | :--- | :--- |
| **POST** | `teacher_login` | Publik | `{ "password": "..." }` | Memverifikasi password guru. Melakukan rate limiting (maksimal 5 kali salah). Jika berhasil, meregenerasi ID Sesi, menyetel sesi autentikasi, mencatat audit log, dan membalas status sukses beserta flag kewajiban ganti password. |
| **POST** | `change_teacher_password` | Guru | `{ "new_password": "..." }` | Mengubah password guru (minimal 8 karakter), mengenkripsi dengan bcrypt, menonaktifkan status wajib ganti password default, dan menyimpan perubahan di `db_kuis.json`. |
| **POST** | `teacher_logout` | Guru | *None* | Menghancurkan sesi server, menghapus cookie browser, mencatat log audit, dan mengembalikan status sukses. |
| **GET** | `teacher_status` | Publik | *None* | Mengembalikan JSON status autentikasi guru aktif `{ "authenticated": true/false, "must_change_password": true/false }`. |
| **GET** | `get_student_data` | Publik | *None* | Mengembalikan daftar kuis berstatus `ACTIVE` dan berada dalam jadwal pengerjaan. Seluruh kunci jawaban, pembahasan, dan PIN disaring (dihapus) demi keamanan sebelum dikirim ke siswa. |
| **GET** | `get_data` | Guru | *None* | Mengembalikan seluruh database mentah `db_kuis.json` (termasuk kunci jawaban, log, konfigurasi kuis) untuk keperluan sinkronisasi dashboard guru. |
| **GET** | `check_attempt` | Publik | `?nis=...&quiz_id=...` | Memeriksa apakah siswa bersangkutan sudah pernah menyelesaikan kuis ini, dan mengecek sisa kuota atau ketersediaan izin ujian ulang (`retake_allowed`). |
| **POST** | `start_attempt` | Publik | `{ "nis": "...", "name": "...", "class": "...", "quiz_id": "...", "pin": "..." }` | Memulai sesi ujian baru. Memeriksa ketersediaan jadwal, PIN kuis, dan kuota retake. Mengacak soal dan opsi secara server-side, menghasilkan token sesi acak (SHA-256 hashed di db), mengonfigurasi batas waktu (`ends_at`), lalu mengembalikan token ujian serta kuis versi teracak ke siswa. |
| **GET** | `get_attempt` | Publik | `?token=...` | Memulihkan sesi ujian aktif siswa yang sedang berjalan (re-hydration) untuk mencegah kehilangan progres jika tab tertutup. Mengembalikan soal dalam urutan acak yang konsisten dan waktu pengerjaan tersisa. |
| **POST** | `save_attempt_answers` | Publik | `{ "attempt_token": "...", "answers": { ... } }` | Menyimpan jawaban sementara siswa ke dalam draf pengerjaan sesi aktif server secara langsung. |
| **POST** | `submit_result` | Publik | `{ "attempt_token": "..." }` | Menyelesaikan ujian. Server mencocokkan draf jawaban, menerjemahkan urutan opsi teracak siswa ke urutan opsi asli, melakukan penilaian (scoring) seutuhnya di server, memindahkan sesi ke daftar `results` (maksimal 5 rekaman per kuis/NIS), mencabut izin retake terpakai, dan menghasilkan `review_token`. |
| **GET** | `get_review` | Publik | `?token=...` | Menarik pembahasan kuis berdasarkan `review_token`. Hasil detail, kunci jawaban, dan pembahasan hanya akan dikirimkan jika guru telah mengizinkan tinjauan kuis tersebut (`review_enabled = true`). |
| **POST** | `set_review_state` | Guru | `{ "quiz_id": "...", "enabled": true/false }` | Mengubah izin akses pembahasan dan kunci jawaban bagi siswa untuk paket kuis tertentu secara real-time. |
| **GET** | `get_review_states` | Guru | *None* | Mengembalikan seluruh pemetaan status izin pembahasan kuis aktif. |
| **POST** | `allow_retake` | Guru | `{ "nis": "...", "quiz_id": "...", "reason": "...", "attempts": 1, "valid_until": "..." }` | Mendaftarkan otorisasi manual ujian ulang untuk siswa tertentu beserta batasan kuota pengerjaan dan waktu berlaku. |
| **POST** | `save_questions` | Guru | Larik JSON daftar seluruh kuis | Menyimpan/memperbarui daftar kuis beserta konfigurasi soal-soalnya ke dalam database. |
| **POST** | `delete_result` | Guru | `{ "nis": "...", "quiz_id": "..." }` | Menghapus satu rekaman nilai siswa dari array hasil aktif dan memindahkannya ke dalam `trash['results']`. |
| **POST** | `delete_results` | Guru | `{ "items": [ ["nis", "quiz_id"], ... ] }` | Menghapus rekam nilai terpilih secara massal dan mengarsipkannya ke dalam keranjang sampah. |
| **GET** | `get_students` | Guru | *None* | Menarik seluruh daftar data profil siswa aktif. |
| **POST** | `save_student` | Guru | `{ "nis": "...", "name": "...", "class": "...", "homeroom": "...", "photo": "Base64..." }` | Menambah atau memperbarui profil siswa secara manual ke database. |
| **POST** | `delete_student` | Guru | `{ "nis": "..." }` | Menghapus data induk siswa dan mengarsipkannya ke `trash['students']`. |
| **POST** | `import_students` | Guru | `{ "students": [ ... ] }` | Mengimpor larik siswa secara massal dari Excel/CSV. Data dengan NIS yang sama akan ditimpa dengan data terbaru. |
| **POST** | `clear_results` | Guru | *None* | Mengosongkan seluruh database hasil ujian siswa. Sistem akan otomatis melakukan pencadangan otomatis (`create_backup`) berlabel `before-reset-results` di folder `backups/` demi mencegah bencana hilangnya data. |
| **GET** | `teacher_dashboard` | Guru | *None* | Mengembalikan statistik ringkas dashboard, status pengerjaan siswa aktif secara real-time (monitoring), dan daftar 200 aktivitas log audit guru terakhir. |
| **GET** | `exam_analytics` | Guru | `?quiz_id=...` | Menyediakan analisis mendalam performa per butir soal kuis (tingkat kesuksesan siswa menjawab benar per nomor soal). |
| **GET** | `get_trash` | Guru | *None* | Menampilkan daftar seluruh berkas logis yang ada di dalam keranjang sampah (`trash`). |
| **POST** | `restore_trash` | Guru | `{ "type": "results/students", "index": ... }` | Memulihkan kembali data yang terhapus dari keranjang sampah ke tabel aktif semula. |
| **POST** | `create_backup` | Guru | *None* | Melakukan duplikasi darurat berkas `db_kuis.json` ke direktori khusus `./backups/` dengan nama timestamp otomatis. |
| **GET** | `system_status` | Guru | *None* | Melakukan self-test diagnostik kesiapan infrastruktur web server lokal (status PHP, izin baca-tulis berkas database, sessions, backups). |
