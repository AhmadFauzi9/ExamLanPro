# 03 - Teacher Flow

Halaman pengelolaan guru pada **ExamLAN Pro** berada di bawah jalur terpisah `/guru/` (`guru/index.html`). Antarmuka ini bertindak sebagai panel kontrol terpadu untuk pengawasan ujian real-time, bank soal, data siswa, dan konfigurasi server.

## 1. Alur Autentikasi Keamanan & Sesi
1. **Pencegahan Bypass:** Ketika berkas `guru/index.html` dimuat di browser, elemen bodi utama guru diredam (`display: none !important`) demi keamanan.
2. **Pemeriksaan Sesi Aktif:**
   - Script segera memicu fungsi `checkTeacherSession()`. Fungsi ini mengirimkan fetch ke `api.php?action=teacher_status` menggunakan kredensial cookie (`credentials: 'same-origin'`).
   - Di sisi backend, fungsi `teacherOnly()` di `api.php` menguji eksistensi `$_SESSION['teacher_authenticated']` dan durasi aktivitas terakhir (maksimal 30 menit / 1800 detik).
   - **Kasus Belum Login:** Server merespons dengan status unauthorized. JavaScript menangkap respons ini lalu memunculkan modal login guru (`server-auth-modal`).
3. **Mekanisme Login & Rate Limiting:**
   - Guru menginput password pada form login. Form dikirimkan ke `api.php?action=teacher_login` melalui POST.
   - **Rate Limiting Sederhana:** Server memantau kegagalan login dalam variabel `$_SESSION['teacher_login_limit']`. Jika kegagalan login berturut-turut mencapai 5 kali, server mengembalikan status HTTP 429 (Too Many Attempts) dan memblokir login selama 5 menit (`time() + 300`).
   - **Verifikasi Sandi:** Server memverifikasi kecocokan sandi menggunakan `password_verify()`. Jika cocok, server meregenerasi ID sesi baru (`session_regenerate_id(true)`) untuk mencegah serangan *session fixation*, mencatat log audit "Login guru", dan membalas dengan parameter kesuksesan.
4. **Kewajiban Penggantian Sandi Default (Default Password Force-Change):**
   - Jika hash password guru masih merujuk pada hash asli (`admin123`), server mengirimkan bendera `must_change_password => true`.
   - JavaScript langsung memunculkan modal wajib ganti password (`change-password-modal`). Guru dipaksa memasukkan password baru minimal 8 karakter.
   - Input dikirim via POST ke `api.php?action=change_teacher_password`. Sandi dienkripsi menggunakan algoritma `PASSWORD_DEFAULT` (bcrypt) dan disimpan ke berkas database `db_kuis.json` dengan penanda status `teacher_password_changed => true`. Dashboard utama baru diizinkan terbuka setelah proses ini sukses.

## 2. Struktur Navigasi Sidebar (Desktop) dan Drawer (Mobile)
Dashboard menggunakan navigasi berbasis tab di sisi kiri (sidebar) pada desktop, memanfaatkan tata letak grid Tailwind CSS:
- Di desktop: `#view-teacher` diatur dengan `display: grid; grid-template-columns: 220px 1fr;` di mana sidebar navigasi menempel di kiri secara sticky.
- Di mobile (<768px): Sidebar berubah menjadi elemen navigasi horizontal yang dapat di-scroll secara independen (`overflow: auto`).

Perpindahan tab dikelola secara instan di sisi klien oleh fungsi JavaScript `switchTeacherTab(tab)` dengan rincian tab operasional berikut:

---

