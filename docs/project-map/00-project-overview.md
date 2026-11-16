# 00 - Project Overview

## 1. Deskripsi Umum Aplikasi
**ExamLAN Pro** adalah aplikasi ujian sekolah berbasis Jaringan Area Lokal (LAN) yang dirancang khusus untuk lingkungan Lab Komputer sekolah atau pesantren secara offline. Aplikasi ini memisahkan secara ketat pengerjaan siswa dan manajemen guru, serta memastikan keamanan, kestabilan koneksi, dan kemudahan administrasi tanpa memerlukan internet eksternal.

## 2. Arsitektur Utama
Aplikasi ini dibangun menggunakan arsitektur monolitik ringan berbasis berkas (file-based) dengan rincian teknologi sebagai berikut:
- **Frontend Siswa:** Single Page Application (SPA) berbasis HTML5, CSS3 (Tailwind CSS v3 via CDN), dan JavaScript Vanilla (`index.html` di root).
- **Frontend Guru:** Single Page Application (SPA) responsif yang diletakkan di subdirektori tersendiri (`guru/index.html`).
- **Backend API:** Skrip PHP tunggal (`api.php`) di root yang menangani autentikasi sesi, manajemen data, validasi, pengacakan kuis, penyimpanan, dan sinkronisasi server-side.
- **Data Storage:** Penyimpanan berbasis berkas JSON (`db_kuis.json`) di root. Struktur internal mendukung pencadangan otomatis, log audit, trash bin, manajemen siswa, kuis, sesi ujian aktif, dan hasil pengerjaan.
- **Sistem Sesi:** Sesi PHP native disimpan secara lokal di folder server (`sessions/`) untuk mengisolasi dan membatasi akses guru.

## 3. Fitur Utama yang Berjalan (Actual State)
Berdasarkan hasil audit kode sumber aktual, berikut adalah fitur utama yang telah berhasil diimplementasikan sepenuhnya:
1. **Pemisahan Akses Siswa & Guru:** Siswa mengakses root `/index.html` sedangkan guru mengakses subfolder `/guru/index.html`.
2. **Server-Side Security & Grading:** Browser siswa tidak pernah memproses kunci jawaban, perhitungan skor, atau benar/salah. Jawaban siswa dievaluasi dan dihitung seutuhnya oleh `api.php`.
3. **Mekanisme Server Session (Tanpa Server Key):** Fitur "Server Key" telah dieliminasi sepenuhnya. Autentikasi guru menggunakan password hashing (`password_verify()` dengan default bcrypt) yang disimpan di `db_kuis.json`, sesi PHP dengan masa aktif 30 menit, dan kewajiban ganti password default pada login pertama.
4. **Sesi Ujian Siswa (Active Attempt):** Sesi ujian diinisiasi di server. Urutan soal dan urutan opsi diacak secara unik per siswa menggunakan algoritma `shuffledIndices()` berbasis `random_int()`, dan dipertahankan konsistensinya meskipun browser siswa di-refresh.
5. **Auto-Save & Sinkronisasi Lokal:** Jawaban siswa disimpan secara periodik ke server via API. Apabila koneksi LAN terputus, jawaban dicache di `localStorage` siswa dan disinkronkan kembali begitu koneksi terhubung.
6. **Kontrol Jawaban & Pembahasan:** Siswa tidak dapat melihat kunci jawaban dan pembahasan sebelum guru mengaktifkan status tinjauan (*review state*) untuk paket kuis tertentu melalui dashboard. Polling berkala dilakukan di browser siswa untuk mendeteksi perubahan izin ini.
7. **Penyimpanan Hasil & Batas Retake:** Hasil ujian siswa disimpan di server (maksimal 5 riwayat pengerjaan terakhir untuk NIS dan Kuis yang sama). Kesempatan ujian ulang (*retake*) dikelola secara individu melalui modal izin khusus di dashboard guru.
8. **Manajemen Data Siswa:** Guru dapat menambah, menyunting, menghapus, atau mengimpor data siswa secara massal menggunakan template Excel/CSV yang dikonversi langsung di browser via library SheetJS (`xlsx.full.min.js`).
9. **Log Audit & Status Server:** Setiap tindakan penting guru (login, logout, modifikasi kuis, izin retake, pemulihan sampah) tercatat di log audit. Menu Status Server melakukan diagnostik mandiri terhadap izin tulis berkas, folder cadangan, dan status PHP.
