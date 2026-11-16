# 01 - File Map

Berikut adalah pemetaan berkas dan struktur direktori aktual dalam workspace aplikasi ujian "ExamLAN Pro":

## 1. Struktur Direktori Aktual
```text
C:\xampp\htdocs\kuis\
├── api.php                             # Backend API utama (skrip tunggal PHP)
├── index.html                          # Entry point & interface utama siswa
├── db_kuis.json                        # Penyimpanan basis data berkas JSON utama
├── api_fase1.php                       # File sisa / placeholder (tidak digunakan, hanya berisi string dummy)
├── README.txt                          # Dokumentasi singkat cara pemasangan & pembaharuan
├── UPGRADE_PROMPT.txt                  # Berkas instruksi upgrade fase keamanan & sistem ujian
├── tes_gemini.js                       # Skrip pengujian terisolasi Google GenAI SDK
├── ExamLAN_Pro_Siswa_Guru_SERVER_FIX.zip # Arsip cadangan distribusi asli
├── ExamLAN_Pro_UPGRADED_PHASE1.zip     # Arsip cadangan distribusi fase pembaruan
│
├── guru/                               # Direktori khusus operasional guru/server
│   ├── index.html                      # Dashboard utama guru & form login guru
│   └── .htaccess                       # Konfigurasi Apache untuk pengamanan folder guru
│
├── sessions/                           # Folder penyimpanan berkas sesi PHP native (dibuat otomatis)
│   ├── sess_7g9i0kmapne9p5o9o8ue5sq78g # Contoh berkas sesi tersimpan
│   └── sess_fdi6m8edmtcd1e1q89udos2sth
│
└── backups/                            # Folder penyimpanan cadangan otomatis (dibuat otomatis)
    └── examlan-*.json                  # Hasil penyalinan berkas db_kuis.json
```

## 2. Deskripsi dan Peran Berkas Utama

### A. Backend (`api.php`)
- **Fungsi:** Sebagai pengatur tunggal (controller) logika aplikasi, autentikasi, otorisasi, pengacakan soal, perhitungan nilai ujian, dan operasi I/O terhadap `db_kuis.json`.
- **Fitur Utama:**
  - Menginisiasi direktori `sessions/` dan mengonfigurasi parameter session cookie (`httponly`, `secure`, `SameSite=Lax`).
  - Menyediakan penanganan cors opsional (tidak menggunakan wildcard agar kredensial session cookie tetap terbawa saat panggilan API dari subfolder `/guru/`).
  - Menangani fungsi-fungsi enkripsi password (`password_hash()`, `password_verify()`).
  - Memproses grading (penilaian) secara real-time di server untuk mencegah kecurangan manipulasi nilai di sisi klien.

### B. Frontend Siswa (`index.html`)
- **Fungsi:** Menyediakan antarmuka pengerjaan ujian untuk siswa.
- **Fitur Utama:**
  - Form login/pendaftaran siswa (NIS, Nama, Kelas, pilihan Kuis, PIN opsional).
  - Tampilan pengerjaan dengan panel navigasi nomor soal yang interaktif (sidebar/drawer).
  - Integrasi auto-save berkala ke server (setiap 8 detik) dan penanganan koneksi offline menggunakan `localStorage` serta sinkronisasi ulang saat kembali online.
  - Pengambilan hasil ujian serta modul polling real-time untuk pembukaan kunci pembahasan kuis oleh guru.
  - Modul notifikasi dan konfirmasi kustom via CSS modal (tanpa `alert()` atau `confirm()` bawaan browser).

### C. Frontend Guru (`guru/index.html`)
- **Fungsi:** Dashboard kontrol utama bagi guru atau admin server.
- **Fitur Utama:**
  - Form login kredensial guru menggunakan otentikasi session-cookie.
  - Sesi penggantian password wajib jika guru masih menggunakan hash default.
  - Desain navigasi berbasis sidebar di layar desktop dan drawer responsif di layar mobile.
  - Tabulasi menu operasional terpadu:
    - **Hasil Ujian:** Monitoring rekap nilai, cetak, hapus terpilih/semua, ekspor CSV, dan izin ujian ulang per baris.
    - **Bank Soal & Paket Kuis:** Menyusun metadata paket, mengaktifkan kuis, menyunting soal manual dengan 5 tipe soal.
    - **Import Soal Massal:** Mengunduh template Excel (.xlsx) atau Teks (.txt) dan mengunggahnya langsung ke server API.
    - **Data Siswa:** Manajemen detail profil siswa beserta foto (disimpan sebagai Base64) dan template import Excel/CSV.
    - **Monitoring & Audit:** Memantau peserta ujian aktif secara langsung, daftar aktivitas (audit log), dan status server.

### D. Storage Data (`db_kuis.json`)
- **Fungsi:** Menyimpan seluruh data persisten sistem.
- **Isi Struktur Root JSON:**
  - `questions`: Kumpulan paket kuis beserta rincian butir soal, kunci jawaban asli, bobot poin, dan pembahasan lengkap.
  - `results`: Rekam medis ujian siswa (NIS, nama, kelas, kuis, skor, jumlah benar/salah, tanggal submit, review token, urutan pengacakan soal, jawaban asli, breakdown evaluasi).
  - `review_settings`: Pengaturan boolean pembukaan pembahasan ujian per ID kuis.
  - `retake_permissions`: Riwayat otorisasi kesempatan ujian ulang (retake) siswa.
  - `students`: Profil data induk siswa (NIS, nama, kelas, wali kelas, foto Base64).
  - `active_attempts`: Sesi ujian berjalan yang diamankan di server menggunakan hash token SHA-256.
  - `audit_logs`: Rekaman log audit sistem guru (maksimal 1000 entri terakhir).
  - `backups`: Daftar catatan pencadangan data berkas.
  - `trash`: Keranjang sampah logis (`results`, `students`, `quizzes`) untuk mencegah kehilangan data permanen akibat salah hapus.
  - `teacher_password_hash`: Hash password akun guru yang diamankan.
  - `teacher_password_changed`: Flag penanda jika password default telah diganti.
