<?php
// Session guru harus tetap terbawa pada request API berikutnya.
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$sessionDir = __DIR__ . '/sessions';
if (!is_dir($sessionDir)) @mkdir($sessionDir, 0750, true);
if (is_dir($sessionDir)) session_save_path($sessionDir);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Dashboard guru dan API digunakan dalam origin yang sama. Tidak menggunakan
// Access-Control-Allow-Origin: * karena wildcard tidak kompatibel dengan credentials.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$file_db = __DIR__ . '/db_kuis.json';
// Ganti password default ini dengan password guru Anda sendiri.
$TEACHER_PASSWORD_HASH = '$2y$12$Id0m0N5GLQeEaxfBCE9HW.vUv.C3bJR/BrJ6MPJKGmPTbGFX2ZC1m'; // admin123

if (!file_exists($file_db)) {
    file_put_contents($file_db, json_encode([
        'questions' => [],
        'results' => [],
        'review_settings' => [],
        'retake_permissions' => [],
        'students' => [],
        'active_attempts' => [],
        'student_sessions' => [],
        'audit_logs' => [],
        'backups' => [],
        'trash' => ['results' => [], 'students' => [], 'quizzes' => []],
        'teacher_password_hash' => $TEACHER_PASSWORD_HASH,
        'teacher_password_changed' => false
    ], JSON_PRETTY_PRINT));
}

function generateStudentPassword($length = 6) {
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits = '23456789';
    $all = $letters . $digits;
    $pass = $letters[random_int(0, strlen($letters) - 1)] . $digits[random_int(0, strlen($digits) - 1)];
    for ($i = 2; $i < $length; $i++) {
        $pass .= $all[random_int(0, strlen($all) - 1)];
    }
    return str_shuffle($pass);
}

function loadDb($file_db) {
    $db = json_decode(file_get_contents($file_db), true);
    if (!is_array($db)) $db = [];
    $db['questions'] = $db['questions'] ?? [];
    $dirty = false;
    $now = time();
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    foreach ($db['questions'] as &$q) {
        if (empty($q['pin'])) {
            $q['pin'] = substr(str_shuffle($chars), 0, 6);
            $q['pin_enabled'] = true;
            $q['pin_updated_at'] = $now;
            $q['pin_auto_refresh'] = true;
            $dirty = true;
        }

        // Fitur rotasi token otomatis setiap 10 menit (600 detik) - Strict Security (tanpa toleransi token lama)
        $autoRefresh = ($q['pin_auto_refresh'] ?? true);
        if ($autoRefresh && !empty($q['pin'])) {
            $updatedAt = (int)($q['pin_updated_at'] ?? 0);
            if ($updatedAt <= 0) {
                $q['pin_updated_at'] = $now;
                $dirty = true;
            } elseif (($now - $updatedAt) >= 600) {
                // Rotasi token baru secara ketat
                $q['pin'] = substr(str_shuffle($chars), 0, 6);
                $q['pin_updated_at'] = $now;
                unset($q['prev_pin'], $q['prev_pin_expires_at']);
                $dirty = true;
            }
        }
    }
    unset($q);
    $db['results'] = $db['results'] ?? [];
    $db['review_settings'] = $db['review_settings'] ?? [];
    $db['retake_permissions'] = $db['retake_permissions'] ?? [];
    $db['students'] = $db['students'] ?? [];
    foreach ($db['students'] as &$st) {
        if (empty($st['password'])) {
            $st['password'] = generateStudentPassword();
            $dirty = true;
        }
    }
    unset($st);
    $db['active_attempts'] = $db['active_attempts'] ?? [];
    $db['student_sessions'] = $db['student_sessions'] ?? [];
    $db['audit_logs'] = $db['audit_logs'] ?? [];
    $db['backups'] = $db['backups'] ?? [];
    $db['time_extension_requests'] = $db['time_extension_requests'] ?? [];
    $db['settings'] = $db['settings'] ?? [];
    if (!isset($db['settings']['require_student_password'])) {
        $db['settings']['require_student_password'] = true;
        $dirty = true;
    }
    $db['teacher_password_hash'] = $db['teacher_password_hash'] ?? $TEACHER_PASSWORD_HASH;
    $db['teacher_password_changed'] = $db['teacher_password_changed'] ?? false;
    if ($dirty) saveDb($file_db, $db);
    return $db;
}

function saveDb($file_db, $db) {
    file_put_contents($file_db, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function audit(&$db, $action, $target = '', $detail = '') {
    $db['audit_logs'][] = ['time' => date('c'), 'user' => 'guru', 'action' => $action, 'target' => $target, 'detail' => $detail];
    if (count($db['audit_logs']) > 1000) $db['audit_logs'] = array_slice($db['audit_logs'], -1000);
}

function backupDb($file_db, &$db, $reason) {
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $name = 'examlan-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9_-]/i', '-', $reason) . '.json';
    if (is_dir($dir) && @copy($file_db, $dir . '/' . $name)) {
        $db['backups'][] = ['file' => $name, 'time' => date('c'), 'reason' => $reason];
    }
}

function teacherOnly() {
    $timeout = 1800;
    if (empty($_SESSION['teacher_authenticated']) || empty($_SESSION['teacher_last_activity']) || time() - $_SESSION['teacher_last_activity'] > $timeout) {
        $_SESSION = [];
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Akses guru ditolak. Silakan login sebagai guru.']);
        exit;
    }
    $_SESSION['teacher_last_activity'] = time();
}

function shuffledIndices($count) { if ($count < 1) return []; $a = range(0, $count - 1); for ($i = count($a) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$a[$i], $a[$j]] = [$a[$j], $a[$i]]; } return $a; }
function attemptKey($token) { return hash('sha256', (string)$token); }
function studentAttempt(&$db, $token) { $key = attemptKey($token); return $db['active_attempts'][$key] ?? null; }
function publicAttemptQuiz($quiz, $attempt) {
    $out = ['id' => $quiz['id'], 'title' => $quiz['title'] ?? '', 'duration' => $quiz['duration'] ?? 0, 'questions' => []];
    foreach (($attempt['question_order'] ?? []) as $qid) {
        if (!isset($quiz['questions'][$qid])) continue;
        $q = $quiz['questions'][$qid]; unset($q['answer'], $q['answers'], $q['explanation']);
        $q['_student_id'] = (string)$qid;
        if (isset($attempt['option_order'][$qid]) && is_array($q['options'] ?? null)) {
            $ordered = [];
            foreach ($attempt['option_order'][$qid] as $original) if (array_key_exists($original, $q['options'])) $ordered[] = $q['options'][$original];
            $q['options'] = $ordered;
        }
        $out['questions'][] = $q;
    }
    return $out;
}
function quizValidationErrors($quiz) {
    $errors = []; $questions = $quiz['questions'] ?? [];
    if (trim((string)($quiz['title'] ?? '')) === '') $errors[] = 'Judul wajib diisi';
    if ((int)($quiz['duration'] ?? 0) < 1) $errors[] = 'Durasi belum valid';
    if (!$questions) $errors[] = 'Minimal satu soal diperlukan';
    foreach ($questions as $i => $q) {
        $type = $q['type'] ?? 'mc';
        $num = $i + 1;

        switch ($type) {
            case 'mc':
                if (trim((string)($q['question'] ?? '')) === '') $errors[] = 'Soal ' . $num . ' belum lengkap: teks pertanyaan wajib diisi';
                if (empty($q['options']) || !is_array($q['options'])) $errors[] = 'Opsi soal ' . $num . ' belum lengkap';
                if (!array_key_exists('answer', $q) || $q['answer'] === null || $q['answer'] === '') $errors[] = 'Kunci soal ' . $num . ' belum diisi';
                break;

            case 'complex':
                if (trim((string)($q['question'] ?? '')) === '') $errors[] = 'Soal ' . $num . ' belum lengkap: teks pertanyaan wajib diisi';
                if (empty($q['options']) || !is_array($q['options'])) $errors[] = 'Opsi soal ' . $num . ' belum lengkap';
                if (empty($q['answers']) || !is_array($q['answers'])) $errors[] = 'Kunci soal ' . $num . ' belum diisi';
                break;

            case 'cause_effect':
                $stmt = trim((string)($q['statement'] ?? ''));
                $qText = trim((string)($q['question'] ?? ''));
                $reason = trim((string)($q['reason'] ?? ''));
                // Wajib ada statement (atau question), dan reason. Tidak memvalidasi options.
                if ($stmt === '' && $qText === '') $errors[] = 'Soal ' . $num . ' belum lengkap: pernyataan wajib diisi';
                if ($reason === '') $errors[] = 'Soal ' . $num . ' belum lengkap: alasan wajib diisi';
                if (!array_key_exists('answer', $q) || $q['answer'] === null || $q['answer'] === '') $errors[] = 'Kunci soal ' . $num . ' belum diisi';
                break;

            case 'case_analysis':
                if (trim((string)($q['passage'] ?? '')) === '') $errors[] = 'Soal ' . $num . ' belum lengkap: teks kasus wajib diisi';
                if (trim((string)($q['question'] ?? '')) === '') $errors[] = 'Soal ' . $num . ' belum lengkap: pertanyaan wajib diisi';
                if (empty($q['options']) || !is_array($q['options'])) $errors[] = 'Opsi soal ' . $num . ' belum lengkap';
                if (!array_key_exists('answer', $q) || $q['answer'] === null || $q['answer'] === '') $errors[] = 'Kunci soal ' . $num . ' belum diisi';
                break;

            case 'scaled':
                if (trim((string)($q['question'] ?? '')) === '') $errors[] = 'Soal ' . $num . ' belum lengkap: teks pertanyaan wajib diisi';
                if (empty($q['options']) || !is_array($q['options'])) $errors[] = 'Opsi soal ' . $num . ' belum lengkap';
                // Soal berskala tidak memerlukan kunci jawaban tunggal (setiap opsi memiliki poin)
                break;

            case 'matching':
                if (trim((string)($q['question'] ?? '')) === '') $errors[] = 'Soal ' . $num . ' belum lengkap: teks pertanyaan menjodohkan wajib diisi';
                if (empty($q['premises']) || empty($q['targets'])) $errors[] = 'Pasangan premis dan target pada soal ' . $num . ' belum lengkap';
                break;

            case 'short_answer':
                if (trim((string)($q['question'] ?? '')) === '') $errors[] = 'Soal ' . $num . ' belum lengkap: teks pertanyaan isian singkat wajib diisi';
                if (!array_key_exists('answer', $q) || trim((string)$q['answer']) === '') $errors[] = 'Kunci jawaban isian singkat soal ' . $num . ' belum diisi';
                break;

            case 'essay':
                if (trim((string)($q['question'] ?? '')) === '') $errors[] = 'Soal ' . $num . ' belum lengkap: teks pertanyaan uraian/esai wajib diisi';
                break;

            default:
                $main = trim((string)($q['question'] ?? ''));
                if ($main === '') $main = trim((string)($q['statement'] ?? ''));
                if ($main === '') $errors[] = 'Soal ' . $num . ' belum lengkap';
                break;
        }
    }
    return array_values(array_unique($errors));
}
function quizAvailableNow($quiz) {
    if (($quiz['status'] ?? 'ACTIVE') !== 'ACTIVE') return 'Paket kuis belum aktif.';
    $now = time(); if (!empty($quiz['start_at']) && strtotime($quiz['start_at']) > $now) return 'Ujian belum memasuki jadwal mulai.';
    if (!empty($quiz['end_at']) && strtotime($quiz['end_at']) < $now) return 'Masa akses ujian telah berakhir.';
    return null;
}

function normalizeAnswers($value) {
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $k => $v) {
        $out[(int)$k] = is_array($v) ? array_map('intval', $v) : $v;
    }
    return $out;
}

function normalizeCauseEffectKey($key) {
    if ($key === null) return '';
    $v = strtolower(trim((string)$key));
    if ($v === '') return '';

    $clean = preg_replace('/[^a-z0-9]/', '', $v);

    // Option A (Pernyataan & Alasan Benar, Berhubungan Sebab Akibat)
    if (in_array($clean, [
        'a', '1', '0',
        'bothcorrectconnected', 'bothtrueconnected', 'bothcorrectrelated',
        'bothcorrect', 'bothtrue',
        'keduanyabenarberhubungan', 'keduanyabenardansalingberhubungan',
        'benarbenarberhubungan', 'keduanyabenar'
    ], true)) {
        return 'both_correct_connected';
    }

    // Option B (Pernyataan & Alasan Benar, TIDAK Berhubungan)
    if (in_array($clean, [
        'b', '2',
        'bothcorrectnotconnected', 'bothtruenotconnected', 'bothcorrectunconnected',
        'keduanyabenartidakberhubungan', 'keduanyabenartetapitidakberhubungan',
        'benarbenartidakberhubungan'
    ], true)) {
        return 'both_correct_not_connected';
    }

    // Option C (Pernyataan BENAR dan Alasan SALAH)
    if (in_array($clean, [
        'c', '3',
        'statementtruereasonfalse',
        'statementcorrectreasonwrong',
        'statementtruereasonwrong',
        'statementcorrectreasonfalse',
        'pernyataanbenaralasansalah',
        'pernyataanbenardanalasansalah',
        'benarsalah'
    ], true)) {
        return 'statement_true_reason_false';
    }

    // Option D (Pernyataan SALAH dan Alasan BENAR)
    if (in_array($clean, [
        'd', '4',
        'statementfalsereasontrue',
        'statementwrongreasoncorrect',
        'statementwrongreasontrue',
        'statementfalsereasoncorrect',
        'pernyataansalahalasanbenar',
        'pernyataansalahdanalasanbenar',
        'salahbenar'
    ], true)) {
        return 'statement_false_reason_true';
    }

    // Option E (Pernyataan dan Alasan keduanya SALAH)
    if (in_array($clean, [
        'e', '5',
        'bothfalse',
        'bothwrong',
        'keduanyasalah',
        'salahsalah',
        'pernyataandanalasankeduanyasalah'
    ], true)) {
        return 'both_false';
    }

    return $v;
}

function findQuestionByStudentId($quiz, $studentId) {
    $idx = (int)$studentId;
    return ($idx >= 0 && isset($quiz['questions'][$idx])) ? $quiz['questions'][$idx] : null;
}

function answerText($q, $answer, $correct = false) {
    if ($q['type'] === 'mc' || $q['type'] === 'case_analysis') {
        if ($answer === null || $answer === '') return 'Tidak dijawab';
        $i = (int)$answer;
        return isset($q['options'][$i]) ? chr(65 + $i) . '. ' . $q['options'][$i] : 'Tidak dijawab';
    }
    if ($q['type'] === 'complex') {
        $vals = is_array($answer) ? $answer : [];
        if (!$vals) return 'Tidak dijawab';
        return implode('<br>', array_map(function($i) use ($q) {
            return isset($q['options'][$i]) ? chr(65 + $i) . '. ' . $q['options'][$i] : '';
        }, $vals));
    }
    if ($q['type'] === 'matching') {
        $premises = $q['premises'] ?? [];
        $targets = $q['targets'] ?? [];
        $pairs = is_array($answer) ? $answer : [];
        if (!$pairs) return 'Tidak dijawab';
        $out = [];
        foreach ($pairs as $pIdx => $tIdx) {
            $pText = $premises[(int)$pIdx] ?? ('Premis ' . ((int)$pIdx + 1));
            $tText = $targets[(int)$tIdx] ?? ('Pasangan ' . ((int)$tIdx + 1));
            $out[] = "<b>$pText</b> ➔ $tText";
        }
        return $out ? implode('<br>', $out) : 'Tidak dijawab';
    }
    if ($q['type'] === 'short_answer') {
        return ($answer !== null && $answer !== '') ? htmlspecialchars((string)$answer) : 'Tidak dijawab';
    }
    if ($q['type'] === 'essay') {
        return ($answer !== null && $answer !== '') ? nl2br(htmlspecialchars((string)$answer)) : 'Tidak dijawab';
    }
    if ($q['type'] === 'cause_effect') {
        $canonical = normalizeCauseEffectKey($answer);
        $labels = [
            'both_correct_connected' => 'Pernyataan & alasan benar, berhubungan sebab akibat (A)',
            'both_correct_not_connected' => 'Pernyataan & alasan benar, TIDAK berhubungan (B)',
            'statement_true_reason_false' => 'Pernyataan benar, alasan salah (C)',
            'statement_false_reason_true' => 'Pernyataan salah, alasan benar (D)',
            'both_false' => 'Keduanya salah (E)'
        ];
        return $labels[$canonical] ?? (($answer !== null && $answer !== '') ? htmlspecialchars((string)$answer) : 'Tidak dijawab');
    }
    if ($q['type'] === 'scaled') {
        $opt = isset($q['options'][(int)$answer]) ? $q['options'][(int)$answer] : null;
        return $opt ? ($opt['text'] . ' (Poin: ' . $opt['score'] . ')') : 'Tidak dijawab';
    }
    return ($answer !== null && $answer !== '') ? htmlspecialchars((string)$answer) : 'Tidak dijawab';
}

