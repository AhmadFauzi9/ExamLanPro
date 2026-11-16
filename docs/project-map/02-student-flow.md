# 02 - Student Flow

Alur data dan perjalanan siswa dalam menyelesaikan ujian di **ExamLAN Pro** dirancang untuk menjamin integritas akademis dan ketahanan koneksi LAN. Berikut adalah diagram langkah-langkah prosedural secara aktual berdasarkan implementasi kode:

## 1. Tahap Persiapan & Inisialisasi Sesi
1. **Mengakses Aplikasi:** Siswa membuka browser dan mengakses URL root server (contoh: `http://192.168.1.100/kuis/`).
2. **Memuat Data Kuis Aktif:** 
   - `index.html` memanggil API `api.php?action=get_student_data` via fetch.
   - Server (`api.php`) memuat database `db_kuis.json` dan menyaring paket kuis yang berstatus `ACTIVE` dan berada dalam jadwal mulai/selesai (`quizAvailableNow()`).
   - Server menghapus semua kolom sensitif (`answer`, `answers`, `explanation`, `pin`) dari butir soal sebelum mengirimkannya ke siswa untuk mencegah kebocoran kunci jawaban lewat "Inspect Element".
   - Kuis yang lolos verifikasi dimasukkan ke dalam elemen dropdown `<select>` di halaman depan siswa.

## 2. Pendaftaran Identitas & Keamanan Akses
1. **Input Identitas:** Siswa mengisi formulir identitas:
   - Memilih Paket Kuis.
   - Memasukkan NIS (Nomor Induk Siswa/Santri) dengan ketentuan maksimal 6 digit angka (`maxlength="6"` dan regex `\d{6}`).
   - Memasukkan Nama Lengkap.
   - Memasukkan Kelas & Bagian (atau nomor PC).
   - Memasukkan PIN Ujian (opsional, baris input PIN ini otomatis muncul jika kuis yang dipilih mengaktifkan parameter `pin_enabled`).
2. **Pengecekan Izin Pengerjaan (Ujian Ulang):**
   - Sebelum form dikirim, sistem memicu pengecekan status ke `api.php?action=check_attempt&nis=...&quiz_id=...`.
   - Server memeriksa apakah siswa dengan NIS tersebut sudah memiliki rekaman di daftar `results`.
   - Jika sudah pernah mengerjakan, server memeriksa entri di `retake_permissions` untuk NIS + Quiz ID tersebut.
   - Jika tidak ada izin ujian ulang yang aktif atau kesempatan telah habis, pengerjaan ditolak dengan pesan modal modal informatif.
3. **Konfirmasi Mulai:**
   - Jika diizinkan, siswa disuguhkan modal konfirmasi kustom: *"Mulai ujian ... ? Waktu dan urutan soal akan ditetapkan server."*
   - Ketika diklik **Mulai**, frontend mengirimkan permintaan POST ke `api.php?action=start_attempt` berisi paket kuis, identitas, dan PIN.

## 3. Pembuatan Sesi Ujian Server (Secure In-Progress State)
1. **Inisialisasi Sesi di Server:**
   - Server memverifikasi kecocokan PIN kuis (menggunakan `hash_equals()` untuk keamanan konstan) dan batas waktu.
   - Server menghasilkan token sesi unik berupa hash acak 64 karakter (`bin2hex(random_bytes(32))`).
   - Server mengacak urutan soal siswa dengan algoritma Fisher-Yates (`shuffledIndices()`).
   - Server mengacak urutan opsi pilihan jawaban (A, B, C, D) secara unik per butir soal siswa.
   - Server menyimpan rancangan urutan soal, urutan opsi, waktu habis (`ends_at`), serta identitas siswa ke dalam struktur `active_attempts` di `db_kuis.json` dengan kunci berupa hash SHA-256 dari token (`attemptKey($token)`).
   - Server mengirimkan respons sukses beserta token ujian asli kepada klien siswa.
2. **Penyimpanan Sesi Klien:**
   - Token ujian disimpan di `localStorage` siswa dengan nama kunci `examlan_active_attempt`.
   - Hal ini memastikan apabila tab browser tidak sengaja tertutup atau siswa me-refresh halaman, frontend dapat melakukan *re-hydration* dengan memanggil `api.php?action=get_attempt&token=...` untuk memulihkan soal ujian dan sisa waktu pengerjaan secara akurat (tanpa mengulang waktu dari nol atau mengubah urutan acak soal).