### A. Tab 1: Rekap & Manajemen Hasil Ujian (Results Tab)
Menu utama untuk mengawasi perolehan nilai siswa. Fitur yang tersedia:
1. **Ringkasan Statistik Utama:** Menampilkan total siswa, total kuis terdaftar, jumlah ujian hari ini, dan jumlah peserta aktif (monitoring langsung dari active attempts).
2. **Filter Kuis:** Menampilkan tabel hasil berdasarkan kuis spesifik atau menampilkan semua hasil sekaligus.
3. **Buka/Tutup Pembahasan:**
   - Tombol kontrol memicu fungsi `toggleTeacherReview()`. Guru mengirim permintaan ke `api.php?action=set_review_state` untuk mengubah status kuis.
   - Status disimpan di array `review_settings` dalam `db_kuis.json`. Jika diset `true`, browser siswa yang sedang menampilkan hasil ujian akan otomatis mendeteksi pembukaan kunci lewat polling, lalu memunculkan kunci jawaban dan pembahasan soal.
4. **Aksi per Baris Hasil:**
   - **Izinkan Ujian Ulang (Allow Retake):** Pemicuan modal retake. Guru menginput alasan, menetapkan jumlah kesempatan (1-10), dan waktu kedaluwarsa izin. Data dikirim ke POST `allow_retake` dan disimpan di tabel `retake_permissions`.
   - **Hapus Hasil:** Menghapus satu rekaman hasil pengerjaan siswa. Server akan memindahkan rekaman hasil tersebut ke folder keranjang sampah `trash['results']` agar dapat dipulihkan jika salah klik.
5. **Aksi Toolbar Utama:**
   - **Refresh Data:** Memaksa penarikan data terbaru dari server.
   - **Ekspor CSV:** JavaScript membaca data hasil pada tabel dan menghasilkan berkas teks CSV terenkode URI yang langsung dapat diunduh browser sebagai berkas laporan.
   - **Hapus Dipilih (Hapus Massal):** Mengirimkan daftar NIS dan Quiz ID terpilih ke server via POST `delete_results` untuk penghapusan massal.
   - **Hapus Semua / Reset Hasil:** Tombol pengosongan database hasil. Untuk keamanan, sistem secara otomatis melakukan pencadangan otomatis (`backupDb`) berlabel `before-reset-results` sebelum memindahkan seluruh rekaman hasil ke folder `trash['results']`.

---

### B. Tab 2: Bank Soal & Paket Kuis (Manage Tab)
Tempat pengelolaan paket kuis dan modifikasi butir soal secara visual.
1. **Manajemen Paket Kuis:**
   - Tombol "Buat Paket Kuis Baru" atau "Edit Kuis" memicu modal pendaftaran metadata kuis (Judul, Durasi, Mata Pelajaran, Kelas, Batas Waktu Akses, PIN Kuis, dan Status).
   - Pengaturan status kuis mendukung: `DRAFT`, `SIAP`, `ACTIVE` (aktif), `SELESAI`, dan `DIARSIPKAN`.
   - Sebelum status kuis diubah menjadi `ACTIVE`, server memicu validasi ketat `quizValidationErrors()` untuk memastikan kuis memiliki durasi valid, judul terisi, minimal ada 1 soal, dan seluruh opsi serta kunci jawaban telah lengkap.
2. **Pengelolaan Butir Soal:**
   - Mengklik "Kelola Soal" memunculkan dialog daftar soal kuis bersangkutan.
   - Guru dapat menambahkan atau menyunting soal secara rinci menggunakan form editor interaktif yang dinamis berdasarkan jenis soal terpilih:
     - **Pilihan Ganda Biasa (mc):** Form menyediakan input Opsi A-D dan tombol radio untuk menandai kunci jawaban tunggal.
     - **Pilihan Ganda Kompleks (complex):** Form menyediakan opsi centang (checkbox) untuk menandai lebih dari satu kunci jawaban yang benar.
     - **Sebab Akibat (cause_effect):** Form menyediakan input khusus "Pernyataan Utama" dan "Alasan Utama", serta dropdown kunci pilihan hubungan sebab akibat.
     - **Studi Kasus (case_analysis):** Form menyediakan kotak input area teks studi kasus/narasi cerita, opsi jawaban, dan radio kunci.
     - **Pilihan Berskala (scaled):** Form menyajikan pilihan skala Likert default dengan input teks kustom dan bobot poin nilai yang bisa diatur manual.
   - Teks pembahasan (eksplanasi ilmiah) dapat ditambahkan di bagian bawah untuk dipelajari siswa nantinya. Data disimpan ke server menggunakan POST `save_questions`.