function calculateSubmission($quiz, $questionOrder, $answers) {
    $correctCount = 0;
    $incorrectCount = 0;
    $totalMaxScore = 0;
    $totalObtainedScore = 0;
    $breakdowns = [];

    foreach ($questionOrder as $studentPos => $studentId) {
        $q = findQuestionByStudentId($quiz, $studentId);
        if (!$q) continue;
        $studentAns = array_key_exists($studentPos, $answers) ? $answers[$studentPos] : null;
        $isCorrect = false;

        if ($q['type'] === 'mc' || $q['type'] === 'case_analysis') {
            $isCorrect = false;
            if ($studentAns !== null && $studentAns !== '') {
                $rawAns = $q['answer'] ?? 0;
                $sIdx = is_numeric($studentAns) ? (int)$studentAns : (ord(strtoupper(trim((string)$studentAns))[0]) - 65);
                if (is_numeric($rawAns)) {
                    $eIdx = (int)$rawAns;
                } else {
                    $eIdx = ord(strtoupper(trim((string)$rawAns))[0]) - 65;
                }
                $isCorrect = ($sIdx === $eIdx);
            }
        } elseif ($q['type'] === 'complex') {
            $expected = array_map(function($v) {
                return is_numeric($v) ? (int)$v : (ord(strtoupper(trim((string)$v))[0]) - 65);
            }, $q['answers'] ?? []);
            $given = is_array($studentAns) ? array_map(function($v) {
                return is_numeric($v) ? (int)$v : (ord(strtoupper(trim((string)$v))[0]) - 65);
            }, $studentAns) : [];
            sort($expected); sort($given);
            $isCorrect = !empty($expected) && $expected === $given;
        } elseif ($q['type'] === 'matching') {
            $expected = is_array($q['pairs'] ?? null) ? $q['pairs'] : (is_array($q['answer'] ?? null) ? $q['answer'] : []);
            $given = is_array($studentAns) ? $studentAns : [];
            $matchCount = 0;
            $totalPairs = count($expected);
            foreach ($expected as $pIdx => $tIdx) {
                if (isset($given[$pIdx]) && (int)$given[$pIdx] === (int)$tIdx) {
                    $matchCount++;
                }
            }
            $isCorrect = ($totalPairs > 0 && $matchCount === $totalPairs);
        } elseif ($q['type'] === 'short_answer') {
            $expRaw = trim((string)($q['answer'] ?? ''));
            $variants = array_map(fn($v) => strtolower(trim($v)), explode('|', $expRaw));
            $given = strtolower(trim((string)$studentAns));
            $isCorrect = ($given !== '' && in_array($given, $variants));
        } elseif ($q['type'] === 'essay') {
            $given = trim((string)$studentAns);
            $isCorrect = ($given !== '');
        } elseif ($q['type'] === 'cause_effect') {
            $sNorm = normalizeCauseEffectKey($studentAns);
            $kNorm = normalizeCauseEffectKey($q['answer'] ?? '');
            $isCorrect = ($studentAns !== null && $studentAns !== '' && $sNorm !== '' && $sNorm === $kNorm);
        } elseif ($q['type'] === 'scaled') {
            $selected = isset($q['options'][(int)$studentAns]) ? $q['options'][(int)$studentAns] : null;
            $score = $selected ? (int)$selected['score'] : 0;
            $max = 0;
            foreach (($q['options'] ?? []) as $o) $max = max($max, (int)($o['score'] ?? 0));
            $totalObtainedScore += $score;
            $totalMaxScore += $max;
            $isCorrect = $score >= ($max / 2);
        }

        if ($isCorrect) $correctCount++; else $incorrectCount++;
        $correctAnswer = $q['type'] === 'complex' ? ($q['answers'] ?? []) : ($q['type'] === 'matching' ? ($q['pairs'] ?? $q['answer'] ?? []) : ($q['answer'] ?? null));
        $breakdowns[] = [
            'question' => $q,
            'studentAnswer' => $studentAns,
            'isCorrect' => $isCorrect,
            'studentAnswerText' => answerText($q, $studentAns),
            'correctAnswerText' => $q['type'] === 'scaled' ? 'Soal Evaluasi Skala' : answerText($q, $correctAnswer, true)
        ];
    }

    $totalQuestions = count($questionOrder);
    $hasScaled = false;
    foreach ($quiz['questions'] as $q) if (($q['type'] ?? '') === 'scaled') { $hasScaled = true; break; }
    $score = $hasScaled ? ($totalMaxScore > 0 ? (int)round(($totalObtainedScore / $totalMaxScore) * 100) : 0)
                        : ($totalQuestions > 0 ? (int)round(($correctCount / $totalQuestions) * 100) : 0);
    return [$score, $correctCount, $incorrectCount, $breakdowns];
}

$action = $_GET['action'] ?? '';

if ($action === 'teacher_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $limit = $_SESSION['teacher_login_limit'] ?? ['count' => 0, 'until' => 0];
    if ($limit['until'] > time()) { http_response_code(429); echo json_encode(['status'=>'error','message'=>'Terlalu banyak percobaan login. Coba lagi beberapa menit lagi.']); exit; }
    $db = loadDb($file_db);
    $hash = $db['teacher_password_hash'] ?? null;
    if (!is_string($hash) || !preg_match('/^\$2[ayb]\$\d{2}\$[A-Za-z0-9.\/]{53}$/', $hash)) {
        $hash = $TEACHER_PASSWORD_HASH;
    }
    if (password_verify((string)($input['password'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['teacher_authenticated'] = true;
        $_SESSION['teacher_last_activity'] = time();
        $_SESSION['teacher_login_limit'] = ['count' => 0, 'until' => 0];
        audit($db, 'Login guru', $ip); saveDb($file_db, $db);
        echo json_encode(['status' => 'success', 'message' => 'Login guru berhasil.', 'must_change_password' => empty($db['teacher_password_changed'])]);
    } else {
        $count = (int)$limit['count'] + 1;
        $_SESSION['teacher_login_limit'] = ['count' => $count, 'until' => $count >= 5 ? time() + 300 : 0];
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Password guru salah.']);
    }
    exit;
}

if ($action === 'change_teacher_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $newPassword = (string)($input['new_password'] ?? '');
    if (strlen($newPassword) < 8) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Password baru minimal 8 karakter.']); exit; }
    $db = loadDb($file_db);
    $db['teacher_password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    $db['teacher_password_changed'] = true;
    audit($db, 'Perubahan password guru');
    saveDb($file_db, $db);
    echo json_encode(['status'=>'success','message'=>'Password guru berhasil diganti.']);
    exit;
}


if ($action === 'teacher_logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = loadDb($file_db); audit($db, 'Logout guru'); saveDb($file_db, $db);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    echo json_encode(['status' => 'success', 'message' => 'Sesi guru dikunci.']);
    exit;
}

if ($action === 'teacher_status' || $action === 'check_session') {
    $timeout = 1800; // 30 menit masa aktif sesi guru
    $isAuthenticated = !empty($_SESSION['teacher_authenticated']) 
        && !empty($_SESSION['teacher_last_activity']) 
        && (time() - (int)$_SESSION['teacher_last_activity'] <= $timeout);

    if ($isAuthenticated) {
        $_SESSION['teacher_last_activity'] = time();
        $db = loadDb($file_db);
        echo json_encode([
            'status' => 'success',
            'authenticated' => true,
            'must_change_password' => empty($db['teacher_password_changed'])
        ]);
    } else {
        $_SESSION['teacher_authenticated'] = false;
        echo json_encode([
            'status' => 'success',
            'authenticated' => false,
            'must_change_password' => false
        ]);
    }
    exit;
}

// Data untuk siswa: kunci jawaban, pembahasan, dan hasil ujian tidak pernah dikirim.
if ($action === 'get_student_data' || ($action === 'get_data' && empty($_SESSION['teacher_authenticated']))) {
    $db = loadDb($file_db);
    $studentQuizzes = [];
    foreach ($db['questions'] as $quiz) {
        if (quizAvailableNow($quiz) !== null) continue;
        $safeQuestions = [];
        foreach (($quiz['questions'] ?? []) as $idx => $q) {
            $safe = $q;
            unset($safe['answer'], $safe['answers'], $safe['explanation']);
            $safe['_student_id'] = (string)$idx;
            $safeQuestions[] = $safe;
        }
        unset($quiz['pin']);
        $quiz['questions'] = $safeQuestions;
        $studentQuizzes[] = $quiz;
    }
    $publicStudents = array_map(function($st) {
        return [
            'nis' => (string)($st['nis'] ?? ''),
            'name' => (string)($st['name'] ?? ''),
            'class' => (string)($st['class'] ?? ''),
            'tahun_ajaran' => (string)($st['tahun_ajaran'] ?? $st['academic_year'] ?? '')
        ];
    }, $db['students'] ?? []);
    $requirePassword = (bool)($db['settings']['require_student_password'] ?? true);
    echo json_encode([
        'questions' => $studentQuizzes,
        'results' => [],
        'students' => $publicStudents,
        'settings' => [
            'require_student_password' => $requirePassword
        ]
    ]);
    exit;
}

if ($action === 'get_public_settings') {
    $db = loadDb($file_db);
    echo json_encode([
        'status' => 'success',
        'require_student_password' => (bool)($db['settings']['require_student_password'] ?? true)
    ]);
    exit;
}

if ($action === 'save_system_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = loadDb($file_db);
    $db['settings'] = $db['settings'] ?? [];
    if (isset($input['require_student_password'])) {
        $db['settings']['require_student_password'] = (bool)$input['require_student_password'];
    }
    audit($db, 'Ubah pengaturan ujian', 'Sistem', 'Wajib Password = ' . ($db['settings']['require_student_password'] ? 'Ya' : 'Tidak'));
    saveDb($file_db, $db);
    echo json_encode([
        'status' => 'success',
        'message' => 'Pengaturan sistem berhasil disimpan.',
        'settings' => $db['settings']
    ]);
    exit;
}

// Data lengkap hanya untuk guru yang sudah login.
if ($action === 'get_data') {
    teacherOnly();
    echo json_encode(loadDb($file_db));
    exit;
}

// Periksa status pengerjaan sebelum siswa diperbolehkan masuk ke soal.
if ($action === 'check_attempt') {
    $db = loadDb($file_db);
    $nis = trim((string)($_GET['nis'] ?? ''));
    $quizId = trim((string)($_GET['quiz_id'] ?? ''));
    if ($nis === '' || $quizId === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'NIS atau paket kuis tidak valid.']);
        exit;
    }

    $alreadyAttempted = false;
    foreach ($db['results'] as $r) {
        if ((string)($r['nis'] ?? '') === $nis && (string)($r['code'] ?? '') === $quizId) {
            $alreadyAttempted = true;
            break;
        }
    }

    $permissionKey = $nis . '_' . $quizId;
    $permission = $db['retake_permissions'][$permissionKey] ?? null;
    $retakeAllowed = is_array($permission) ? ((int)($permission['remaining'] ?? 0) > 0 && (empty($permission['expires_at']) || strtotime($permission['expires_at']) >= time())) : !empty($permission);

    echo json_encode([
        'status' => 'success',
        'already_attempted' => $alreadyAttempted,
        'retake_allowed' => $retakeAllowed
    ]);
    exit;
}

// Autentikasi siswa login dengan NIS dan Password
if ($action === 'student_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $nis = trim((string)($input['nis'] ?? ''));
    $password = strtoupper(trim((string)($input['password'] ?? '')));

    $db = loadDb($file_db);
    $requirePassword = (bool)($db['settings']['require_student_password'] ?? true);

    if ($nis === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Nomor Induk Siswa (NIS) wajib diisi.']);
        exit;
    }

    if ($requirePassword && $password === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Password login siswa wajib diisi.']);
        exit;
    }

    $matched = null;
    foreach ($db['students'] as $st) {
        if (trim((string)($st['nis'] ?? '')) === $nis) {
            $matched = $st;
            break;
        }
    }

    if (!$matched) {
        // Jika password dinonaktifkan dan ada identitas nama & kelas pada payload
        if (!$requirePassword && !empty($input['name']) && !empty($input['class'])) {
            $matched = [
                'nis' => $nis,
                'name' => trim((string)$input['name']),
                'class' => trim((string)$input['class']),
                'tahun_ajaran' => trim((string)($input['tahun_ajaran'] ?? ''))
            ];
        } else {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => "NIS '{$nis}' tidak ditemukan dalam database siswa. Pastikan NIS benar atau hubungi guru."]);
            exit;
        }
    }

    if ($requirePassword) {
        $stPassword = strtoupper(trim((string)($matched['password'] ?? '')));
        if ($stPassword === '' || !hash_equals($stPassword, $password)) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Password login salah! Silakan tanyakan password login Anda kepada guru pengawas.']);
            exit;
        }
    }

    // Catat sesi login siswa ke sistem monitoring & audit log
    $quizId = trim((string)($input['quiz_id'] ?? ''));
    $quizTitle = '';
    if ($quizId !== '') {
        foreach ($db['questions'] as $q) {
            if ((string)($q['id'] ?? '') === $quizId) {
                $quizTitle = (string)($q['title'] ?? $quizId);
                break;
            }
        }
    }

    // Periksa apakah siswa memiliki sesi ujian aktif yang belum selesai
    $activeAttempt = null;
    $activeAttemptKey = null;
    $db['active_attempts'] = $db['active_attempts'] ?? [];
    foreach ($db['active_attempts'] as $ak => $cand) {
        if ((string)($cand['nis'] ?? '') === $nis && empty($cand['finished'])) {
            if ((int)($cand['ends_at'] ?? 0) > time()) {
                $activeAttempt = $cand;
                $activeAttemptKey = $ak;
                break;
            }
        }
    }

    $db['student_sessions'] = $db['student_sessions'] ?? [];
    $prev = $db['student_sessions'][$nis] ?? [];
    $isResetAllowed = !empty($prev['reset_allowed']) || !empty($activeAttempt['reset_allowed']);

    // Kasus 1: Siswa memiliki ujian aktif yang belum selesai
    if ($activeAttempt !== null) {
        $attemptQuizId = (string)($activeAttempt['quiz_id'] ?? $quizId);
        $attemptQuiz = null;
        foreach ($db['questions'] as $q) {
            if ((string)($q['id'] ?? '') === $attemptQuizId) {
                $attemptQuiz = $q;
                break;
            }
        }

        // Jika guru pengawas sudah klik Reset Sesi di Monitoring Ujian ATAU siswa me-refresh/relogin
        if ($isResetAllowed) {
            // Cek apakah paket kuis mewajibkan token ujian
            $quizPin = strtoupper(trim((string)($attemptQuiz['pin'] ?? '')));
            $inputPin = strtoupper(trim((string)($input['pin'] ?? '')));

            if ($quizPin !== '') {
                // Jika siswa belum memasukkan token saat login, minta masukkan token terlebih dahulu
                if ($inputPin === '') {
                    echo json_encode([
                        'status' => 'success',
                        'needs_resume_token' => true,
                        'message' => 'Sesi ujian Anda telah dibuka oleh Guru Pengawas. Silakan masukkan Token Ujian aktif terbaru dari pengawas untuk melanjutkan ujian.',
                        'student' => [
                            'nis' => (string)$matched['nis'],
                            'name' => (string)$matched['name'],
                            'class' => (string)$matched['class'],
                            'tahun_ajaran' => (string)($matched['tahun_ajaran'] ?? '')
                        ],
                        'quiz' => [
                            'id' => $attemptQuizId,
                            'title' => $attemptQuiz['title'] ?? 'Ujian Berjalan',
                            'subject' => $attemptQuiz['subject'] ?? 'Umum',
                            'class' => $attemptQuiz['class'] ?? ''
                        ]
                    ]);
                    exit;
                }

                // Jika siswa memasukkan token, verifikasi secara ketat (harus cocok dengan token aktif saat ini)
                if (!hash_equals($quizPin, $inputPin)) {
                    http_response_code(401);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'TOKEN ujian tidak valid atau sudah kedaluwarsa. Silakan minta dan masukkan token terbaru yang aktif dari guru pengawas.'
                    ]);
                    exit;
                }
            }

            // Bersihkan flag reset_allowed setelah token tervalidasi
            if (isset($db['student_sessions'][$nis])) {
                $db['student_sessions'][$nis]['reset_allowed'] = false;
                $db['student_sessions'][$nis]['last_seen'] = time();
                $db['student_sessions'][$nis]['status'] = 'WORKING';
                $db['student_sessions'][$nis]['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
            }
            if (isset($db['active_attempts'][$activeAttemptKey])) {
                $db['active_attempts'][$activeAttemptKey]['reset_allowed'] = false;
                $db['active_attempts'][$activeAttemptKey]['updated_at'] = time();
            }

            audit($db, 'Siswa resume ujian', "{$nis} - " . (string)$matched['name'], "Melanjutkan ujian setelah reset sesi & validasi token pengawas: " . ($attemptQuiz['title'] ?? $attemptQuizId));
            saveDb($file_db, $db);

            $tokenToSend = $activeAttempt['token'] ?? '';
            if ($tokenToSend === '') {
                // Generate token baru jika token asli hilang tapi hash ada
                $tokenToSend = bin2hex(random_bytes(32));
                $newHash = attemptKey($tokenToSend);
                $db['active_attempts'][$newHash] = $activeAttempt;
                $db['active_attempts'][$newHash]['token'] = $tokenToSend;
                $db['active_attempts'][$newHash]['token_hash'] = $newHash;
                if ($newHash !== $activeAttemptKey) {
                    unset($db['active_attempts'][$activeAttemptKey]);
                }
                saveDb($file_db, $db);
            }

            echo json_encode([
                'status' => 'success',
                'resume_attempt' => true,
                'message' => 'Sesi ujian Anda dipulihkan oleh Guru Pengawas. Seluruh progres dan jawaban tersimpan aman di server.',
                'student' => [
                    'nis' => (string)$matched['nis'],
                    'name' => (string)$matched['name'],
                    'class' => (string)$matched['class'],
                    'tahun_ajaran' => (string)($matched['tahun_ajaran'] ?? '')
                ],
                'attempt' => [
                    'attempt_token' => $tokenToSend,
                    'quiz' => $attemptQuiz ? publicAttemptQuiz($attemptQuiz, $activeAttempt) : null,
                    'answers' => $activeAttempt['answers'] ?? [],
                    'server_now' => time(),
                    'ends_at' => $activeAttempt['ends_at']
                ]
            ]);
            exit;
        }

        // Jika sesi masih aktif di perangkat lain dan belum di-reset oleh guru pengawas
        // dan jika waktu aktif terakhir kurang dari 60 detik atau beda IP
        $lastSeen = (int)($prev['last_seen'] ?? ($activeAttempt['updated_at'] ?? 0));
        $prevIp = (string)($prev['ip'] ?? '');
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';

        if (($prevIp !== '' && $prevIp !== $currentIp) || (time() - $lastSeen < 60)) {
            http_response_code(403);
            echo json_encode([
                'status' => 'locked',
                'message' => "Sesi ujian Anda masih aktif atau terkunci di perangkat ujian lab. Jika komputer Anda mati, restart, atau kabel listrik tersenggol, silakan lapor kepada Guru Pengawas untuk melakukan 'Reset Sesi' di halaman Monitoring Ujian agar Anda dapat lanjut ujian tanpa kehilangan jawaban."
            ]);
            exit;
        }
    }

    $db['student_sessions'][$nis] = [
        'nis' => (string)$matched['nis'],
        'name' => (string)$matched['name'],
        'class' => (string)$matched['class'],
        'quiz_id' => $quizId ?: ($prev['quiz_id'] ?? ''),
        'quiz_title' => $quizTitle ?: ($prev['quiz_title'] ?? ''),
        'status' => (!empty($prev['status']) && $prev['status'] === 'WORKING') ? 'WORKING' : 'STANDBY',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'login_at' => $prev['login_at'] ?? time(),
        'last_seen' => time(),
        'attempt_token_hash' => $prev['attempt_token_hash'] ?? ''
    ];
    audit($db, 'Login siswa', (string)$matched['nis'] . ' - ' . (string)$matched['name'], 'Kelas ' . (string)$matched['class'] . ($quizTitle ? ' (' . $quizTitle . ')' : ''));
    saveDb($file_db, $db);

    echo json_encode([
        'status' => 'success',
        'message' => 'Login berhasil.',
        'student' => [
            'nis' => (string)$matched['nis'],
            'name' => (string)$matched['name'],
            'class' => (string)$matched['class'],
            'tahun_ajaran' => (string)($matched['tahun_ajaran'] ?? '')
        ]
    ]);
    exit;
}

