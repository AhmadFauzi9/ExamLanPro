# 05 - Storage Map

Sistem penyimpanan data **ExamLAN Pro** mengandalkan pendekatan bebas mesin database relasional (database-less/file-based) dengan menggunakan berkas flat JSON tunggal (`db_kuis.json`) sebagai media penyimpanan utama, ditambah penyimpanan sesi PHP native dan folder backup eksternal.

## 1. Skema Basis Data JSON (`db_kuis.json`)
Struktur root berkas JSON ini dirancang dengan pengelompokan entitas logis yang rapi sebagai berikut:

```json
{
  "questions": [
    {
      "id": "quiz-1718000000000",
      "title": "Evaluasi Jaringan Komputer 7A",
      "duration": 30,
      "description": "Petunjuk: Pilihlah jawaban yang paling tepat.",
      "subject": "Informatika",
      "class": "7A",
      "start_at": "2026-09-20T08:00",
      "end_at": "2026-09-20T12:00",
      "status": "ACTIVE",
      "pin": "1234",
      "pin_enabled": true,
      "questions": [
        {
          "type": "mc",
          "question": "Manakah protokol yang berjalan di port 80?",
          "options": ["FTP", "SSH", "HTTP", "HTTPS"],
          "answer": 2,
          "explanation": "Port 80 adalah standar port non-secure untuk protokol HTTP."
        }
      ]
    }
  ],
  "results": [
    {
      "nis": "123456",
      "name": "Budi Santoso",
      "class": "7A",
      "code": "quiz-1718000000000",
      "title": "Evaluasi Jaringan Komputer 7A",
      "score": 100,
      "correct": 1,
      "wrong": 0,
      "date": "20/09/2026, 09.30.15",
      "breakdowns": [
        {
          "question": { "type": "mc", "question": "..." },
          "studentAnswer": 2,
          "isCorrect": true
        }
      ]
    }
  ],
  "review_settings": {
    "quiz-1718000000000": true
  },
  "retake_permissions": [
    {
      "nis": "123456",
      "quiz_id": "quiz-1718000000000",
      "reason": "Komputer mati mendadak",
      "attempts": 1,
      "valid_until": "2026-09-20T11:00",
      "used_count": 0
    }
  ],
  "students": [
    {
      "nis": "123456",
      "name": "Budi Santoso",
      "class": "7A",
      "homeroom": "Ust. Ahmad",
      "photo": "data:image/png;base64,iVBOR..."
    }
  ],
  "active_attempts": {
    "sha256_attempt_token_hash": {
      "token_hash": "sha256_attempt_token_hash",
      "quiz_id": "quiz-1718000000000",
      "nis": "123456",
      "name": "Budi Santoso",
      "class": "7A",
      "question_order": [0],
      "option_order": {
        "0": [2, 0, 1, 3]
      },
      "answers": {
        "0": 0
      },
      "started_at": 1718000100,
      "ends_at": 1718001900,
      "updated_at": 1718000200,
      "finished": false
    }
  },
  "audit_logs": [
    {
      "time": "2026-09-20T09:00:00+07:00",
      "user": "guru",
      "action": "Login guru",
      "target": "::1",
      "detail": ""
    }
  ],
  "backups": [
    {
      "file": "examlan-20260920-090522-manual.json",
      "time": "2026-09-20T09:05:22+07:00",
      "type": "manual"
    }
  ],
  "trash": {
    "results": [],
    "students": [],
    "quizzes": []
  },
  "teacher_password_hash": "$2y$10$xyz...",
  "teacher_password_changed": true
}
```

---

## 2. Struktur Penggunaan Ruang & Folder Lokal

1. **Folder `sessions/` (Sesi Server):**
   - Jalur: `./sessions/` (relatif terhadap `api.php`).
   - Sesi PHP native disimpan di sini alih-alih di folder default sistem operasi (`/tmp` atau `C:\Windows\Temp`). Hal ini mencegah kebocoran sesi di lingkungan hosting bersama (shared-hosting) dan memudahkan pembersihan berkas draf sesi guru yang sudah kedaluwarsa.
2. **Folder `backups/` (Cadangan Berkas JSON):**
   - Jalur: `./backups/` (relatif terhadap `api.php`).
   - Direktori ini menyimpan kompresi manual atau otomatis dari database `db_kuis.json`.
   - Berkas cadangan dinamai dengan stempel waktu: `examlan-[YYYYMMDD]-[HHMMSS]-[tipe].json`.
3. **Penyimpanan Gambar Profil Siswa:**
   - Foto profil siswa yang diunggah dikonversi di sisi klien menjadi teks string terenkode Base64 (Data URI scheme dengan MIME type `data:image/...;base64,...`).
   - String ini disimpan langsung di dalam larik objek `students` di dalam `db_kuis.json`. Dengan pendekatan ini, aplikasi tidak perlu mengelola unggahan berkas multipart biner secara fisik di server, memudahkan proses pencadangan (cukup salin satu file `db_kuis.json` sudah termasuk seluruh foto profil siswa).

---

## 3. Penanganan Operasi Konkurensi & Mutex-Locking
Untuk mengatasi potensi kerusakan data (race conditions) saat puluhan siswa mengirimkan jawaban pada detik yang sama di jaringan LAN, backend menerapkan mekanisme berikut pada fungsi I/O berkas:

1. **Eksklusivitas Penulisan (Exclusive Write Lock):**
   - Fungsi `saveDb()` dipanggil dengan argumen bendera `LOCK_EX` bawaan PHP pada skrip penulisan:
     `file_put_contents($file_db, json_encode($db, ...), LOCK_EX);`
   - Bendera `LOCK_EX` menginstruksikan sistem operasi untuk mengunci berkas `db_kuis.json` selama proses penulisan draf berlangsung. Proses penulisan dari request/klien lain akan ditangguhkan (queueing) sampai kunci dilepas, mencegah berkas kosong/corrupt akibat tabrakan I/O.
2. **Keterbatasan Tanpa Shared Lock saat Membaca:**
   - Audit menemukan fungsi `loadDb()` masih menggunakan `file_get_contents()` biasa tanpa kunci bersama (`LOCK_SH`). Hal ini berisiko kecil mengembalikan data kosong jika pembacaan terjadi tepat saat proses pemotongan penulisan berkas sedang berlangsung di request paralel.