## 4. Tahap Pengerjaan & Auto-Save
1. **Navigasi Soal:**
   - Antarmuka menampilkan satu soal aktif beserta tombol navigasi "Sebelumnya", "Selanjutnya", dan panel "Navigasi Soal" di sidebar.
   - Sidebar navigasi soal menampilkan tombol angka dengan indikasi warna interaktif:
     - **Biru dengan Ring:** Soal sedang aktif dibuka.
     - **Hijau:** Soal sudah dijawab.
     - **Slate/Abu-abu:** Soal belum diisi.
2. **Penyimpanan Otomatis (Auto-Save):**
   - Setiap kali siswa memilih opsi jawaban, jawaban disimpan sementara di variabel memori lokal `studentAnswers` dan memicu antrean penyimpanan `queueAnswerSave()` (dengan delay debouncing 500ms).
   - Sinkronisasi periodik dijalankan setiap 8 detik via interval timer ke endpoint `api.php?action=save_attempt_answers`.
3. **Penanganan LAN Terputus (Offline Resilience):**
   - Jika request penyimpanan API gagal (karena kabel LAN dicabut atau server mati), status save-indicator di kanan atas berubah menjadi kuning: `⚠ Koneksi terputus — jawaban disimpan lokal`.
   - Jawaban tetap disimpan aman di `localStorage` siswa di bawah nama `examlan_pending_answers`.
   - Frontend memasang event listener `'online'`. Ketika jaringan LAN pulih, antrean jawaban offline langsung dikirim secara otomatis ke server dan cache lokal dihapus.

## 5. Validasi dan Pengiriman Hasil Akhir
1. **Pemeriksaan Jawaban Kosong:**
   - Saat siswa mengklik tombol "Kirim Jawaban", JavaScript memeriksa kelengkapan jawaban.
   - Jika terdeteksi ada soal yang belum dijawab, pengumpulan **DITOLAK**. Sistem menampilkan modal peringatan berisi daftar nomor soal yang masih kosong dan melompat langsung ke nomor kosong pertama jika disetujui.
2. **Penyelesaian Ujian (Submission):**
   - Jika semua soal terisi, modal konfirmasi akhir muncul. Klik "Kirim Jawaban" memicu POST ke `api.php?action=submit_result`.
   - Di sisi server, `api.php` mengambil data sesi ujian siswa dari `active_attempts` via token.
   - **Translasi Kunci:** Karena browser mengirimkan posisi indeks opsi yang diacak klien, server memetakan kembali nilai pilihan siswa tersebut ke indeks asli menggunakan data `option_order` yang tersimpan aman di server sebelum menghitung nilai. (Kunci jawaban asli tidak pernah dikirim ke klien siswa selama ujian).
   - **Evaluasi Nilai:** Server menghitung total benar, salah, dan nilai akhir berdasarkan jenis soal (pilihan ganda, kompleks, sebab-akibat, atau berskala).
   - **Persistensi Rekaman:** Server memindahkan rekam ujian ke array `results`. Jika siswa memiliki riwayat pengerjaan sebelumnya untuk kuis yang sama, database hanya menyimpan maksimal 5 riwayat pengerjaan terakhir untuk menghemat kapasitas berkas JSON. Sesi aktif di `active_attempts` dinonaktifkan (`finished = true`).
   - Server menerbitkan token tinjauan unik (`review_token`) untuk siswa.

## 6. Persistensi Halaman Hasil & Polling Pembahasan
1. **Penguncian Halaman Hasil:**
   - Setelah sukses mengumpulkan, detail rekap hasil (skor, benar, salah, akurasi) disimpan dalam `sessionStorage` dengan nama `examlan_student_session`.
   - Sesi ini memblokir siswa agar tidak bisa kembali ke halaman login identitas saat browser di-refresh. Halaman hasil akan tetap terkunci dan tampil secara konsisten.
2. **Polling Kunci Jawaban & Pembahasan:**
   - Halaman hasil segera memulai polling berkala via fetch ke `api.php?action=get_review&token=review_token` setiap 3 detik.
   - **Jika Guru Belum Membuka:** API merespons dengan status `allowed: false` dan menyembunyikan rincian evaluasi butir soal.
   - **Jika Guru Membuka Tinjauan:** Status tinjauan kuis di server diset menjadi `review_enabled = true`. Polling mendeteksi perubahan ini (`allowed: true`) dan menerima data `breakdowns` berisi butir soal, pilihan siswa, kunci jawaban tepat, dan teks penjelasan/pembahasan ilmiah untuk dirender di layar siswa.