// Membuat sesi ujian di server: urutan soal/opsi, waktu, dan identitas tidak lagi
// berasal dari browser ketika jawaban dikumpulkan.
if ($action === 'start_attempt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $quizId = trim((string)($input['quiz_id'] ?? '')); $nis = trim((string)($input['nis'] ?? ''));
    $name = trim((string)($input['name'] ?? '')); $class = trim((string)($input['class'] ?? ''));
    if ($quizId === '' || $nis === '' || $name === '' || $class === '') { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Identitas dan paket kuis wajib diisi.']); exit; }
    $db = loadDb($file_db); $quiz = null;
    foreach ($db['questions'] as $candidate) if ((string)($candidate['id'] ?? '') === $quizId) { $quiz = $candidate; break; }
    if (!$quiz || empty($quiz['questions'])) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Kuis tidak tersedia.']); exit; }
    $quizPin = strtoupper(trim((string)($quiz['pin'] ?? '')));
    $inputPin = strtoupper(trim((string)($input['pin'] ?? '')));
    if ($quizPin !== '') {
        if ($inputPin === '' || !hash_equals($quizPin, $inputPin)) {
            http_response_code(401);
            echo json_encode(['status'=>'error','message'=>'TOKEN ujian tidak valid atau sudah kedaluwarsa. Silakan masukkan token terbaru yang aktif dari guru pengawas.']);
            exit;
        }
    }
    // Validasi password siswa jika siswa terdaftar dan pengaturan mewajibkan password
    $requirePassword = (bool)($db['settings']['require_student_password'] ?? true);
    if ($requirePassword) {
        $inputPassword = strtoupper(trim((string)($input['password'] ?? '')));
        foreach ($db['students'] as $st) {
            if (trim((string)($st['nis'] ?? '')) === $nis) {
                $stPassword = strtoupper(trim((string)($st['password'] ?? '')));
                if ($stPassword !== '' && ($inputPassword === '' || !hash_equals($stPassword, $inputPassword))) {
                    http_response_code(401);
                    echo json_encode(['status'=>'error','message'=>'Autentikasi siswa gagal: Password tidak valid.']);
                    exit;
                }
                break;
            }
        }
    }
    // Cek jika siswa sudah memiliki sesi aktif yang sedang berjalan untuk kuis ini
    foreach ($db['active_attempts'] as $existingKey => $existingAttempt) {
        if ((string)($existingAttempt['nis'] ?? '') === $nis && (string)($existingAttempt['quiz_id'] ?? '') === $quizId && empty($existingAttempt['finished']) && (int)($existingAttempt['ends_at'] ?? 0) > time()) {
            $tokenToSend = $existingAttempt['token'] ?? '';
            if ($tokenToSend === '') {
                $tokenToSend = bin2hex(random_bytes(32));
                $newHash = attemptKey($tokenToSend);
                $db['active_attempts'][$newHash] = $existingAttempt;
                $db['active_attempts'][$newHash]['token'] = $tokenToSend;
                $db['active_attempts'][$newHash]['token_hash'] = $newHash;
                if ($newHash !== $existingKey) unset($db['active_attempts'][$existingKey]);
                saveDb($file_db, $db);
            }
            echo json_encode([
                'status' => 'success',
                'resumed' => true,
                'attempt_token' => $tokenToSend,
                'quiz' => publicAttemptQuiz($quiz, $existingAttempt),
                'server_now' => time(),
                'ends_at' => $existingAttempt['ends_at'],
                'answers' => $existingAttempt['answers'] ?? []
            ]);
            exit;
        }
    }

    $permissionKey = $nis . '_' . $quizId; $permission = $db['retake_permissions'][$permissionKey] ?? null; $retakeAllowed = is_array($permission) ? ((int)($permission['remaining'] ?? 0) > 0 && (empty($permission['expires_at']) || strtotime($permission['expires_at']) >= time())) : !empty($permission); foreach ($db['results'] as $r) if ((string)($r['nis'] ?? '') === $nis && (string)($r['code'] ?? '') === $quizId && !$retakeAllowed) { http_response_code(409); echo json_encode(['status'=>'error','message'=>'Ujian ini sudah pernah dikerjakan.']); exit; }
    $totalQuestions = count($quiz['questions']);
    $maxQuestions = isset($quiz['max_questions']) ? (int)$quiz['max_questions'] : 0;
    if ($maxQuestions <= 0 && isset($quiz['question_count'])) {
        $maxQuestions = (int)$quiz['question_count'];
    }

    $order = shuffledIndices($totalQuestions);
    if ($maxQuestions > 0 && $maxQuestions < $totalQuestions) {
        $order = array_slice($order, 0, $maxQuestions);
    }

    $token = bin2hex(random_bytes(32));
    $optionOrder = [];
    foreach ($order as $qid) {
        if (is_array($quiz['questions'][$qid]['options'] ?? null)) {
            $optionOrder[$qid] = shuffledIndices(count($quiz['questions'][$qid]['options']));
        }
    }
    $attempt = ['token'=>$token, 'token_hash'=>attemptKey($token), 'quiz_id'=>$quizId, 'quiz_title'=>$quiz['title'] ?? $quizId, 'nis'=>$nis, 'name'=>$name, 'class'=>$class, 'question_order'=>$order, 'option_order'=>$optionOrder, 'answers'=>[], 'started_at'=>time(), 'ends_at'=>time() + max(1, (int)($quiz['duration'] ?? 1)) * 60, 'updated_at'=>time()];
    $db['active_attempts'][attemptKey($token)] = $attempt;
    $db['student_sessions'] = $db['student_sessions'] ?? [];
    $prev = $db['student_sessions'][$nis] ?? [];
    $db['student_sessions'][$nis] = array_merge($prev, [
        'nis' => $nis,
        'name' => $name,
        'class' => $class,
        'quiz_id' => $quizId,
        'quiz_title' => $quiz['title'] ?? $quizId,
        'status' => 'WORKING',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'login_at' => $prev['login_at'] ?? time(),
        'started_at' => time(),
        'ends_at' => $attempt['ends_at'],
        'last_seen' => time(),
        'attempt_token_hash' => attemptKey($token)
    ]);
    audit($db, 'Mulai ujian', "{$nis} - {$name}", 'Paket: ' . ($quiz['title'] ?? $quizId));
    saveDb($file_db, $db);
    echo json_encode([
        'status' => 'success',
        'attempt_token' => $token,
        'nis' => $nis,
        'name' => $name,
        'class' => $class,
        'quiz' => publicAttemptQuiz($quiz, $attempt),
        'server_now' => time(),
        'ends_at' => $attempt['ends_at'],
        'answers' => []
    ]); exit;
}

if ($action === 'get_attempt') {
    $token = (string)($_GET['token'] ?? ''); $db = loadDb($file_db); $attempt = studentAttempt($db, $token);
    if (!$attempt) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Sesi ujian tidak ditemukan.']); exit; }
    if (!empty($attempt['reset_allowed'])) {
        http_response_code(403);
        echo json_encode([
            'status' => 'needs_login',
            'message' => 'Sesi ujian Anda telah direset oleh Pengawas. Silakan login kembali dan masukkan token aktif terbaru.'
        ]);
        exit;
    }
    $quiz = null; foreach ($db['questions'] as $candidate) if ((string)($candidate['id'] ?? '') === (string)$attempt['quiz_id']) { $quiz=$candidate; break; }
    if (!$quiz) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Kuis tidak ditemukan.']); exit; }
    echo json_encode([
        'status' => 'success',
        'attempt_token' => $token,
        'nis' => (string)($attempt['nis'] ?? ''),
        'name' => (string)($attempt['name'] ?? ''),
        'class' => (string)($attempt['class'] ?? ''),
        'quiz' => publicAttemptQuiz($quiz, $attempt),
        'answers' => $attempt['answers'],
        'server_now' => time(),
        'ends_at' => $attempt['ends_at'],
        'finished' => !empty($attempt['finished']),
        'violations_count' => (int)($attempt['violations_count'] ?? 0)
    ]); exit;
}

if ($action === 'student_heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $nis = trim((string)($input['nis'] ?? ''));
    $attemptToken = (string)($input['attempt_token'] ?? '');
    $quizId = trim((string)($input['quiz_id'] ?? ''));
    $db = loadDb($file_db);
    $dirty = false;
    if ($nis !== '') {
        $db['student_sessions'] = $db['student_sessions'] ?? [];
        if (isset($db['student_sessions'][$nis])) {
            $db['student_sessions'][$nis]['last_seen'] = time();
            if ($quizId !== '' && empty($db['student_sessions'][$nis]['quiz_id'])) {
                $db['student_sessions'][$nis]['quiz_id'] = $quizId;
            }
            $dirty = true;
        }
    }
    if ($attemptToken !== '') {
        $key = attemptKey($attemptToken);
        if (isset($db['active_attempts'][$key])) {
            $db['active_attempts'][$key]['updated_at'] = time();
            $dirty = true;
        }
    }
    if ($dirty) saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'server_now' => time()]);
    exit;
}

if ($action === 'save_attempt_answers' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input=json_decode(file_get_contents('php://input'),true)?:[]; $token=(string)($input['attempt_token']??''); $db=loadDb($file_db); $key=attemptKey($token); $attempt=$db['active_attempts'][$key]??null;
    if (!$attempt || !empty($attempt['finished'])) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Sesi ujian tidak aktif.']); exit; }
    if (time() > (int)$attempt['ends_at']) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'Waktu ujian telah berakhir.']); exit; }
    $answers=normalizeAnswers($input['answers']??[]); $allowed=count($attempt['question_order']); foreach($answers as $position=>$answer) if($position < 0 || $position >= $allowed) unset($answers[$position]);
    $db['active_attempts'][$key]['answers']=$answers; $db['active_attempts'][$key]['updated_at']=time();
    $nis = (string)($attempt['nis'] ?? '');
    if ($nis !== '' && isset($db['student_sessions'][$nis])) {
        $db['student_sessions'][$nis]['last_seen'] = time();
        $db['student_sessions'][$nis]['status'] = 'WORKING';
    }
    saveDb($file_db,$db);
    echo json_encode(['status'=>'success','message'=>'Jawaban tersimpan.','server_now'=>time()]); exit;
}

if ($action === 'report_student_violation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $token = (string)($input['attempt_token'] ?? '');
    $nisInput = trim((string)($input['nis'] ?? ''));
    $type = (string)($input['type'] ?? 'tab_switch');
    $details = (string)($input['details'] ?? 'Pindah tab / aplikasi');
    $db = loadDb($file_db);
    $key = attemptKey($token);
    $attempt = $db['active_attempts'][$key] ?? null;

    if (!$attempt && $nisInput !== '') {
        foreach ($db['active_attempts'] as $aKey => $a) {
            if ((string)($a['nis'] ?? '') === $nisInput && empty($a['finished'])) {
                $key = $aKey;
                $attempt = $a;
                break;
            }
        }
    }

    $count = 0;
    $vLogItem = [
        'time' => date('d/m/Y H:i:s'),
        'timestamp' => time(),
        'type' => $type,
        'details' => $details,
        'count' => 0
    ];

    if ($attempt) {
        $count = (int)($db['active_attempts'][$key]['violations_count'] ?? 0) + 1;
        $vLogItem['count'] = $count;
        $db['active_attempts'][$key]['violations_count'] = $count;
        $db['active_attempts'][$key]['last_violation_at'] = time();
        $db['active_attempts'][$key]['last_violation_type'] = $type;
        if (!isset($db['active_attempts'][$key]['violations_log'])) $db['active_attempts'][$key]['violations_log'] = [];
        $db['active_attempts'][$key]['violations_log'][] = $vLogItem;
        if (count($db['active_attempts'][$key]['violations_log']) > 200) {
            $db['active_attempts'][$key]['violations_log'] = array_slice($db['active_attempts'][$key]['violations_log'], -200);
        }

        $nis = (string)($attempt['nis'] ?? $nisInput);
        if ($nis !== '' && isset($db['student_sessions'][$nis])) {
            $db['student_sessions'][$nis]['violations_count'] = $count;
            $db['student_sessions'][$nis]['last_violation_at'] = time();
            $db['student_sessions'][$nis]['last_violation_type'] = $type;
            if (!isset($db['student_sessions'][$nis]['violations_log'])) $db['student_sessions'][$nis]['violations_log'] = [];
            $db['student_sessions'][$nis]['violations_log'][] = $vLogItem;
            if (count($db['student_sessions'][$nis]['violations_log']) > 200) {
                $db['student_sessions'][$nis]['violations_log'] = array_slice($db['student_sessions'][$nis]['violations_log'], -200);
            }
        }
        $name = (string)($attempt['name'] ?? ($db['student_sessions'][$nis]['name'] ?? $nis));
        audit($db, 'Peringatan Kecurangan', "Siswa {$name} (NIS: {$nis}) terdeteksi: {$details} (ke-{$count})");
        saveDb($file_db, $db);
    } elseif ($nisInput !== '' && isset($db['student_sessions'][$nisInput])) {
        $count = (int)($db['student_sessions'][$nisInput]['violations_count'] ?? 0) + 1;
        $vLogItem['count'] = $count;
        $db['student_sessions'][$nisInput]['violations_count'] = $count;
        $db['student_sessions'][$nisInput]['last_violation_at'] = time();
        $db['student_sessions'][$nisInput]['last_violation_type'] = $type;
        if (!isset($db['student_sessions'][$nisInput]['violations_log'])) $db['student_sessions'][$nisInput]['violations_log'] = [];
        $db['student_sessions'][$nisInput]['violations_log'][] = $vLogItem;
        if (count($db['student_sessions'][$nisInput]['violations_log']) > 200) {
            $db['student_sessions'][$nisInput]['violations_log'] = array_slice($db['student_sessions'][$nisInput]['violations_log'], -200);
        }
        $name = (string)($db['student_sessions'][$nisInput]['name'] ?? $nisInput);
        audit($db, 'Peringatan Kecurangan', "Siswa {$name} (NIS: {$nisInput}) terdeteksi: {$details} (ke-{$count})");
        saveDb($file_db, $db);
    }
    echo json_encode(['status' => 'success', 'violations_count' => $count]);
    exit;
}

