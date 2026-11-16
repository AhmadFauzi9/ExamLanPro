# 07 - Exam Flow

Sistem ujian **ExamLAN Pro** mengimplementasikan logika pengerjaan yang ketat di sisi server untuk mencegah berbagai teknik kecurangan yang umum terjadi pada aplikasi ujian berbasis web. Berikut adalah pemetaan mendalam terhadap alur teknis butir soal, pengacakan, dan penilaian.

## 1. Mekanisme Pengacakan (Randomization Strategy)
Pengacakan dilakukan sepenuhnya di sisi server (`api.php`) saat permintaan `start_attempt` diterima.

1. **Pengacakan Nomor Soal:**
   - Server menggunakan algoritma pengacakan berbasis `random_int()` untuk menghasilkan urutan indeks soal yang unik bagi setiap siswa.
   - Urutan ini disimpan dalam larik `question_order` di dalam entitas `active_attempts` pada database.
   - Hal ini memastikan bahwa meskipun siswa bersebelahan mengerjakan kuis yang sama, nomor soal 1 pada siswa A akan berbeda dengan nomor soal 1 pada siswa B.
2. **Pengacakan Opsi Jawaban (Option Shuffling):**
   - Selain urutan soal, urutan pilihan jawaban (A, B, C, D) juga diacak per butir soal per siswa.
   - Peta urutan opsi disimpan dalam objek `option_order` (misal: `{"0": [2, 0, 1, 3]}`).
   - Artinya, jika kunci jawaban asli soal nomor 0 adalah indeks 2 (pilihan C), maka pada tampilan siswa tersebut, pilihan C asli mungkin akan muncul di posisi paling atas (indeks 0).
3. **Persistensi Pengacakan:** Karena urutan disimpan di server, me-refresh halaman tidak akan mengubah urutan yang sudah ditetapkan bagi siswa tersebut sampai ujian berakhir.

---

## 2. Tipe Soal yang Didukung
Aplikasi mendukung 5 variasi tipe soal modern yang dapat diatur melalui dashboard guru:

| Tipe | Nama | Mekanisme Penilaian (Grading) |
| :--- | :--- | :--- |
| `mc` | Pilihan Ganda Biasa | Skor 100% jika indeks pilihan siswa cocok dengan kunci jawaban `answer`. |
| `complex` | Ganda Kompleks | Siswa dapat memilih lebih dari satu jawaban. Skor diberikan jika seluruh set jawaban siswa (`answers`) tepat sama dengan kunci jawaban tanpa ada yang kurang atau lebih. |
| `cause_effect` | Sebab Akibat | Menampilkan dua pernyataan (Pernyataan & Alasan). Siswa memilih salah satu dari 5 kategori hubungan sebab akibat. |
| `case_analysis` | Analisis Kasus | Serupa dengan `mc` namun memiliki kolom teks narasi/cerita (`passage`) yang ditampilkan sebagai konteks pertanyaan. |
| `scaled` | Pilihan Berskala | Digunakan untuk evaluasi (Likert Scale). Setiap opsi memiliki bobot poin manual. Skor akhir dihitung berdasarkan rasio poin yang didapat terhadap total poin maksimal. |

---

## 3. Sistem Pengawasan & Integritas (Anti-Cheat)
1. **Grading Server-Side:** Frontend (`index.html`) tidak pernah melakukan perhitungan nilai. Saat tombol "Kirim Jawaban" diklik, frontend hanya mengirimkan token sesi. Server akan memuat jawaban dari draf tersimpan, mencocokkannya dengan kunci asli di database, dan menghitung skor. Hal ini menutup celah manipulasi variabel `score` via console browser.
2. **Eliminasi Kunci Jawaban di Klien:** Panggilan API `get_student_data` dan `get_attempt` secara eksplisit menghapus properti `answer`, `answers`, dan `explanation` dari objek soal. Siswa tidak akan pernah menemukan kunci jawaban di dalam tab *Network* atau *Source* pada Developer Tools.
3. **Batas Waktu Server-Side:** Server menyimpan timestamp `ends_at`. Jika siswa mengirimkan jawaban melampaui batas waktu tersebut, server akan menolak atau memotong durasi pengerjaan secara otomatis.
4. **Validasi PIN:** Kuis dapat dilindungi dengan PIN dinamis yang harus dimasukkan siswa sebelum memulai. PIN ini diverifikasi di server menggunakan fungsi `hash_equals()` untuk mencegah serangan *timing attack*.

---

## 4. Siklus Hidup Jawaban (Auto-Sync)
- **Status "Menyimpan":** Setiap perubahan jawaban di klien memicu penanda visual `⟳ Menyimpan…`.
- **Debouncing:** Pengiriman ke server dilakukan setelah siswa berhenti memilih selama 500ms atau secara berkala setiap 8 detik untuk mengoptimalkan lalu lintas jaringan LAN.
- **Retry Mechanism:** Jika koneksi LAN terganggu, sistem akan masuk ke mode "Saved Locally" dan akan mencoba mengirim ulang seluruh antrean jawaban secara otomatis begitu pendeteksi `window.online` aktif kembali.
