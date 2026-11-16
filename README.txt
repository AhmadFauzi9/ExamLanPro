ExamLAN Pro - Siswa & Guru Terpisah

Siswa: http://IP-SERVER/kuis/
Guru:  http://localhost/kuis/guru/

Fitur versi ini:
- Dashboard hasil guru: Checkbox | No | NIS | Nama Siswa | Kelas & Bagian | Nama Kuis | Benar | Salah | Nilai Akhir | Aksi.
- Aksi per baris: Izinkan Ujian Ulang + Hapus.
- Tombol Hapus Semua/Reset Hasil berada di toolbar bersama Refresh Data dan Ekspor.
- Hapus Dipilih tersedia di toolbar untuk hasil yang dicentang.
- Navigasi sidebar nomor soal dan lompat langsung ke soal.
- Semua soal wajib dijawab sebelum pengumpulan.
- Login guru wajib mengganti password default pada login pertama.
- Data siswa: Foto, NIS, Nama, Kelas & Bagian, Wali Kelas; manual atau template Excel/CSV.
- Notifikasi aplikasi menggunakan modal, bukan alert/confirm browser.
- Paket kuis mendukung status, mata pelajaran, kelas, jadwal akses, dan PIN opsional.
- Sidebar guru menyediakan monitoring, audit log, backup manual, dan status server.

ExamLAN Pro — Pembaruan aman (fase keamanan & sistem ujian)
===========================================================

Cara update tanpa menghapus data
--------------------------------
1. Cadangkan db_kuis.json yang sudah ada.
2. Ganti hanya index.html, guru/index.html, dan api.php dengan versi pembaruan.
3. Jangan mengganti db_kuis.json. Struktur baru (sesi aktif, audit, trash, dan
   backup) akan ditambahkan otomatis saat API melakukan penyimpanan berikutnya.
4. Pastikan Apache dapat menulis pada folder kuis. Folder sessions dan backups
   dibuat otomatis bila diperlukan.
5. Buka /kuis/guru/ dan login. Password default wajib diganti pada login pertama.

Perubahan inti
--------------
- Mode siswa memakai sesi ujian server: waktu, urutan soal, urutan opsi, dan
  jawaban aktif disimpan di server.
- Nilai, benar, dan salah dihitung sepenuhnya di api.php.
- Siswa tidak menerima kunci atau pembahasan sebelum guru mengaktifkan review.
- Jawaban disimpan saat berubah, berkala, dan dicache lokal saat LAN putus.
- Dashboard guru tetap berada di /guru/ dan hanya bisa dibuka dengan sesi guru.
- Tombol/fitur Kunci Server/Server Key telah dihapus; yang tersedia adalah Logout.