if ($action === 'get_student_violations') {
    teacherOnly();
    $nis = trim((string)($_GET['nis'] ?? ''));
    if ($nis === '') { http_response_code(400); echo json_encode(['status'=>'error','message'=>'NIS tidak valid']); exit; }
    $db = loadDb($file_db);
    
    $name = $nis;
    $class = '-';
    $quizTitle = '-';
    $violationsCount = 0;
    $violations = [];

    // 1. Dari student_sessions
    if (isset($db['student_sessions'][$nis])) {
        $st = $db['student_sessions'][$nis];
        $name = (string)($st['name'] ?? $name);
        $class = (string)($st['class'] ?? $class);
        $quizTitle = (string)($st['quiz_title'] ?? $quizTitle);
        $violationsCount = (int)($st['violations_count'] ?? 0);
        if (!empty($st['violations_log']) && is_array($st['violations_log'])) {
            $violations = $st['violations_log'];
        }
    }

    // 2. Dari active_attempts
    foreach (($db['active_attempts'] ?? []) as $att) {
        if ((string)($att['nis'] ?? '') === $nis) {
            $name = (string)($att['name'] ?? $name);
            $class = (string)($att['class'] ?? $class);
            $quizTitle = (string)($att['quiz_title'] ?? $quizTitle);
            $violationsCount = max($violationsCount, (int)($att['violations_count'] ?? 0));
            if (!empty($att['violations_log']) && is_array($att['violations_log'])) {
                foreach ($att['violations_log'] as $vl) {
                    $violations[] = $vl;
                }
            }
        }
    }

    // 3. Fallback nama dari master data students
    if ($name === $nis) {
        foreach (($db['students'] ?? []) as $s) {
            if ((string)($s['nis'] ?? '') === $nis) {
                $name = (string)($s['name'] ?? $name);
                $class = (string)($s['class'] ?? $class);
                break;
            }
        }
    }

    // 4. Dari audit_logs (mencakup seluruh riwayat audit terperinci)
    $auditLogs = $db['audit_logs'] ?? [];
    $nisPattern = '(NIS: ' . $nis . ')';
    foreach ($auditLogs as $a) {
        if (($a['action'] ?? '') === 'Peringatan Kecurangan' && strpos((string)($a['target'] ?? ''), $nisPattern) !== false) {
            $target = (string)$a['target'];
            $details = $target;
            $count = null;
            if (preg_match('/terdeteksi:\s*(.+?)(?:\s*\(ke-(\d+)\))?$/i', $target, $m)) {
                $details = trim($m[1]);
                $count = isset($m[2]) ? (int)$m[2] : null;
            }
            $t = $a['time'] ?? '';
            $timeFormatted = $t ? date('d/m/Y H:i:s', strtotime($t)) : '-';
            $type = 'other';
            if (stripos($details, 'layar penuh') !== false || stripos($details, 'fullscreen') !== false) {
                $type = 'fullscreen_exit';
            } elseif (stripos($details, 'pindah tab') !== false || stripos($details, 'minimalkan') !== false) {
                $type = 'tab_switch';
            } elseif (stripos($details, 'fokus') !== false || stripos($details, 'aplikasi') !== false) {
                $type = 'window_blur';
            } elseif (stripos($details, 'pintasan') !== false || stripos($details, 'shortcut') !== false) {
                $type = 'blocked_shortcut';
            } elseif (stripos($details, 'klik kanan') !== false) {
                $type = 'contextmenu';
            }

            $violations[] = [
                'time' => $timeFormatted,
                'timestamp' => $t ? strtotime($t) : 0,
                'type' => $type,
                'details' => $details,
                'count' => $count
            ];
        }
    }

    // Deduplikasi catatan
    $unique = [];
    $seen = [];
    foreach ($violations as $v) {
        $sig = ($v['time'] ?? '') . '|' . ($v['details'] ?? '') . '|' . ($v['count'] ?? '');
        if (!isset($seen[$sig])) {
            $seen[$sig] = true;
            $unique[] = $v;
        }
    }

    // Urutkan waktu terbaru di atas
    usort($unique, function($a, $b) {
        $tA = (int)($a['timestamp'] ?? 0);
        $tB = (int)($b['timestamp'] ?? 0);
        if ($tA === $tB) {
            return ($b['count'] ?? 0) - ($a['count'] ?? 0);
        }
        return $tB - $tA;
    });

    if ($violationsCount <= 0) {
        $violationsCount = count($unique);
    }

    echo json_encode([
        'status' => 'success',
        'nis' => $nis,
        'name' => $name,
        'class' => $class,
        'quiz_title' => $quizTitle,
        'violations_count' => $violationsCount,
        'violations' => $unique
    ]);
    exit;
}

if ($action === 'reset_student_violations' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $nis = trim((string)($input['nis'] ?? ''));
    if ($nis === '') { http_response_code(400); echo json_encode(['status'=>'error','message'=>'NIS tidak valid']); exit; }
    $db = loadDb($file_db);
    if (isset($db['student_sessions'][$nis])) {
        $db['student_sessions'][$nis]['violations_count'] = 0;
        $db['student_sessions'][$nis]['violations_log'] = [];
    }
    foreach ($db['active_attempts'] as &$att) {
        if ((string)($att['nis'] ?? '') === $nis) {
            $att['violations_count'] = 0;
            $att['violations_log'] = [];
        }
    }
    unset($att);
    audit($db, 'Reset Pelanggaran', "NIS: {$nis}", "Jumlah pelanggaran direset ke 0 oleh guru");
    saveDb($file_db, $db);
    echo json_encode(['status'=>'success','message'=>'Riwayat pelanggaran siswa berhasil direset.']);
    exit;
}

if ($action === 'submit_result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = loadDb($file_db);
    $attemptToken = (string)($input['attempt_token'] ?? ''); $attemptKey = attemptKey($attemptToken); $attempt = $db['active_attempts'][$attemptKey] ?? null;
    if (!$attempt || !empty($attempt['finished'])) { http_response_code(409); echo json_encode(['status'=>'error','message'=>'Sesi ujian tidak aktif atau sudah selesai.']); exit; }
    $quizId = (string)$attempt['quiz_id'];
    $nis = (string)$attempt['nis'];
    $quiz = null;
    foreach ($db['questions'] as $candidate) if ((string)($candidate['id'] ?? '') === $quizId) { $quiz = $candidate; break; }
    if (!$quiz || $nis === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Data ujian tidak valid.']);
        exit;
    }

    $questionOrder = $attempt['question_order'];
    $answers = normalizeAnswers($attempt['answers'] ?? []);
    // Browser mengirim posisi opsi acak. Server memetakan kembali ke indeks asli
    // sebelum menghitung nilai, sehingga kunci tidak pernah dipercaya dari klien.
    foreach ($answers as $position => &$answer) { $qid = $questionOrder[(int)$position] ?? null; $map = $attempt['option_order'][$qid] ?? []; if (is_array($answer)) $answer = array_values(array_filter(array_map(fn($v) => $map[(int)$v] ?? null, $answer), fn($v) => $v !== null)); elseif (is_numeric($answer) && isset($map[(int)$answer])) $answer = $map[(int)$answer]; } unset($answer);
    $missing = []; foreach ($questionOrder as $pos => $_qid) { $v = $answers[$pos] ?? null; if ($v === null || $v === '' || (is_array($v) && !$v)) $missing[] = $pos + 1; }
    $isExpired = (time() >= (int)($attempt['ends_at'] ?? 0)) || !empty($input['force_submit']);
    if ($missing && !$isExpired) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Masih ada jawaban kosong.','empty_questions'=>$missing]); exit; }
    [$score, $correct, $wrong, $breakdowns] = calculateSubmission($quiz, $questionOrder, $answers);

    $token = bin2hex(random_bytes(32));
    $record = [
        'nis' => $nis,
        'name' => (string)$attempt['name'],
        'class' => (string)$attempt['class'],
        'code' => $quizId,
        'title' => (string)($quiz['title'] ?? ''),
        'score' => $score,
        'correct' => $correct,
        'wrong' => $wrong,
        'date' => date('d/m/Y H:i:s'),
        'review_token' => $token,
        'question_order' => $questionOrder,
        'answers' => $answers,
        'breakdowns' => $breakdowns
    ];

    // Maksimal 5 riwayat untuk NIS + paket yang sama.
    $same = array_values(array_filter($db['results'], function($r) use ($nis, $quizId) {
        return (string)($r['nis'] ?? '') === $nis && (string)($r['code'] ?? '') === $quizId;
    }));
    if (count($same) >= 5) {
        for ($i = count($db['results']) - 1; $i >= 0; $i--) {
            if ((string)($db['results'][$i]['nis'] ?? '') === $nis && (string)($db['results'][$i]['code'] ?? '') === $quizId) {
                array_splice($db['results'], $i, 1);
                break;
            }
        }
    }
    array_unshift($db['results'], $record);
    $db['active_attempts'][$attemptKey]['finished'] = true;
    $db['active_attempts'][$attemptKey]['result_token'] = $token;
    $db['active_attempts'][$attemptKey]['score'] = $score;
    $permissionKey = $nis . '_' . $quizId;
    if (isset($db['retake_permissions'][$permissionKey]) && is_array($db['retake_permissions'][$permissionKey])) { $db['retake_permissions'][$permissionKey]['remaining'] = max(0, (int)$db['retake_permissions'][$permissionKey]['remaining'] - 1); if (!$db['retake_permissions'][$permissionKey]['remaining']) unset($db['retake_permissions'][$permissionKey]); } else { unset($db['retake_permissions'][$permissionKey]); }
    
    // Update status monitoring siswa
    $db['student_sessions'] = $db['student_sessions'] ?? [];
    $prev = $db['student_sessions'][$nis] ?? [];
    $db['student_sessions'][$nis] = array_merge($prev, [
        'nis' => $nis,
        'name' => (string)($attempt['name'] ?? ($prev['name'] ?? $nis)),
        'class' => (string)($attempt['class'] ?? ($prev['class'] ?? '')),
        'quiz_id' => $quizId,
        'quiz_title' => (string)($quiz['title'] ?? $quizId),
        'status' => 'FINISHED',
        'finished_at' => time(),
        'score' => $score,
        'last_seen' => time()
    ]);
    audit($db, 'Selesai ujian', "{$nis} - " . (string)($attempt['name'] ?? ''), "Nilai: {$score} ({$correct} Benar, {$wrong} Salah) - " . ($quiz['title'] ?? $quizId));
    saveDb($file_db, $db);

    echo json_encode(['status' => 'success', 'message' => 'Jawaban berhasil terkirim ke server', 'score' => $score, 'correct' => $correct, 'wrong' => $wrong, 'review_token' => $token]);
    exit;
}

if ($action === 'get_review') {
    $token = (string)($_GET['token'] ?? '');
    if ($token === '') { http_response_code(400); echo json_encode(['allowed' => false]); exit; }
    $db = loadDb($file_db);
    foreach ($db['results'] as $r) {
        if (($r['review_token'] ?? '') === $token) {
            $quizCode = (string)($r['code'] ?? '');
            $allowed = !empty($db['review_settings'][$quizCode]) || !empty($db['review_settings']['all']);
            echo json_encode(['allowed' => (bool)$allowed, 'breakdowns' => $allowed ? ($r['breakdowns'] ?? []) : []]);
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['allowed' => false]);
    exit;
}

if ($action === 'set_review_state' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $quizId = (string)($input['quiz_id'] ?? '');
    $enabled = !empty($input['enabled']);
    $db = loadDb($file_db);
    if ($quizId === '') { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Paket kuis tidak valid.']); exit; }
    if ($quizId === 'all') {
        $db['review_settings']['all'] = $enabled;
        foreach (($db['questions'] ?? []) as $q) {
            if (!empty($q['id'])) {
                $db['review_settings'][$q['id']] = $enabled;
            }
        }
    } else {
        $db['review_settings'][$quizId] = $enabled;
    }
    saveDb($file_db, $db);
    audit($db, $enabled ? 'Buka Pembahasan Ujian' : 'Kunci Pembahasan Ujian', $quizId);
    echo json_encode([
        'status' => 'success',
        'enabled' => $enabled,
        'review_settings' => $db['review_settings'],
        'review_states' => $db['review_settings'],
        'message' => $enabled ? 'Jawaban & pembahasan dibuka untuk siswa.' : 'Jawaban & pembahasan disembunyikan dari siswa.'
    ]);
    exit;
}

if ($action === 'get_review_states') {
    teacherOnly();
    $db = loadDb($file_db);
    echo json_encode([
        'status' => 'success',
        'review_settings' => $db['review_settings'] ?? [],
        'review_states' => $db['review_settings'] ?? []
    ]);
    exit;
}

if ($action === 'allow_retake' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $nis = trim((string)($input['nis'] ?? ''));
    $quizId = trim((string)($input['quiz_id'] ?? ''));
    if ($nis === '' || $quizId === '') { http_response_code(400); echo json_encode(['status'=>'error','message'=>'NIS atau paket kuis tidak valid.']); exit; }
    $db = loadDb($file_db);
    $attempts = max(1, min(10, (int)($input['attempts'] ?? 1))); $until = trim((string)($input['valid_until'] ?? ''));
    if ($until !== '' && strtotime($until) === false) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Tanggal berlaku tidak valid.']); exit; }
    $db['retake_permissions'][$nis . '_' . $quizId] = ['remaining'=>$attempts, 'reason'=>trim((string)($input['reason'] ?? '')), 'expires_at'=>$until ?: null, 'granted_by'=>'guru', 'granted_at'=>date('c')];
    audit($db, 'Izin ujian ulang', $nis . ' / ' . $quizId, 'Kesempatan: ' . $attempts);
    saveDb($file_db, $db);
    echo json_encode(['status'=>'success','message'=>'Izin ujian ulang diberikan.']);
    exit;
}

if ($action === 'save_questions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Data soal tidak valid.']); exit; }

    $uploadsDir = __DIR__ . '/uploads/soal';

    foreach ($input as &$quiz) {
        $quiz['status'] = $quiz['status'] ?? 'ACTIVE';
        // Jika paket kuis belum memiliki soal sama sekali (masih baru dibuat / draf),
        // otomatis simpan sebagai DRAFT agar tidak memblokir pembuatan paket baru sebelum import/input soal
        if (empty($quiz['questions'])) {
            $quiz['status'] = 'DRAFT';
        }
        if ($quiz['status'] === 'ACTIVE' && ($errors = quizValidationErrors($quiz))) {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Kuis tidak dapat diaktifkan: ' . implode('; ', $errors)]);
            exit;
        }

        // Proses lampiran gambar pada tiap butir soal
        if (isset($quiz['questions']) && is_array($quiz['questions'])) {
            foreach ($quiz['questions'] as &$q) {
                // Deteksi permintaan hapus gambar
                if (isset($q['image_payload']) && $q['image_payload'] === 'DELETE') {
                    if (!empty($q['image'])) {
                        $oldPath = __DIR__ . '/' . ltrim((string)$q['image'], '/\\');
                        if (file_exists($oldPath) && is_file($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                    $q['image'] = '';
                    unset($q['image_payload']);
                }

                // Deteksi upload gambar baru via Base64
                if (!empty($q['image_payload']) && $q['image_payload'] !== 'DELETE') {
                    if (!is_dir($uploadsDir)) {
                        @mkdir($uploadsDir, 0755, true);
                    }

                    $payload = (string)$q['image_payload'];
                    $ext = 'jpg';
                    if (preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $payload, $matches)) {
                        $ext = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
                        $payload = substr($payload, strpos($payload, ',') + 1);
                    }

                    $decoded = base64_decode($payload, true);
                    if ($decoded !== false) {
                        // Hapus file gambar lama jika ada pergantian gambar
                        if (!empty($q['image'])) {
                            $oldPath = __DIR__ . '/' . ltrim((string)$q['image'], '/\\');
                            if (file_exists($oldPath) && is_file($oldPath)) {
                                @unlink($oldPath);
                            }
                        }

                        $safeQuizId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($quiz['id'] ?? 'quiz'));
                        $filename = 'img_soal_' . $safeQuizId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        $filepath = $uploadsDir . '/' . $filename;

                        if (file_put_contents($filepath, $decoded) !== false) {
                            $q['image'] = 'uploads/soal/' . $filename;
                        }
                    }
                    // Hapus properti Base64 panjang dari memori sebelum simpan ke DB JSON
                    unset($q['image_payload']);
                }
            }
            unset($q);
        }
    }
    unset($quiz);

    $db = loadDb($file_db); backupDb($file_db, $db, 'before-save-quizzes');
    
    $existingMap = [];
    foreach ($db['questions'] as $eq) {
        if (!empty($eq['id'])) $existingMap[$eq['id']] = $eq;
    }

    $now = time();
    foreach ($input as &$quiz) {
        $qid = $quiz['id'] ?? '';
        $existing = $existingMap[$qid] ?? null;

        if (!isset($quiz['pin_auto_refresh'])) {
            $quiz['pin_auto_refresh'] = $existing['pin_auto_refresh'] ?? true;
        }

        // Jika pin diganti manual oleh guru
        if ($existing && !empty($existing['pin']) && !empty($quiz['pin']) && $existing['pin'] !== $quiz['pin']) {
            $quiz['pin_updated_at'] = $now;
        } elseif ($existing && !empty($existing['pin_updated_at'])) {
            $quiz['pin_updated_at'] = $existing['pin_updated_at'];
        } else {
            $quiz['pin_updated_at'] = $now;
        }
        unset($quiz['prev_pin'], $quiz['prev_pin_expires_at']);
    }
    unset($quiz);

    $db['questions'] = $input;
    audit($db, 'Simpan paket kuis', '', count($input) . ' paket');
    saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'message' => 'Soal berhasil disimpan di server']);
    exit;
}