---

### C. Tab 3: Import Soal Massal (Import Tab)
Mempermudah guru mengimpor ratusan soal sekaligus tanpa input satu per satu.
1. **Unduh Template:** Guru dapat mengunduh berkas template Excel (.xlsx) resmi yang digenerasi secara lokal via SheetJS atau mengunduh template struktur teks/word (.txt).
2. **Proses Unggah:**
   - Guru memilih paket kuis target, lalu mengunggah berkas template yang sudah diisi.
   - **Excel Parser:** JavaScript mengonversi larik baris Excel menjadi objek JSON terstruktur di sisi klien.
   - **Text Parser:** JavaScript membagi berkas teks menggunakan delimiter tiga garis (`---`) dan mengurai baris kunci-nilai (`TIPE:`, `PERTANYAAN:`, `KASUS:`, dll).
   - Hasil konversi dikirim sebagai larik JSON utuh ke API `save_questions` untuk disimpan langsung ke database server.

---

### D. Tab 4: Manajemen Data Siswa (Students Tab)
Menu khusus untuk mendaftarkan dan memvalidasi siswa yang berhak mengikuti ujian.
1. **Entri Manual:** Form pengisian NIS (unik), Nama, Kelas, Wali Kelas, dan unggahan foto profil siswa. Berkas foto dikonversi secara real-time menjadi string Base64 di browser dan dikirim via POST `save_student` ke server.
2. **Import Data Siswa:** Mengunduh template Excel data siswa, memproses berkas unggahan, dan menggabungkannya ke database via POST `import_students`. Jika terdapat NIS yang sama, data siswa lama akan ditimpa dengan data baru.
3. **Penghapusan Siswa:** Siswa yang dihapus akan dipindahkan sementara ke `trash['students']` via API `delete_student`.

---

### E. Tab 5: Monitoring, Log Audit & Pencadangan (Operations Tab)
Pusat kendali teknis server untuk memantau keadaan aplikasi:
1. **Monitoring Live:** Melakukan fetch periodik untuk memantau siswa yang sedang ujian (NIS, Nama, Kelas, Paket Kuis, jumlah soal yang telah dikerjakan, dan sisa waktu pengerjaan).
2. **Audit Log:** Rincian 200 aktivitas penting terakhir yang dilakukan oleh user 'guru' lengkap dengan parameter waktu, IP target, jenis tindakan, dan detail data.
3. **Pencadangan Berkas (Backup):** Mengklik "Backup Sekarang" memicu panggilan API `create_backup`. Server akan menduplikasi file database `db_kuis.json` ke dalam direktori `/backups/` dengan nama berformat timestamp: `examlan-YYYYMMDD-HHMMSS-manual.json`.
4. **Status Server (Diagnostik):** Menjalankan pemeriksaan API status server untuk menguji kelayakan sistem PHP, status writable direktori utama, folder sesi, folder backups, dan berkas JSON.

## 3. Alur Keluar Sesi (Logout)
- Mengklik tombol **Logout** memicu pemanggilan fungsi `lockTeacherServer()`.
- Browser mengirimkan permintaan POST ke `api.php?action=teacher_logout`.
- Backend memproses aksi logout:
  - Mencatat aksi "Logout guru" ke log audit.
  - Mengosongkan data superglobal `$_SESSION`.
  - Menghapus cookie session-name di browser dengan waktu kedaluwarsa masa lalu (`time() - 42000`).
  - Menghancurkan sesi server menggunakan `session_destroy()`.
- Browser mengalihkan kembali guru ke modal tampilan login awal.