function parseQuestionBlocks($blocks) {
    $questions = [];
    foreach ($blocks as $b) {
        $lines = $b['lines'] ?? [];
        $image = $b['image'] ?? '';
        if (empty($lines)) continue;

        $qData = [
            'type' => 'mc',
            'question' => '',
            'options' => [],
            'answer' => 0,
            'answers' => [],
            'explanation' => '',
            'image' => $image ?: '',
            'premises' => [],
            'targets' => [],
            'pairs' => []
        ];

        $optMap = [];
        $matchingPairs = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (preg_match('/^TIPE\s*[:=]\s*(.+)$/i', $line, $m)) {
                $qData['type'] = strtolower(trim($m[1]));
            } elseif (preg_match('/^PERTANYAAN\s*[:=]\s*(.+)$/i', $line, $m)) {
                $qData['question'] = trim($m[1]);
            } elseif (preg_match('/^KASUS\s*[:=]\s*(.+)$/i', $line, $m)) {
                $qData['passage'] = trim($m[1]);
            } elseif (preg_match('/^PERNYATAAN\s*[:=]\s*(.+)$/i', $line, $m)) {
                $qData['statement'] = trim($m[1]);
            } elseif (preg_match('/^ALASAN\s*[:=]\s*(.+)$/i', $line, $m)) {
                $qData['reason'] = trim($m[1]);
            } elseif (preg_match('/^(PASANGAN|JODOHKAN)\s*[:=]\s*(.+)$/i', $line, $m)) {
                $rawP = trim($m[2]);
                if (strpos($rawP, '|') !== false) {
                    foreach (explode('|', $rawP) as $sp) {
                        if (trim($sp) !== '') $matchingPairs[] = trim($sp);
                    }
                } else {
                    $matchingPairs[] = $rawP;
                }
            } elseif (preg_match('/^(PASANGAN|JODOHKAN)\s*[:=]?$/i', $line)) {
                // Header penanda bagian menjodohkan
            } elseif ((strpos($line, ' - ') !== false || strpos($line, ' = ') !== false || strpos($line, '=') !== false) && !preg_match('/^(TIPE|PERTANYAAN|KASUS|PERNYATAAN|ALASAN|KUNCI|PEMBAHASAN)/i', $line)) {
                $matchingPairs[] = $line;
            } elseif (preg_match('/^([A-E])\s*[:\.]\s*(.+)$/i', $line, $m)) {
                $letter = strtoupper($m[1]);
                $optMap[$letter] = trim($m[2]);
            } elseif (preg_match('/^KUNCI(\s*JAWABAN)?\s*[:=]\s*(.+)$/i', $line, $m)) {
                $qData['key_raw'] = trim($m[2]);
            } elseif (preg_match('/^PEMBAHASAN\s*[:=]\s*(.+)$/i', $line, $m)) {
                $qData['explanation'] = trim($m[1]);
            } elseif (empty($qData['question']) && !preg_match('/^(TEMPLATE|PETUNJUK|SOAL\s+\d+)/i', $line)) {
                $qData['question'] = $line;
            }
        }

        $type = $qData['type'];
        if ($type === 'pilihan_ganda' || $type === 'pg') $type = 'mc';
        if ($type === 'pgk' || $type === 'ganda_kompleks' || $type === 'kompleks') $type = 'complex';
        if ($type === 'jodohkan' || $type === 'menjodohkan') $type = 'matching';
        if ($type === 'isian' || $type === 'isian_singkat' || $type === 'short') $type = 'short_answer';
        if ($type === 'esai' || $type === 'uraian' || $type === 'essay') $type = 'essay';
        $qData['type'] = $type;

        if ($type === 'mc') {
            $opts = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $let) {
                if (isset($optMap[$let])) $opts[] = $optMap[$let];
            }
            $qData['options'] = $opts ?: ['Opsi A', 'Opsi B', 'Opsi C', 'Opsi D'];
            $key = $qData['key_raw'] ?? '0';
            if (is_numeric($key)) {
                $qData['answer'] = (int)$key;
            } else {
                $letIdx = ord(strtoupper($key[0] ?? 'A')) - 65;
                $qData['answer'] = max(0, min(count($qData['options']) - 1, $letIdx));
            }
        } elseif ($type === 'complex') {
            $opts = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $let) {
                if (isset($optMap[$let])) $opts[] = $optMap[$let];
            }
            $qData['options'] = $opts ?: ['Opsi A', 'Opsi B', 'Opsi C', 'Opsi D'];
            $keyRaw = $qData['key_raw'] ?? '0';
            $parts = explode(',', $keyRaw);
            $ans = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if (is_numeric($p)) {
                    $ans[] = (int)$p;
                } else {
                    $letIdx = ord(strtoupper($p[0] ?? 'A')) - 65;
                    if ($letIdx >= 0 && $letIdx < count($qData['options'])) $ans[] = $letIdx;
                }
            }
            $qData['answers'] = array_values(array_unique($ans));
        } elseif ($type === 'matching') {
            $premises = [];
            $targets = [];
            $pairs = [];
            $idx = 0;
            $rawPairs = !empty($matchingPairs) ? $matchingPairs : $optMap;
            foreach ($rawPairs as $k => $val) {
                $prem = '';
                $targ = '';
                if (strpos($val, '=') !== false) {
                    $split = explode('=', $val, 2);
                    $prem = trim($split[0]);
                    $targ = trim($split[1]);
                } elseif (strpos($val, ' - ') !== false) {
                    $split = explode(' - ', $val, 2);
                    $prem = trim($split[0]);
                    $targ = trim($split[1]);
                } elseif (strpos($val, ' : ') !== false || strpos($val, ':') !== false) {
                    $split = explode(':', $val, 2);
                    $prem = trim($split[0]);
                    $targ = trim($split[1]);
                } else {
                    $prem = is_string($k) ? "Pernyataan $k" : "Premis " . ($idx + 1);
                    $targ = $val;
                }
                if ($prem && $targ) {
                    $premises[] = $prem;
                    $targets[] = $targ;
                    $pairs[(string)$idx] = $idx;
                    $idx++;
                }
            }
            if (!empty($qData['key_raw'])) {
                $customPairs = [];
                $customParts = explode(',', (string)$qData['key_raw']);
                foreach ($customParts as $cp) {
                    if (strpos($cp, '=') !== false) {
                        [$pPart, $tPart] = explode('=', trim($cp), 2);
                        $pPart = trim($pPart);
                        $tPart = trim($tPart);
                        $pIdx = is_numeric($pPart) ? (int)$pPart : (ord(strtoupper($pPart[0])) - 65);
                        $tIdx = is_numeric($tPart) ? (int)$tPart : (ord(strtoupper($tPart[0])) - 65);
                        $customPairs[(string)$pIdx] = $tIdx;
                    }
                }
                if (!empty($customPairs)) {
                    $pairs = $customPairs;
                }
            }
            $qData['premises'] = $premises;
            $qData['targets'] = $targets;
            $qData['pairs'] = $pairs;
            $qData['answer'] = $pairs;
        } elseif ($type === 'short_answer') {
            $qData['answer'] = trim($qData['key_raw'] ?? '');
        } elseif ($type === 'essay') {
            $qData['answer'] = trim($qData['key_raw'] ?? '');
        } elseif ($type === 'cause_effect') {
            $qData['statement'] = $qData['statement'] ?? '';
            $qData['reason'] = $qData['reason'] ?? '';
            $rawKey = trim((string)($qData['key_raw'] ?? 'both_correct_connected'));
            $qData['answer'] = normalizeCauseEffectKey($rawKey);
        } elseif ($type === 'case_analysis') {
            $opts = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $let) {
                if (isset($optMap[$let])) $opts[] = $optMap[$let];
            }
            $qData['options'] = $opts ?: ['Opsi A', 'Opsi B', 'Opsi C', 'Opsi D'];
            $key = trim((string)($qData['key_raw'] ?? '0'));
            if (preg_match('/^[A-E]$/i', $key)) {
                $qData['answer'] = ord(strtoupper($key)) - 65;
            } elseif (is_numeric($key)) {
                $intKey = (int)$key;
                if ($intKey >= 0 && $intKey < count($qData['options'])) {
                    $qData['answer'] = $intKey;
                } elseif ($intKey >= 1 && $intKey <= count($qData['options'])) {
                    $qData['answer'] = $intKey - 1;
                } else {
                    $qData['answer'] = 0;
                }
            } else {
                $qData['answer'] = 0;
            }
        }

        unset($qData['key_raw']);
        if (!empty($qData['question']) || !empty($qData['statement'])) {
            $questions[] = $qData;
        }
    }
    return $questions;
}

function parseDocxQuestions($filePath, $uploadsDir, $quizId = 'quiz') {
    if (!file_exists($filePath)) return ['error' => 'Berkas tidak ditemukan.'];
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return ['error' => 'Format berkas Word (.docx) tidak valid.'];

    $relsMap = [];
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    if ($relsXml) {
        $dom = new DOMDocument();
        @$dom->loadXML($relsXml);
        $rels = $dom->getElementsByTagName('Relationship');
        foreach ($rels as $r) {
            $id = $r->getAttribute('Id');
            $target = $r->getAttribute('Target');
            $type = $r->getAttribute('Type');
            if (strpos($type, 'image') !== false || preg_match('/\.(png|jpe?g|gif|webp)$/i', $target)) {
                $target = ltrim($target, '/');
                if (strpos($target, 'word/') !== 0 && strpos($target, 'media/') === 0) {
                    $target = 'word/' . $target;
                }
                $relsMap[$id] = $target;
            }
        }
    }

    $docXml = $zip->getFromName('word/document.xml');
    if (!$docXml) {
        $zip->close();
        return ['error' => 'document.xml tidak ditemukan di dalam berkas docx.'];
    }

    $dom = new DOMDocument();
    @$dom->loadXML($docXml);

    $paras = $dom->getElementsByTagName('p');
    $blocks = [];
    $currentLines = [];
    $currentImage = null;

    foreach ($paras as $p) {
        $tNodes = $p->getElementsByTagName('t');
        $paraText = '';
        foreach ($tNodes as $t) {
            $paraText .= $t->nodeValue;
        }
        $paraText = trim($paraText);

        $imgNodes = [];
        $blips = $p->getElementsByTagName('blip');
        foreach ($blips as $b) {
            $rId = $b->getAttribute('r:embed') ?: $b->getAttribute('embed');
            if ($rId && isset($relsMap[$rId])) $imgNodes[] = $relsMap[$rId];
        }
        $imagedatas = $p->getElementsByTagName('imagedata');
        foreach ($imagedatas as $im) {
            $rId = $im->getAttribute('r:id') ?: $im->getAttribute('id');
            if ($rId && isset($relsMap[$rId])) $imgNodes[] = $relsMap[$rId];
        }

        if (!empty($imgNodes)) {
            $firstImgPath = $imgNodes[0];
            $imgBinary = $zip->getFromName($firstImgPath);
            if ($imgBinary) {
                if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
                $ext = pathinfo($firstImgPath, PATHINFO_EXTENSION) ?: 'png';
                $safeName = 'img_soal_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $quizId) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $destPath = $uploadsDir . '/' . $safeName;
                if (file_put_contents($destPath, $imgBinary) !== false) {
                    $currentImage = 'uploads/soal/' . $safeName;
                }
            }
        }

        if (trim($paraText) === '---' || trim($paraText) === '===' || preg_match('/^Soal\s+(\d+)[:\.]?/i', $paraText)) {
            if (!empty($currentLines)) {
                $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
                $currentLines = [];
                $currentImage = null;
            }
            if (preg_match('/^Soal\s+(\d+)[:\.]?/i', $paraText)) {
                $currentLines[] = $paraText;
            }
        } else {
            if ($paraText !== '') {
                $currentLines[] = $paraText;
            }
        }
    }
    if (!empty($currentLines)) {
        $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
    }
    $zip->close();

    return parseQuestionBlocks($blocks);
}

function parseHtmlDocQuestions($filePath, $uploadsDir, $quizId = 'quiz') {
    if (!file_exists($filePath)) return ['error' => 'Berkas tidak ditemukan.'];
    $html = file_get_contents($filePath);
    if (!$html) return ['error' => 'Berkas kosong.'];

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    $xpath = new DOMXPath($dom);
    $elements = $xpath->query('//p | //div | //h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //li | //tr');

    $blocks = [];
    $currentLines = [];
    $currentImage = null;

    foreach ($elements as $el) {
        $imgs = $el->getElementsByTagName('img');
        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');
            if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $src, $m)) {
                $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
                $b64 = substr($src, strpos($src, ',') + 1);
                $bin = base64_decode($b64, true);
                if ($bin) {
                    if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
                    $name = 'img_soal_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $quizId) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                    if (file_put_contents($uploadsDir . '/' . $name, $bin) !== false) {
                        $currentImage = 'uploads/soal/' . $name;
                    }
                }
            } elseif (strpos($src, 'uploads/soal/') !== false) {
                $currentImage = $src;
            }
        }

        $text = trim($el->textContent);
        if ($text === '') continue;

        if ($text === '---' || $text === '===' || preg_match('/^Soal\s+(\d+)[:\.]?/i', $text)) {
            if (!empty($currentLines)) {
                $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
                $currentLines = [];
                $currentImage = null;
            }
            if (preg_match('/^Soal\s+(\d+)[:\.]?/i', $text)) {
                $currentLines[] = $text;
            }
        } else {
            $currentLines[] = $text;
        }
    }

    if (!empty($currentLines)) {
        $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
    }

    return parseQuestionBlocks($blocks);
}

function parseXlsxQuestionsWithImages($filePath, $uploadsDir, $quizId = 'quiz') {
    if (!file_exists($filePath)) return ['error' => 'Berkas tidak ditemukan.'];
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return ['error' => 'Format berkas Excel (.xlsx) tidak valid.'];

    $rowImages = [];
    $drawingRelsXml = $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels');
    $drawingXml = $zip->getFromName('xl/drawings/drawing1.xml');
    if ($drawingRelsXml && $drawingXml) {
        $relMap = [];
        $dDom = new DOMDocument();
        @$dDom->loadXML($drawingRelsXml);
        foreach ($dDom->getElementsByTagName('Relationship') as $r) {
            $rId = $r->getAttribute('Id');
            $target = $r->getAttribute('Target');
            $target = ltrim(str_replace('../', 'xl/', $target), '/');
            $relMap[$rId] = $target;
        }

        $drawDom = new DOMDocument();
        @$drawDom->loadXML($drawingXml);
        $twoCells = $drawDom->getElementsByTagName('twoCellAnchor');
        foreach ($twoCells as $tc) {
            $fromRow = null;
            $fromRows = $tc->getElementsByTagName('from');
            if ($fromRows->length > 0) {
                $rowEls = $fromRows->item(0)->getElementsByTagName('row');
                if ($rowEls->length > 0) $fromRow = (int)$rowEls->item(0)->nodeValue;
            }
            $blips = $tc->getElementsByTagName('blip');
            if ($blips->length > 0 && $fromRow !== null) {
                $rId = $blips->item(0)->getAttribute('r:embed');
                if ($rId && isset($relMap[$rId])) {
                    $mediaPath = $relMap[$rId];
                    $bin = $zip->getFromName($mediaPath);
                    if ($bin) {
                        if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
                        $ext = pathinfo($mediaPath, PATHINFO_EXTENSION) ?: 'png';
                        $imgName = 'img_soal_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $quizId) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        if (file_put_contents($uploadsDir . '/' . $imgName, $bin) !== false) {
                            $rowImages[$fromRow + 1] = 'uploads/soal/' . $imgName;
                        }
                    }
                }
            }
        }
    }

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $sDom = new DOMDocument();
        @$sDom->loadXML($ssXml);
        foreach ($sDom->getElementsByTagName('si') as $si) {
            $sharedStrings[] = $si->textContent;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        $zip->close();
        return ['error' => 'Lembar sheet1.xml tidak ditemukan di dalam berkas Excel.'];
    }

    $shDom = new DOMDocument();
    @$shDom->loadXML($sheetXml);
    $rows = $shDom->getElementsByTagName('row');

    $headerMap = [];
    $blocks = [];

    foreach ($rows as $rowEl) {
        $rowNum = (int)$rowEl->getAttribute('r');
        $cCells = $rowEl->getElementsByTagName('c');
        $rowData = [];

        foreach ($cCells as $c) {
            $cellRef = $c->getAttribute('r');
            $colLetter = preg_replace('/[0-9]/', '', $cellRef);
            $type = $c->getAttribute('t');
            $vEls = $c->getElementsByTagName('v');
            $val = '';
            if ($vEls->length > 0) {
                $rawVal = $vEls->item(0)->nodeValue;
                if ($type === 's' && isset($sharedStrings[(int)$rawVal])) {
                    $val = $sharedStrings[(int)$rawVal];
                } else {
                    $val = $rawVal;
                }
            } else {
                $tEls = $c->getElementsByTagName('t');
                if ($tEls->length > 0) $val = $tEls->item(0)->nodeValue;
            }
            $rowData[$colLetter] = trim($val);
        }

        if ($rowNum === 1) {
            foreach ($rowData as $col => $name) {
                $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $name));
                $headerMap[$col] = $cleanName;
            }
        } else {
            $namedRow = [];
            foreach ($rowData as $col => $v) {
                $k = $headerMap[$col] ?? $col;
                $namedRow[$k] = $v;
            }

            $img = $rowImages[$rowNum] ?? ($namedRow['gambar'] ?? '');

            $lines = [];
            $typeVal = $namedRow['tipe'] ?? ($namedRow['type'] ?? 'mc');
            if (empty($typeVal) && empty($namedRow['pertanyaan'])) continue;

            $lines[] = 'TIPE: ' . ($typeVal ?: 'mc');
            if (!empty($namedRow['pertanyaan'])) $lines[] = 'PERTANYAAN: ' . $namedRow['pertanyaan'];
            $caseVal = $namedRow['teks_kasus'] ?? ($namedRow['kasus'] ?? ($namedRow['bacaan'] ?? ''));
            if (!empty($caseVal)) $lines[] = 'KASUS: ' . $caseVal;
            $stmtVal = $namedRow['pernyataan'] ?? ($namedRow['statement'] ?? '');
            if (!empty($stmtVal)) $lines[] = 'PERNYATAAN: ' . $stmtVal;
            $reasonVal = $namedRow['alasan'] ?? ($namedRow['reason'] ?? '');
            if (!empty($reasonVal)) $lines[] = 'ALASAN: ' . $reasonVal;
            if (!empty($namedRow['opsi_a'])) $lines[] = 'A: ' . $namedRow['opsi_a'];
            if (!empty($namedRow['opsi_b'])) $lines[] = 'B: ' . $namedRow['opsi_b'];
            if (!empty($namedRow['opsi_c'])) $lines[] = 'C: ' . $namedRow['opsi_c'];
            if (!empty($namedRow['opsi_d'])) $lines[] = 'D: ' . $namedRow['opsi_d'];
            if (!empty($namedRow['opsi_e'])) $lines[] = 'E: ' . $namedRow['opsi_e'];
            $matchVal = $namedRow['pasangan_menjodohkan'] ?? ($namedRow['jodohkan'] ?? ($namedRow['pasangan'] ?? ''));
            if (!empty($matchVal)) {
                $lines[] = 'PASANGAN: ' . $matchVal;
            }
            $keyVal = $namedRow['kunci_jawaban'] ?? ($namedRow['kunci'] ?? ($namedRow['jawaban'] ?? ''));
            if (!empty($keyVal)) $lines[] = 'KUNCI: ' . $keyVal;
            if (!empty($namedRow['pembahasan'])) $lines[] = 'PEMBAHASAN: ' . $namedRow['pembahasan'];

            $blocks[] = ['lines' => $lines, 'image' => $img];
        }
    }
    $zip->close();

    return parseQuestionBlocks($blocks);
}

function parseTextQuestions($text, $quizId = 'quiz') {
    $rawBlocks = preg_split('/(\r?\n|^)(\-{3,}|\={3,})(\r?\n|$)/', $text);
    $blocks = [];
    foreach ($rawBlocks as $b) {
        $lines = array_filter(array_map('trim', explode("\n", $b)), fn($l) => $l !== '');
        if ($lines) $blocks[] = ['lines' => array_values($lines), 'image' => ''];
    }
    return parseQuestionBlocks($blocks);
}

function parseCsvQuestions($filePath, $quizId = 'quiz') {
    $handle = fopen($filePath, 'r');
    if (!$handle) return ['error' => 'Gagal membuka berkas CSV.'];
    $header = fgetcsv($handle);
    if (!$header) return ['error' => 'Berkas CSV kosong.'];
    $headerMap = array_map(fn($h) => strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $h)), $header);
    $blocks = [];
    while (($row = fgetcsv($handle)) !== false) {
        $named = [];
        foreach ($row as $i => $v) {
            $k = $headerMap[$i] ?? "col_$i";
            $named[$k] = trim($v);
        }
        $lines = [];
        $t = $named['tipe'] ?? ($named['type'] ?? 'mc');
        if (empty($t) && empty($named['pertanyaan'])) continue;
        $lines[] = 'TIPE: ' . ($t ?: 'mc');
        if (!empty($named['pertanyaan'])) $lines[] = 'PERTANYAAN: ' . $named['pertanyaan'];
        $caseVal = $named['teks_kasus'] ?? ($named['kasus'] ?? ($named['bacaan'] ?? ''));
        if (!empty($caseVal)) $lines[] = 'KASUS: ' . $caseVal;
        $stmtVal = $named['pernyataan'] ?? ($named['statement'] ?? '');
        if (!empty($stmtVal)) $lines[] = 'PERNYATAAN: ' . $stmtVal;
        $reasonVal = $named['alasan'] ?? ($named['reason'] ?? '');
        if (!empty($reasonVal)) $lines[] = 'ALASAN: ' . $reasonVal;
        if (!empty($named['opsi_a'])) $lines[] = 'A: ' . $named['opsi_a'];
        if (!empty($named['opsi_b'])) $lines[] = 'B: ' . $named['opsi_b'];
        if (!empty($named['opsi_c'])) $lines[] = 'C: ' . $named['opsi_c'];
        if (!empty($named['opsi_d'])) $lines[] = 'D: ' . $named['opsi_d'];
        if (!empty($named['opsi_e'])) $lines[] = 'E: ' . $named['opsi_e'];
        $matchVal = $named['pasangan_menjodohkan'] ?? ($named['jodohkan'] ?? ($named['pasangan'] ?? ''));
        if (!empty($matchVal)) {
            $lines[] = 'PASANGAN: ' . $matchVal;
        }
        $keyVal = $named['kunci_jawaban'] ?? ($named['kunci'] ?? ($named['jawaban'] ?? ''));
        if (!empty($keyVal)) $lines[] = 'KUNCI: ' . $keyVal;
        if (!empty($named['pembahasan'])) $lines[] = 'PEMBAHASAN: ' . $named['pembahasan'];
        $blocks[] = ['lines' => $lines, 'image' => $named['gambar'] ?? ''];
    }
    fclose($handle);
    return parseQuestionBlocks($blocks);
}

if ($action === 'import_questions_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $quizId = trim((string)($_POST['quiz_id'] ?? ''));
    if ($quizId === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Target paket kuis belum dipilih.']);
        exit;
    }
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Berkas gagal diunggah atau tidak ditemukan.']);
        exit;
    }

    $fileTmp = $_FILES['file']['tmp_name'];
    $fileName = strtolower($_FILES['file']['name']);
    $ext = pathinfo($fileName, PATHINFO_EXTENSION);
    $uploadsDir = __DIR__ . '/uploads/soal';
    if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

    $parsedQuestions = [];

    if ($ext === 'docx') {
        $parsedQuestions = parseDocxQuestions($fileTmp, $uploadsDir, $quizId);
    } elseif ($ext === 'doc') {
        $header = file_get_contents($fileTmp, false, null, 0, 4);
        if ($header === "PK\x03\x04") {
            $parsedQuestions = parseDocxQuestions($fileTmp, $uploadsDir, $quizId);
        } else {
            $parsedQuestions = parseHtmlDocQuestions($fileTmp, $uploadsDir, $quizId);
        }
    } elseif ($ext === 'xlsx' || $ext === 'xls') {
        $parsedQuestions = parseXlsxQuestionsWithImages($fileTmp, $uploadsDir, $quizId);
    } elseif ($ext === 'txt') {
        $parsedQuestions = parseTextQuestions(file_get_contents($fileTmp), $quizId);
    } elseif ($ext === 'csv') {
        $parsedQuestions = parseCsvQuestions($fileTmp, $quizId);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Format berkas tidak didukung. Harap unggah berkas .docx, .doc, .xlsx, .xls, .csv, atau .txt.']);
        exit;
    }

    if (isset($parsedQuestions['error'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $parsedQuestions['error']]);
        exit;
    }

    if (empty($parsedQuestions)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Tidak ada butir soal yang berhasil dibaca dari berkas. Pastikan format penulisan soal sesuai panduan template.']);
        exit;
    }

    $db = loadDb($file_db);
    $targetQuiz = null;
    foreach ($db['questions'] as &$q) {
        if ((string)$q['id'] === $quizId) {
            $targetQuiz = &$q;
            break;
        }
    }
    unset($q);

    if (!$targetQuiz) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Paket kuis target tidak ditemukan.']);
        exit;
    }

    if (!isset($targetQuiz['questions']) || !is_array($targetQuiz['questions'])) {
        $targetQuiz['questions'] = [];
    }

    $addedCount = 0;
    foreach ($parsedQuestions as $newQ) {
        $targetQuiz['questions'][] = $newQ;
        $addedCount++;
    }

    if (!empty($_POST['status'])) {
        $targetQuiz['status'] = trim((string)$_POST['status']);
    } elseif ($targetQuiz['status'] === 'DRAFT' && count($targetQuiz['questions']) > 0) {
        $targetQuiz['status'] = 'ACTIVE';
    }

    audit($db, 'Import soal file (' . $ext . ')', $targetQuiz['title'], "$addedCount soal ditambahkan");
    saveDb($file_db, $db);

    echo json_encode([
        'status' => 'success',
        'message' => "Berhasil mengimpor $addedCount butir soal ke dalam paket '{$targetQuiz['title']}'.",
        'added_count' => $addedCount,
        'quiz' => $targetQuiz,
        'all_questions' => $db['questions']
    ]);
    exit;
}

if ($action === 'delete_result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly(); $input=json_decode(file_get_contents('php://input'),true)?:[]; $nis=trim((string)($input['nis']??'')); $quizId=trim((string)($input['quiz_id']??'')); $db=loadDb($file_db);
    $before=count($db['results']); $removed=array_values(array_filter($db['results'],fn($r)=>(string)($r['nis']??'')===$nis&&(string)($r['code']??'')===$quizId)); $db['trash']['results']=array_merge($db['trash']['results'],$removed); $db['results']=array_values(array_filter($db['results'],fn($r)=>!((string)($r['nis']??'')===$nis&&(string)($r['code']??'')===$quizId))); audit($db,'Hapus hasil',$nis . ' / ' . $quizId); saveDb($file_db,$db);
    echo json_encode(['status'=>'success','message'=>$before===count($db['results'])?'Hasil tidak ditemukan.':'Hasil berhasil dihapus.']); exit;
}

if ($action === 'delete_results' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly(); $input=json_decode(file_get_contents('php://input'),true)?:[]; $items=$input['items']??[]; $db=loadDb($file_db); $keys=[]; foreach($items as $it){$keys[(string)($it['nis']??'').'|'.(string)($it['quiz_id']??'')]=true;}
    $db['results'] = array_values(array_filter($db['results'], function($r) use ($keys) { return empty($keys[(string)($r['nis'] ?? '') . '|' . (string)($r['code'] ?? '')]); })); saveDb($file_db, $db); echo json_encode(['status'=>'success','message'=>'Hasil terpilih berhasil dihapus.']); exit;
}

if ($action === 'get_students') {
    teacherOnly();
    $db = loadDb($file_db);
    $dirty = false;
    foreach ($db['students'] as &$st) {
        if (empty($st['id'])) {
            $st['id'] = 'std_' . bin2hex(random_bytes(6));
            $dirty = true;
        }
        if (!isset($st['tahun_ajaran']) || trim((string)$st['tahun_ajaran']) === '') {
            $st['tahun_ajaran'] = '2026-2027';
            $dirty = true;
        }
    }
    unset($st);
    if ($dirty) saveDb($file_db, $db);
    echo json_encode(['students' => $db['students'], 'settings' => $db['settings'] ?? []]);
    exit;
}

if (($action === 'save_student' || $action === 'update_student') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = trim((string)($input['id'] ?? ''));
    $nis = trim((string)($input['nis'] ?? ''));
    $originalNis = trim((string)($input['original_nis'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $class = trim((string)($input['class'] ?? ''));
    $tahun_ajaran = trim((string)($input['tahun_ajaran'] ?? $input['academic_year'] ?? '2026-2027'));
    $photo = (string)($input['photo'] ?? '');

    if ($name === '' || $class === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Nama siswa dan kelas wajib diisi.']);
        exit;
    }
    if (!empty($photo) && !preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $photo)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Format foto harus PNG, JPG, atau WEBP.']);
        exit;
    }

    $db = loadDb($file_db);

    // Validasi keunikan NIS (hanya jika NIS diisi)
    if ($nis !== '') {
        foreach ($db['students'] as $st) {
            $stNis = trim((string)($st['nis'] ?? ''));
            $stId = (string)($st['id'] ?? '');
            if ($stNis === $nis) {
                if ($id !== '' && $stId === $id) continue;
                if ($originalNis !== '' && $stNis === $originalNis) continue;
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => "NIS '{$nis}' sudah digunakan oleh siswa '{$st['name']}'."]);
                exit;
            }
        }
    }

    $existingPassword = '';
    if ($id !== '') {
        foreach ($db['students'] as $st) {
            if ((string)($st['id'] ?? '') === $id && !empty($st['password'])) {
                $existingPassword = (string)$st['password'];
                break;
            }
        }
    }
    if ($existingPassword === '' && $originalNis !== '') {
        foreach ($db['students'] as $st) {
            if ((string)($st['nis'] ?? '') === $originalNis && !empty($st['password'])) {
                $existingPassword = (string)$st['password'];
                break;
            }
        }
    }
    if ($existingPassword === '' && $nis !== '') {
        foreach ($db['students'] as $st) {
            if ((string)($st['nis'] ?? '') === $nis && !empty($st['password'])) {
                $existingPassword = (string)$st['password'];
                break;
            }
        }
    }

    $studentData = [
        'id' => $id !== '' ? $id : ('std_' . bin2hex(random_bytes(6))),
        'nis' => $nis,
        'name' => $name,
        'class' => $class,
        'tahun_ajaran' => $tahun_ajaran !== '' ? $tahun_ajaran : '2026-2027',
        'password' => $existingPassword !== '' ? $existingPassword : generateStudentPassword(),
        'photo' => $photo
    ];

    $found = false;
    $foundIdx = -1;
    $oldClass = '';
    if ($id !== '') {
        foreach ($db['students'] as $sIdx => $st) {
            if ((string)($st['id'] ?? '') === $id) {
                $oldClass = (string)($st['class'] ?? '');
                $foundIdx = $sIdx;
                $found = true;
                break;
            }
        }
    }
    if (!$found && $originalNis !== '') {
        foreach ($db['students'] as $sIdx => $st) {
            if ((string)($st['nis'] ?? '') === $originalNis) {
                $oldClass = (string)($st['class'] ?? '');
                $foundIdx = $sIdx;
                $found = true;
                break;
            }
        }
    }
    if (!$found && $nis !== '') {
        foreach ($db['students'] as $sIdx => $st) {
            if ((string)($st['nis'] ?? '') === $nis) {
                $oldClass = (string)($st['class'] ?? '');
                $foundIdx = $sIdx;
                $found = true;
                break;
            }
        }
    }

    if ($found && $foundIdx !== -1) {
        $db['students'][$foundIdx] = array_merge($db['students'][$foundIdx], $studentData);
        // Jika kelas diubah saat edit, pindahkan siswa ke urutan terbawah dari kelas baru tersebut
        if ($oldClass !== '' && strcasecmp(trim($oldClass), trim($class)) !== 0) {
            $updatedStudent = $db['students'][$foundIdx];
            array_splice($db['students'], $foundIdx, 1);

            $targetClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)$class)));
            $lastClassIdx = -1;
            foreach ($db['students'] as $sIdx => $st) {
                $stClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)($st['class'] ?? ''))));
                if ($stClassClean === $targetClassClean) {
                    $lastClassIdx = $sIdx;
                }
            }
            if ($lastClassIdx === -1 && $targetClassClean !== '') {
                foreach ($db['students'] as $sIdx => $st) {
                    $stClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)($st['class'] ?? ''))));
                    if (strpos($stClassClean, $targetClassClean) === 0 || strpos($targetClassClean, $stClassClean) === 0) {
                        $lastClassIdx = $sIdx;
                    }
                }
            }
            if ($lastClassIdx !== -1) {
                array_splice($db['students'], $lastClassIdx + 1, 0, [$updatedStudent]);
            } else {
                $db['students'][] = $updatedStudent;
            }
        }
    } else {
        // Tambah siswa manual baru: tempatkan di urutan terbawah sesuai kelas data tersebut
        $targetClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)$class)));
        $lastClassIdx = -1;

        // 1. Pencocokan kelas sama persis (case-insensitive & spasi seragam)
        foreach ($db['students'] as $sIdx => $st) {
            $stClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)($st['class'] ?? ''))));
            if ($stClassClean === $targetClassClean) {
                $lastClassIdx = $sIdx;
            }
        }

        // 2. Pencocokan awalan jika tidak ada yang sama persis
        if ($lastClassIdx === -1 && $targetClassClean !== '') {
            foreach ($db['students'] as $sIdx => $st) {
                $stClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)($st['class'] ?? ''))));
                if (strpos($stClassClean, $targetClassClean) === 0 || strpos($targetClassClean, $stClassClean) === 0) {
                    $lastClassIdx = $sIdx;
                }
            }
        }

        if ($lastClassIdx !== -1) {
            // Sisipkan tepat di urutan terakhir siswa pada kelas tersebut
            array_splice($db['students'], $lastClassIdx + 1, 0, [$studentData]);
        } else {
            // Jika kelas baru / belum ada di database, taruh di paling akhir
            $db['students'][] = $studentData;
        }
    }

    audit($db, $found ? 'Edit siswa' : 'Tambah siswa', $nis !== '' ? $nis : $name);
    saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'message' => 'Data siswa berhasil disimpan.']);
    exit;
}

if (($action === 'delete_student' || $action === 'delete_students') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $rawList = array_merge(
        (array)($input['ids'] ?? []),
        (array)($input['nis_list'] ?? [])
    );
    if (!empty($input['id'])) $rawList[] = $input['id'];
    if (!empty($input['nis'])) $rawList[] = $input['nis'];
    $ids = array_filter(array_map('trim', array_map('strval', $rawList)));
    $id = trim((string)($input['id'] ?? ''));
    $nis = trim((string)($input['nis'] ?? ''));

    $db = loadDb($file_db);

    if (!empty($ids)) {
        $idMap = array_flip($ids);
        $removed = [];
        $remaining = [];
        foreach ($db['students'] as $st) {
            $stId = (string)($st['id'] ?? '');
            $stNis = (string)($st['nis'] ?? '');
            if ((isset($idMap[$stId]) && $stId !== '') || (isset($idMap[$stNis]) && $stNis !== '')) {
                $removed[] = $st;
            } else {
                $remaining[] = $st;
            }
        }
        $db['trash']['students'] = array_merge($db['trash']['students'] ?? [], $removed);
        $db['students'] = $remaining;
        $count = count($removed);
        audit($db, 'Hapus siswa massal', "{$count} siswa");
        saveDb($file_db, $db);
        echo json_encode(['status' => 'success', 'message' => "{$count} data siswa berhasil dihapus.", 'count' => $count]);
        exit;
    }

    $removed = [];
    $remaining = [];
    foreach ($db['students'] as $st) {
        $match = false;
        if ($id !== '' && (string)($st['id'] ?? '') === $id) $match = true;
        elseif ($id === '' && $nis !== '' && (string)($st['nis'] ?? '') === $nis) $match = true;
        if ($match) $removed[] = $st; else $remaining[] = $st;
    }
    $db['trash']['students'] = array_merge($db['trash']['students'] ?? [], $removed);
    $db['students'] = $remaining;
    audit($db, 'Hapus siswa', $nis !== '' ? $nis : $id);
    saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'message' => 'Data siswa dipindahkan ke trash.']);
    exit;
}

if ($action === 'import_students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $rows = $input['students'] ?? [];
    if (!is_array($rows)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Data import tidak valid.']);
        exit;
    }

    $db = loadDb($file_db);
    $existingNisMap = [];
    foreach ($db['students'] as $idx => &$st) {
        if (empty($st['id'])) $st['id'] = 'std_' . bin2hex(random_bytes(6));
        $sNis = trim((string)($st['nis'] ?? ''));
        if ($sNis !== '') $existingNisMap[$sNis] = $idx;
    }
    unset($st);

    $imported = 0;
    $updated = 0;
    foreach ($rows as $r) {
        $name = trim((string)($r['name'] ?? ''));
        $class = trim((string)($r['class'] ?? ''));
        $nis = trim((string)($r['nis'] ?? ''));
        $tahun_ajaran = trim((string)($r['tahun_ajaran'] ?? $r['academic_year'] ?? '2026-2027'));
        $photo = (string)($r['photo'] ?? '');
        if ($name === '') continue;

        if ($nis !== '' && isset($existingNisMap[$nis])) {
            $idx = $existingNisMap[$nis];
            $db['students'][$idx]['name'] = $name;
            $db['students'][$idx]['class'] = $class ?: $db['students'][$idx]['class'];
            $db['students'][$idx]['tahun_ajaran'] = $tahun_ajaran ?: ($db['students'][$idx]['tahun_ajaran'] ?? '2026-2027');
            if (empty($db['students'][$idx]['password'])) {
                $db['students'][$idx]['password'] = generateStudentPassword();
            }
            if ($photo) $db['students'][$idx]['photo'] = $photo;
            $updated++;
        } else {
            $newSt = [
                'id' => 'std_' . bin2hex(random_bytes(6)),
                'nis' => $nis,
                'name' => $name,
                'class' => $class,
                'tahun_ajaran' => $tahun_ajaran ?: '2026-2027',
                'password' => generateStudentPassword(),
                'photo' => $photo
            ];
            $db['students'][] = $newSt;
            if ($nis !== '') $existingNisMap[$nis] = count($db['students']) - 1;
            $imported++;
        }
    }

    audit($db, 'Import siswa', "Baru: {$imported}, Diperbarui: {$updated}");
    saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'message' => "Data siswa berhasil diimpor (Baru: {$imported}, Diperbarui: {$updated}).", 'count' => ($imported + $updated)]);
    exit;
}

if ($action === 'clear_results' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $db = loadDb($file_db);
    backupDb($file_db,$db,'before-reset-results'); $db['trash']['results']=array_merge($db['trash']['results'],$db['results']); audit($db,'Reset hasil ujian');
    $db['results'] = [];
    saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'message' => 'Hasil berhasil direset']);
    exit;
}

if ($action === 'teacher_dashboard') {
    teacherOnly(); $db=loadDb($file_db); $active=[];
    $quizMap = [];
    foreach ($db['questions'] as $q) {
        if (!empty($q['id'])) $quizMap[(string)$q['id']] = $q['title'] ?? $q['id'];
    }
    foreach ($db['trash']['questions'] ?? [] as $q) {
        $qid = (string)($q['id'] ?? '');
        if ($qid !== '' && empty($quizMap[$qid])) $quizMap[$qid] = $q['title'] ?? $qid;
    }
    foreach ($db['results'] ?? [] as $r) {
        $qid = (string)($r['code'] ?? $r['quiz_id'] ?? '');
        if ($qid !== '' && empty($quizMap[$qid]) && !empty($r['title'])) $quizMap[$qid] = $r['title'];
    }
    if (is_dir('backups')) {
        $bFiles = glob('backups/*.json');
        if ($bFiles) {
            foreach ($bFiles as $bf) {
                $bc = @file_get_contents($bf);
                if ($bc) {
                    $bd = json_decode($bc, true);
                    if (!empty($bd['questions']) && is_array($bd['questions'])) {
                        foreach ($bd['questions'] as $bq) {
                            $bqid = (string)($bq['id'] ?? '');
                            if ($bqid !== '' && empty($quizMap[$bqid]) && !empty($bq['title'])) $quizMap[$bqid] = $bq['title'];
                        }
                    }
                }
            }
        }
    }
    $fallbackTitles = [
        'quiz-1789790671156' => 'UB STS Ganjil Kelas 7',
        'quiz-1791161243662' => 'Soal STS Ganjil 2026-2027',
        'quiz-1791169015921' => 'Soal STS Ganjil 2026-2027',
        'quiz-1794792463299' => 'UB SAS GANJIL 2026-2027',
        'quiz-1794805923538' => 'UB SAS GANJIL 26-27'
    ];
    foreach ($fallbackTitles as $fId => $fTitle) {
        if (empty($quizMap[$fId])) $quizMap[$fId] = $fTitle;
    }

    // Agregasi seluruh data monitoring peserta (student_sessions & active_attempts)
    $monitoring = [];
    $trackedNis = [];

    // 1. Sesi siswa yang login / standby / mengerjakan / selesai
    $db['student_sessions'] = $db['student_sessions'] ?? [];
    foreach ($db['student_sessions'] as $nisKey => $sess) {
        $sNis = (string)($sess['nis'] ?? $nisKey);
        if ($sNis === '') continue;

        // Cari active_attempt terkait jika ada
        $matchedAttempt = null;
        if (!empty($sess['attempt_token_hash']) && isset($db['active_attempts'][$sess['attempt_token_hash']])) {
            $matchedAttempt = $db['active_attempts'][$sess['attempt_token_hash']];
        } else {
            foreach ($db['active_attempts'] as $candidate) {
                if ((string)($candidate['nis'] ?? '') === $sNis) {
                    if (!$matchedAttempt || ($candidate['started_at'] ?? 0) > ($matchedAttempt['started_at'] ?? 0)) {
                        $matchedAttempt = $candidate;
                    }
                }
            }
        }

        $qid = (string)($sess['quiz_id'] ?? ($matchedAttempt['quiz_id'] ?? ''));
        $title = (!empty($sess['quiz_title']) && $sess['quiz_title'] !== $qid) ? $sess['quiz_title'] : ($quizMap[$qid] ?? ($qid !== '' ? $qid : '-'));

        $lastSeen = (int)($sess['last_seen'] ?? ($matchedAttempt['updated_at'] ?? $sess['login_at'] ?? time()));
        $endsAt = (int)($matchedAttempt['ends_at'] ?? ($sess['ends_at'] ?? 0));
        $startedAt = (int)($matchedAttempt['started_at'] ?? ($sess['started_at'] ?? $sess['login_at'] ?? 0));

        // Tentukan status pengerjaan
        $rawStatus = strtoupper((string)($sess['status'] ?? 'STANDBY'));
        $status = 'STANDBY';
        $statusLabel = 'Login / Standby';

        if ($rawStatus === 'FINISHED' || !empty($matchedAttempt['finished'])) {
            $status = 'FINISHED';
            $statusLabel = 'Selesai';
        } elseif ($matchedAttempt && $endsAt > 0 && time() > $endsAt) {
            $status = 'TIME_OVER';
            $statusLabel = 'Waktu Habis';
        } elseif ($rawStatus === 'WORKING' || $matchedAttempt) {
            if (time() - $lastSeen > 90) {
                $status = 'OFFLINE';
                $statusLabel = 'Terputus';
            } else {
                $status = 'WORKING';
                $statusLabel = 'Sedang Mengerjakan';
            }
        } else {
            if (time() - $lastSeen > 180) {
                $status = 'OFFLINE';
                $statusLabel = 'Terputus';
            } else {
                $status = 'STANDBY';
                $statusLabel = 'Login / Standby';
            }
        }

        // Hitung progres soal terjawab
        $ansCount = 0;
        $totalQ = 0;
        if ($matchedAttempt) {
            $totalQ = count($matchedAttempt['question_order'] ?? []);
            if (!empty($matchedAttempt['answers']) && is_array($matchedAttempt['answers'])) {
                foreach ($matchedAttempt['answers'] as $ansVal) {
                    if ($ansVal !== null && $ansVal !== '' && $ansVal !== []) {
                        $ansCount++;
                    }
                }
            }
        }

        $monitoring[] = [
            'nis' => $sNis,
            'name' => (string)($sess['name'] ?? ($matchedAttempt['name'] ?? $sNis)),
            'class' => (string)($sess['class'] ?? ($matchedAttempt['class'] ?? '-')),
            'quiz_id' => $qid,
            'quiz_title' => $title,
            'status' => $status,
            'status_label' => $statusLabel,
            'question' => $totalQ > 0 ? "{$ansCount} / {$totalQ}" : '-',
            'answered_count' => $ansCount,
            'total_questions' => $totalQ,
            'score' => $sess['score'] ?? ($matchedAttempt['score'] ?? null),
            'started_at' => $startedAt,
            'ends_at' => $endsAt,
            'updated_at' => $lastSeen,
            'login_at' => (int)($sess['login_at'] ?? $startedAt),
            'finished_at' => (int)($sess['finished_at'] ?? 0),
            'time_remaining' => ($status === 'WORKING' && $endsAt > time()) ? ($endsAt - time()) : 0,
            'ip' => (string)($sess['ip'] ?? ''),
            'violations_count' => (int)($sess['violations_count'] ?? ($matchedAttempt['violations_count'] ?? 0))
        ];
        $trackedNis[$sNis] = true;
    }

    // 2. Sertakan attempt aktif/terbaru dalam 24 jam terakhir yang belum tercatat di student_sessions
    $oneDayAgo = time() - 86400;
    foreach ($db['active_attempts'] as $a) {
        $aNis = (string)($a['nis'] ?? '');
        if ($aNis === '' || isset($trackedNis[$aNis])) continue;
        $aStart = (int)($a['started_at'] ?? 0);
        $aUpdated = (int)($a['updated_at'] ?? $aStart);
        if ($aStart < $oneDayAgo && $aUpdated < $oneDayAgo) continue;

        $qid = (string)($a['quiz_id'] ?? '');
        $title = (!empty($a['quiz_title']) && $a['quiz_title'] !== $qid) ? $a['quiz_title'] : ($quizMap[$qid] ?? ($qid !== '' ? $qid : '-'));
        $endsAt = (int)($a['ends_at'] ?? 0);

        $status = 'WORKING';
        $statusLabel = 'Sedang Mengerjakan';
        if (!empty($a['finished'])) {
            $status = 'FINISHED';
            $statusLabel = 'Selesai';
        } elseif ($endsAt > 0 && time() > $endsAt) {
            $status = 'TIME_OVER';
            $statusLabel = 'Waktu Habis';
        } elseif (time() - $aUpdated > 90) {
            $status = 'OFFLINE';
            $statusLabel = 'Terputus';
        }

        $ansCount = 0;
        $totalQ = count($a['question_order'] ?? []);
        if (!empty($a['answers']) && is_array($a['answers'])) {
            foreach ($a['answers'] as $ansVal) {
                if ($ansVal !== null && $ansVal !== '' && $ansVal !== []) {
                    $ansCount++;
                }
            }
        }

        $monitoring[] = [
            'nis' => $aNis,
            'name' => (string)($a['name'] ?? $aNis),
            'class' => (string)($a['class'] ?? '-'),
            'quiz_id' => $qid,
            'quiz_title' => $title,
            'status' => $status,
            'status_label' => $statusLabel,
            'question' => $totalQ > 0 ? "{$ansCount} / {$totalQ}" : '-',
            'answered_count' => $ansCount,
            'total_questions' => $totalQ,
            'score' => $a['score'] ?? null,
            'started_at' => $aStart,
            'ends_at' => $endsAt,
            'updated_at' => $aUpdated,
            'login_at' => $aStart,
            'finished_at' => !empty($a['finished']) ? $aUpdated : 0,
            'time_remaining' => ($status === 'WORKING' && $endsAt > time()) ? ($endsAt - time()) : 0,
            'violations_count' => (int)($a['violations_count'] ?? 0),
            'ip' => (string)($a['ip'] ?? '')
        ];
        $trackedNis[$aNis] = true;
    }

    // Urutkan peserta: aktivitas terbaru di atas
    usort($monitoring, function($a, $b) {
        return ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0);
    });

    // Ringkasan jumlah per status
    $counts = [
        'total' => count($monitoring),
        'working' => 0,
        'standby' => 0,
        'finished' => 0,
        'offline' => 0,
        'time_over' => 0
    ];
    foreach ($monitoring as $item) {
        $st = $item['status'];
        if ($st === 'WORKING') $counts['working']++;
        elseif ($st === 'STANDBY') $counts['standby']++;
        elseif ($st === 'FINISHED') $counts['finished']++;
        elseif ($st === 'OFFLINE') $counts['offline']++;
        elseif ($st === 'TIME_OVER') $counts['time_over']++;
    }

    $scores = array_map(fn($r)=>(int)($r['score']??0), $db['results']);
    echo json_encode([
        'status' => 'success',
        'monitoring' => $monitoring,
        'counts' => $counts,
        'audit_logs' => array_slice(array_reverse($db['audit_logs']), 0, 200),
        'summary' => [
            'students' => count($db['students']),
            'quizzes' => count($db['questions']),
            'results' => count($db['results']),
            'average' => $scores ? (int)round(array_sum($scores)/count($scores)) : 0
        ]
    ]);
    exit;
}

if ($action === 'reset_student_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $nis = trim((string)($input['nis'] ?? ''));
    $db = loadDb($file_db);
    $stName = $nis;

    if (isset($db['student_sessions'][$nis])) {
        $stName = $db['student_sessions'][$nis]['name'] ?? $nis;
        // Buka kunci sesi siswa dan beri izin relogin/resume
        $db['student_sessions'][$nis]['reset_allowed'] = true;
        $db['student_sessions'][$nis]['is_locked'] = false;
        $db['student_sessions'][$nis]['status'] = 'STANDBY';
        $db['student_sessions'][$nis]['last_seen'] = time();
    }

    // PENTING: JANGAN hapus active_attempts siswa!
    // Kunci sesi dibuka dan flag reset_allowed diaktifkan sehingga saat siswa Ahmad menyalakan
    // PC atau login kembali, seluruh progres soal dan jawaban tidak hilang dan langsung melanjutkan!
    $hasAttempt = false;
    foreach ($db['active_attempts'] as $ak => &$av) {
        if ((string)($av['nis'] ?? '') === $nis && empty($av['finished'])) {
            $av['reset_allowed'] = true;
            $hasAttempt = true;
        }
    }
    unset($av);

    audit($db, 'Reset sesi siswa', "{$nis} - {$stName}", 'Kunci sesi dibuka agar siswa dapat login ulang tanpa kehilangan jawaban');
    saveDb($file_db, $db);
    echo json_encode([
        'status' => 'success',
        'message' => "Sesi siswa {$stName} ({$nis}) berhasil direset. Siswa dapat login kembali dari komputer lab dan langsung melanjutkan ujian dari nomor terakhir tanpa kehilangan jawaban."
    ]);
    exit;
}

if ($action === 'force_finish_student_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $nis = trim((string)($input['nis'] ?? ''));
    $db = loadDb($file_db);

    $matchedAttempt = null;
    $matchedKey = null;
    foreach ($db['active_attempts'] as $ak => $cand) {
        if ((string)($cand['nis'] ?? '') === $nis && empty($cand['finished'])) {
            $matchedAttempt = $cand;
            $matchedKey = $ak;
            break;
        }
    }

    if (!$matchedAttempt) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Tidak ditemukan sesi ujian aktif yang sedang berjalan untuk siswa ini.']);
        exit;
    }

    $quizId = (string)$matchedAttempt['quiz_id'];
    $quiz = null;
    foreach ($db['questions'] as $q) {
        if ((string)($q['id'] ?? '') === $quizId) { $quiz = $q; break; }
    }
    if (!$quiz) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Data paket kuis tidak ditemukan.']);
        exit;
    }

    $questionOrder = $matchedAttempt['question_order'];
    $answers = normalizeAnswers($matchedAttempt['answers'] ?? []);
    foreach ($answers as $position => &$answer) {
        $qid = $questionOrder[(int)$position] ?? null;
        $map = $matchedAttempt['option_order'][$qid] ?? [];
        if (is_array($answer)) {
            $answer = array_values(array_filter(array_map(fn($v) => $map[(int)$v] ?? null, $answer), fn($v) => $v !== null));
        } elseif (is_numeric($answer) && isset($map[(int)$answer])) {
            $answer = $map[(int)$answer];
        }
    }
    unset($answer);

    [$score, $correct, $wrong, $breakdowns] = calculateSubmission($quiz, $questionOrder, $answers);
    $token = bin2hex(random_bytes(32));
    $record = [
        'nis' => $nis,
        'name' => (string)$matchedAttempt['name'],
        'class' => (string)$matchedAttempt['class'],
        'code' => $quizId,
        'title' => (string)($quiz['title'] ?? ''),
        'score' => $score,
        'correct' => $correct,
        'wrong' => $wrong,
        'date' => date('d/m/Y H:i:s'),
        'review_token' => $token,
        'question_order' => $questionOrder,
        'answers' => $answers,
        'breakdowns' => $breakdowns,
        'submitted_by' => 'Guru Pengawas (Selesai Paksa)'
    ];
    array_unshift($db['results'], $record);

    $db['active_attempts'][$matchedKey]['finished'] = true;
    $db['active_attempts'][$matchedKey]['score'] = $score;

    if (isset($db['student_sessions'][$nis])) {
        $db['student_sessions'][$nis]['status'] = 'FINISHED';
        $db['student_sessions'][$nis]['finished_at'] = time();
        $db['student_sessions'][$nis]['score'] = $score;
    }

    audit($db, 'Selesaikan paksa ujian', "{$nis} - " . (string)$matchedAttempt['name'], "Diselesaikan oleh guru pengawas. Nilai: {$score}");
    saveDb($file_db, $db);
    echo json_encode([
        'status' => 'success',
        'message' => "Ujian siswa {$matchedAttempt['name']} ({$nis}) berhasil dikumpulkan paksa. Nilai akhir: {$score}."
    ]);
    exit;
}

if ($action === 'get_result_detail') {
    teacherOnly();
    $db = loadDb($file_db);
    $nis = trim((string)($_GET['nis'] ?? ''));
    $quizId = trim((string)($_GET['quiz_id'] ?? ''));
    $date = trim((string)($_GET['date'] ?? ''));

    $found = null;
    foreach ($db['results'] as $r) {
        if ((string)($r['nis'] ?? '') === $nis && ((string)($r['code'] ?? '') === $quizId || $quizId === '')) {
            if ($date === '' || (string)($r['date'] ?? '') === $date) {
                $found = $r;
                break;
            }
        }
    }

    if (!$found) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Data rincian hasil ujian tidak ditemukan.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'result' => $found]);
    exit;
}

if ($action === 'clear_student_sessions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $db = loadDb($file_db);
    $db['student_sessions'] = [];
    foreach ($db['active_attempts'] as $ak => $av) {
        if (!empty($av['finished']) || (int)($av['ends_at'] ?? 0) < time()) {
            unset($db['active_attempts'][$ak]);
        }
    }
    audit($db, 'Bersihkan sesi monitoring', 'Semua siswa', 'Oleh guru');
    saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'message' => 'Semua riwayat monitoring berhasil dibersihkan.']);
    exit;
}

if ($action === 'exam_analytics') {
    teacherOnly(); $db=loadDb($file_db); $quizId=trim((string)($_GET['quiz_id']??'')); $results=array_values(array_filter($db['results'],fn($r)=>$quizId===''||(string)($r['code']??'')===$quizId));
    $scores=array_map(fn($r)=>(int)($r['score']??0),$results); $distribution=['0-59'=>0,'60-69'=>0,'70-79'=>0,'80-89'=>0,'90-100'=>0]; foreach($scores as $s){$distribution[$s<60?'0-59':($s<70?'60-69':($s<80?'70-79':($s<90?'80-89':'90-100')))]++;}
    $questions=[]; foreach($results as $r) foreach(($r['breakdowns']??[]) as $i=>$b){$key=(string)($b['question']['id']??($r['code'].'-'.$i)); if(!isset($questions[$key])) $questions[$key]=['number'=>$i+1,'question'=>$b['question']['question']??$b['question']['statement']??'Soal','correct'=>0,'total'=>0,'options'=>[],'key'=>$b['correctAnswerText']??'-']; $questions[$key]['total']++; if(!empty($b['isCorrect']))$questions[$key]['correct']++; $answer=$b['studentAnswerText']??'Tidak dijawab'; $questions[$key]['options'][$answer]=($questions[$key]['options'][$answer]??0)+1; }
    echo json_encode(['status'=>'success','summary'=>['participants'=>count($results),'average'=>$scores?(int)round(array_sum($scores)/count($scores)):0,'highest'=>$scores?max($scores):0,'lowest'=>$scores?min($scores):0,'distribution'=>$distribution],'questions'=>array_values($questions)]); exit;
}

if ($action === 'get_trash') { teacherOnly(); $db=loadDb($file_db); echo json_encode(['status'=>'success','trash'=>$db['trash']]); exit; }
if ($action === 'restore_trash' && $_SERVER['REQUEST_METHOD'] === 'POST') { teacherOnly(); $input=json_decode(file_get_contents('php://input'),true)?:[]; $type=(string)($input['type']??''); $index=(int)($input['index']??-1); $db=loadDb($file_db); if(!isset($db['trash'][$type][$index])){http_response_code(404);echo json_encode(['status'=>'error','message'=>'Data trash tidak ditemukan.']);exit;} $item=$db['trash'][$type][$index]; if($type==='results')$db['results'][]=$item; elseif($type==='students')$db['students'][]=$item; else {http_response_code(400);echo json_encode(['status'=>'error','message'=>'Jenis data tidak dapat dipulihkan.']);exit;} array_splice($db['trash'][$type],$index,1); audit($db,'Pulihkan dari trash',$type); saveDb($file_db,$db); echo json_encode(['status'=>'success','message'=>'Data dipulihkan.']);exit; }

if ($action === 'create_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly(); $db=loadDb($file_db); backupDb($file_db,$db,'manual'); audit($db,'Backup manual'); saveDb($file_db,$db); echo json_encode(['status'=>'success','message'=>'Backup berhasil dibuat.','backups'=>$db['backups']]); exit;
}

if ($action === 'delete_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $files = [];
    if (!empty($input['files']) && is_array($input['files'])) {
        foreach ($input['files'] as $f) {
            $fn = basename(trim((string)$f));
            if ($fn !== '') {
                $files[] = $fn;
            }
        }
    } elseif (!empty($input['file'])) {
        $fn = basename(trim((string)$input['file']));
        if ($fn !== '') {
            $files[] = $fn;
        }
    }

    if (empty($files)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Nama berkas cadangan tidak valid atau belum dipilih.']);
        exit;
    }

    $db = loadDb($file_db);
    $newBackups = [];
    $backupsList = $db['backups'] ?? [];
    $deleteMap = array_flip($files);

    foreach ($backupsList as $b) {
        $curFile = (string)($b['file'] ?? $b['name'] ?? '');
        if (isset($deleteMap[$curFile])) {
            $filePath = __DIR__ . '/backups/' . $curFile;
            if (file_exists($filePath) && is_file($filePath)) {
                @unlink($filePath);
            }
        } else {
            $newBackups[] = $b;
        }
    }

    foreach ($files as $fn) {
        $filePath = __DIR__ . '/backups/' . $fn;
        if (file_exists($filePath) && is_file($filePath)) {
            @unlink($filePath);
        }
    }

    $db['backups'] = $newBackups;
    $auditDetail = count($files) > 1 ? count($files) . ' berkas: ' . implode(', ', array_slice($files, 0, 5)) . (count($files) > 5 ? '...' : '') : $files[0];
    audit($db, 'Hapus berkas cadangan', $auditDetail);
    saveDb($file_db, $db);

    $msg = count($files) > 1 
        ? count($files) . ' berkas cadangan berhasil dihapus.' 
        : 'Berkas cadangan berhasil dihapus.';

    echo json_encode([
        'status' => 'success', 
        'message' => $msg, 
        'backups' => $db['backups'],
        'deleted_count' => count($files)
    ]);
    exit;
}

if ($action === 'system_status') {
    teacherOnly(); $db=loadDb($file_db); $writable=is_writable(__DIR__) && is_writable($file_db);
    echo json_encode(['status'=>'success','checks'=>[['name'=>'PHP','ok'=>true,'detail'=>PHP_VERSION],['name'=>'API Guru','ok'=>true,'detail'=>'Sesi terverifikasi'],['name'=>'Session','ok'=>is_writable(session_save_path()),'detail'=>session_save_path()],['name'=>'Storage JSON','ok'=>$writable,'detail'=>basename($file_db)],['name'=>'Backup','ok'=>is_dir(__DIR__.'/backups')||is_writable(__DIR__),'detail'=>'Folder backups']]]); exit;
}

if ($action === 'request_extension' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = (string)($input['attempt_token'] ?? '');
    $db = loadDb($file_db);
    $key = attemptKey($token);
    $attempt = $db['active_attempts'][$key] ?? null;
    if (!$attempt || !empty($attempt['finished'])) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Sesi ujian tidak aktif atau tidak ditemukan.']);
        exit;
    }
    $found = false;
    foreach ($db['time_extension_requests'] as &$req) {
        if (($req['token_hash'] ?? '') === $key) {
            $req['status'] = 'PENDING';
            $req['requested_at'] = time();
            $found = true;
            break;
        }
    }
    unset($req);
    if (!$found) {
        $quiz = null;
        foreach ($db['questions'] as $q) {
            if ((string)($q['id'] ?? '') === (string)$attempt['quiz_id']) { $quiz = $q; break; }
        }
        $db['time_extension_requests'][] = [
            'id' => 'req_' . bin2hex(random_bytes(8)),
            'token_hash' => $key,
            'nis' => (string)$attempt['nis'],
            'name' => (string)$attempt['name'],
            'class' => (string)$attempt['class'],
            'quiz_id' => (string)$attempt['quiz_id'],
            'quiz_title' => (string)($quiz['title'] ?? $attempt['quiz_id']),
            'requested_at' => time(),
            'status' => 'PENDING',
            'added_minutes' => 0
        ];
    }
    audit($db, 'Minta perpanjangan waktu', $attempt['nis'] . ' / ' . $attempt['quiz_id']);
    saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'message' => 'Permintaan perpanjangan waktu berhasil dikirim.']);
    exit;
}

if ($action === 'check_extension_requests') {
    teacherOnly();
    $db = loadDb($file_db);
    $all = array_values($db['time_extension_requests'] ?? []);
    // Urutkan waktu request terbaru di atas
    usort($all, function($a, $b) {
        return ($b['requested_at'] ?? 0) <=> ($a['requested_at'] ?? 0);
    });
    $pending = array_values(array_filter($all, fn($r) => ($r['status'] ?? '') === 'PENDING'));
    echo json_encode([
        'status' => 'success',
        'requests' => $pending,
        'all_requests' => $all,
        'pending_count' => count($pending)
    ]);
    exit;
}

if ($action === 'approve_extension' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    
    // Jika dikirim approve: false, teruskan ke alur penolakan
    if (isset($input['approve']) && $input['approve'] === false) {
        $tokenHash = trim((string)($input['token_hash'] ?? ''));
        $attemptToken = trim((string)($input['attempt_token'] ?? ''));
        $reqId = trim((string)($input['id'] ?? $input['request_id'] ?? ''));
        $db = loadDb($file_db);
        $db['time_extension_requests'] = $db['time_extension_requests'] ?? [];

        if ($tokenHash === '' && $attemptToken !== '') $tokenHash = attemptKey($attemptToken);
        if ($tokenHash === '' && $reqId !== '') {
            foreach ($db['time_extension_requests'] as $r) {
                if (($r['id'] ?? '') === $reqId) { $tokenHash = (string)($r['token_hash'] ?? ''); break; }
            }
        }

        foreach ($db['time_extension_requests'] as &$req) {
            if (($req['token_hash'] ?? '') === $tokenHash || ($reqId !== '' && ($req['id'] ?? '') === $reqId)) {
                $req['status'] = 'REJECTED';
                $req['responded_at'] = time();
                audit($db, 'Tolak perpanjangan waktu', $req['nis']);
                break;
            }
        }
        unset($req);
        saveDb($file_db, $db);
        echo json_encode(['status' => 'success', 'message' => 'Permintaan perpanjangan waktu ditolak.']);
        exit;
    }

    $tokenHash = trim((string)($input['token_hash'] ?? ''));
    $attemptToken = trim((string)($input['attempt_token'] ?? ''));
    $reqId = trim((string)($input['id'] ?? $input['request_id'] ?? ''));
    $nis = trim((string)($input['nis'] ?? ''));
    $minutes = (int)($input['minutes'] ?? 0);

    if ($minutes < 1) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Jumlah menit perpanjangan waktu harus minimal 1 menit.']);
        exit;
    }

    $db = loadDb($file_db);
    $db['time_extension_requests'] = $db['time_extension_requests'] ?? [];

    if ($tokenHash === '' && $attemptToken !== '') $tokenHash = attemptKey($attemptToken);
    if ($tokenHash === '' && $reqId !== '') {
        foreach ($db['time_extension_requests'] as $r) {
            if (($r['id'] ?? '') === $reqId) { $tokenHash = (string)($r['token_hash'] ?? ''); break; }
        }
    }
    if ($tokenHash === '' && $nis !== '') {
        foreach ($db['active_attempts'] as $ak => $av) {
            if ((string)($av['nis'] ?? '') === $nis && empty($av['finished'])) { $tokenHash = $ak; break; }
        }
    }

    if (!isset($db['active_attempts'][$tokenHash])) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Sesi ujian siswa tidak ditemukan di server.']);
        exit;
    }

    $attempt = &$db['active_attempts'][$tokenHash];
    $baseTime = max(time(), (int)($attempt['ends_at'] ?? time()));
    $newEndsAt = $baseTime + ($minutes * 60);
    $attempt['ends_at'] = $newEndsAt;
    $sNis = (string)($attempt['nis'] ?? '');
    unset($attempt);

    if ($sNis !== '' && isset($db['student_sessions'][$sNis])) {
        $db['student_sessions'][$sNis]['ends_at'] = $newEndsAt;
    }

    $matchedReq = false;
    foreach ($db['time_extension_requests'] as &$req) {
        if (($req['token_hash'] ?? '') === $tokenHash || ($reqId !== '' && ($req['id'] ?? '') === $reqId)) {
            $req['status'] = 'APPROVED';
            $req['added_minutes'] = $minutes;
            $req['new_ends_at'] = $newEndsAt;
            $req['responded_at'] = time();
            $matchedReq = true;
            audit($db, "Setujui perpanjangan waktu +{$minutes}m", ($req['nis'] ?? $sNis));
            break;
        }
    }
    unset($req);

    if (!$matchedReq) {
        audit($db, "Setujui perpanjangan waktu +{$minutes}m", $sNis);
    }

    saveDb($file_db, $db);
    echo json_encode([
        'status' => 'success',
        'message' => "Perpanjangan waktu {$minutes} menit berhasil diberikan.",
        'new_ends_at' => $newEndsAt,
        'added_minutes' => $minutes
    ]);
    exit;
}

if ($action === 'reject_extension' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $tokenHash = trim((string)($input['token_hash'] ?? ''));
    $attemptToken = trim((string)($input['attempt_token'] ?? ''));
    $reqId = trim((string)($input['id'] ?? $input['request_id'] ?? ''));
    $db = loadDb($file_db);
    $db['time_extension_requests'] = $db['time_extension_requests'] ?? [];

    if ($tokenHash === '' && $attemptToken !== '') $tokenHash = attemptKey($attemptToken);
    if ($tokenHash === '' && $reqId !== '') {
        foreach ($db['time_extension_requests'] as $r) {
            if (($r['id'] ?? '') === $reqId) { $tokenHash = (string)($r['token_hash'] ?? ''); break; }
        }
    }

    foreach ($db['time_extension_requests'] as &$req) {
        if (($req['token_hash'] ?? '') === $tokenHash || ($reqId !== '' && ($req['id'] ?? '') === $reqId)) {
            $req['status'] = 'REJECTED';
            $req['responded_at'] = time();
            audit($db, 'Tolak perpanjangan waktu', $req['nis']);
            break;
        }
    }
    unset($req);
    saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'message' => 'Permintaan perpanjangan waktu ditolak.']);
    exit;
}

if ($action === 'clear_extension_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    teacherOnly();
    $db = loadDb($file_db);
    // Pertahankan yang masih pending, hapus yang sudah approved / rejected
    $db['time_extension_requests'] = array_values(array_filter($db['time_extension_requests'] ?? [], fn($r) => ($r['status'] ?? '') === 'PENDING'));
    audit($db, 'Bersihkan riwayat perpanjangan waktu');
    saveDb($file_db, $db);
    echo json_encode(['status' => 'success', 'message' => 'Riwayat perpanjangan waktu berhasil dibersihkan.']);
    exit;
}

if ($action === 'check_extension_status') {
    $token = (string)($_GET['token'] ?? '');
    $db = loadDb($file_db);
    $key = attemptKey($token);
    $attempt = $db['active_attempts'][$key] ?? null;
    $targetReq = null;
    foreach ($db['time_extension_requests'] ?? [] as $req) {
        if (($req['token_hash'] ?? '') === $key) {
            $targetReq = $req;
            break;
        }
    }
    if (!$targetReq) {
        echo json_encode(['status' => 'success', 'extension_status' => 'NONE']);
        exit;
    }
    echo json_encode([
        'status' => 'success',
        'extension_status' => $targetReq['status'],
        'ends_at' => $attempt ? (int)$attempt['ends_at'] : 0,
        'server_now' => time()
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['status'=>'error','message'=>'Aksi API tidak ditemukan.']);
