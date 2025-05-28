<?php
session_start();
include("db.php");

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Fungsi bantu untuk membandingkan dua DateTime
function isOverlap($start1, $end1, $start2, $end2) {
    return ($start1 < $end2) && ($end1 > $start1);
}

// Fungsi Divide and Conquer untuk cek bentrok (menggunakan binary search untuk mempercepat)
function isConflictDC($events, $start, $end, $left, $right) {
    if ($left > $right) {
        return false;
    }
    $mid = intdiv($left + $right, 2);
    $e_start = new DateTime($events[$mid]['start_time']);
    $e_end = clone $e_start;
    $e_end->modify("+{$events[$mid]['duration']} minutes");

    if (isOverlap($start, $end, $e_start, $e_end)) {
        return true;
    }
    if ($start < $e_start) {
        return isConflictDC($events, $start, $end, $left, $mid - 1);
    } else {
        return isConflictDC($events, $start, $end, $mid + 1, $right);
    }
}

// Wrapper fungsi cek bentrok dengan Divide and Conquer
function isConflict($conn, $date, $start_time, $duration) {
    $stmt = $conn->prepare("SELECT start_time, duration FROM events WHERE date = ? ORDER BY start_time ASC");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $events = $result->fetch_all(MYSQLI_ASSOC);

    $start = new DateTime($start_time);
    $end = clone $start;
    $end->modify("+{$duration} minutes");

    return isConflictDC($events, $start, $end, 0, count($events) - 1);
}

// Fungsi Divide and Conquer untuk cari slot kosong
function getNextAvailableSlot($conn, $date, $duration) {
    $stmt = $conn->prepare("SELECT start_time, duration FROM events WHERE date = ? ORDER BY start_time ASC");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $events = $result->fetch_all(MYSQLI_ASSOC);

    $start_of_day = new DateTime("07:00");
    $end_of_day = new DateTime("21:00");

    $slots = [];
    $slots[] = [
        'start' => $start_of_day,
        'end' => $start_of_day
    ];

    foreach ($events as $ev) {
        $s = new DateTime($ev['start_time']);
        $e = clone $s;
        $e->modify("+{$ev['duration']} minutes");
        $slots[] = ['start' => $s, 'end' => $e];
    }

    $slots[] = [
        'start' => $end_of_day,
        'end' => $end_of_day
    ];

    function findSlotRecursive($slots, $duration, $left, $right) {
        if ($left >= $right) {
            return null;
        }

        $mid = intdiv($left + $right, 2);

        $gap_start = $slots[$mid]['end'];
        $gap_end = $slots[$mid + 1]['start']; 

        $gap_minutes = ($gap_end->getTimestamp() - $gap_start->getTimestamp()) / 60;

        if ($gap_minutes >= $duration) {
            $left_slot = findSlotRecursive($slots, $duration, $left, $mid - 1);
            if ($left_slot !== null) {
                return $left_slot;
            }
            return $gap_start->format('H:i');
        } else {
            return findSlotRecursive($slots, $duration, $mid + 1, $right);
        }
    }

    return findSlotRecursive($slots, $duration, 0, count($slots) - 2);
}

// Proses form tambah
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_title'])) {
    $messages = [];
    $inputEvents = [];
    for ($i = 0; $i < count($_POST['bulk_title']); $i++) {
        $title = trim($_POST['bulk_title'][$i]);
        $date = $_POST['bulk_date'][$i];
        $start = $_POST['bulk_start'][$i];
        $duration = intval($_POST['bulk_duration'][$i]);
        $weight = intval($_POST['bulk_weight'][$i]);
        if ($title && $date && $start && $duration > 0) {
            $start_dt = new DateTime("$date $start");
            $end_dt = clone $start_dt;
            $end_dt->modify("+{$duration} minutes");
            $inputEvents[] = [
                'title' => $title,
                'date' => $date,
                'start' => $start,
                'duration' => $duration,
                'weight' => $weight,
                'start_dt' => $start_dt,
                'end_dt' => $end_dt,
                'index' => $i
            ];
        }
    }

    // Kelompokkan berdasarkan tanggal
    $eventsByDate = [];
    foreach ($inputEvents as $ev) {
        $eventsByDate[$ev['date']][] = $ev;
    }

    $selected = [];
    foreach ($eventsByDate as $date => $events) {
        // Urutkan: bobot tertinggi dulu, lalu waktu mulai
        usort($events, function($a, $b) {
            if ($b['weight'] !== $a['weight']) {
                return $b['weight'] - $a['weight'];
            }
            return $a['start_dt'] <=> $b['start_dt'];
        });

        $chosen = [];
        foreach ($events as $ev) {
            $bentrok = false;
            foreach ($chosen as $sel) {
                if ($ev['start_dt'] < $sel['end_dt'] && $ev['end_dt'] > $sel['start_dt']) {
                    $bentrok = true;
                    break;
                }
            }
            if (!$bentrok) {
                $chosen[] = $ev;
            } else {
                $messages[] = "Jadwal '<strong>{$ev['title']}</strong>' tidak dijadwalkan karena bentrok dan prioritas lebih rendah.";
            }
        }
        $selected = array_merge($selected, $chosen);
    }

    // Simpan ke database
    foreach ($selected as $ev) {
        $today = (new DateTime())->format('Y-m-d');
        if ($ev['date'] < $today) {
            $messages[] = "Waktu '<strong>{$ev['title']}</strong>' sudah lewat.";
            continue;
        }
        if ($ev['start'] >= '24:00') {
            $messages[] = "Jam mulai '<strong>{$ev['title']}</strong>' tidak boleh 24:00.";
            continue;
        }
        if (isConflict($conn, $ev['date'], $ev['start'], $ev['duration'])) {
            $messages[] = "Jadwal '<strong>{$ev['title']}</strong>' bentrok dengan jadwal di database dan tidak disimpan.";
        } else {
            $stmt = $conn->prepare("INSERT INTO events (title, date, start_time, duration, weight) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssii", $ev['title'], $ev['date'], $ev['start'], $ev['duration'], $ev['weight']);
            if ($stmt->execute()) {
                $messages[] = "Jadwal '<strong>{$ev['title']}</strong>' berhasil disimpan.";
            } else {
                $messages[] = "Gagal menyimpan jadwal '{$ev['title']}'.";
            }
        }
    }
    $_SESSION['messages'] = $messages;
    header("Location: index.php");
    exit;
}

// Hapus event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event_id'])) {
    $id = intval($_POST['delete_event_id']);
    $conn->query("DELETE FROM events WHERE id = $id");
    header("Location: index.php");
    exit;
}

// Hapus event yang sudah lewat (opsional)
$now = (new DateTime())->format('Y-m-d H:i:s');
$conn->query("DELETE FROM events WHERE STR_TO_DATE(CONCAT(date, ' ', start_time), '%Y-%m-%d %H:%i') + INTERVAL duration MINUTE < '$now'");

// Ambil bulan & tahun dari URL jika ada
$currentDate = new DateTime();
$currentMonth = isset($_GET['month']) ? str_pad(intval($_GET['month']), 2, '0', STR_PAD_LEFT) : $currentDate->format('m');
$currentYear = isset($_GET['year']) ? intval($_GET['year']) : $currentDate->format('Y');
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);
$firstDayOfMonth = new DateTime("{$currentYear}-{$currentMonth}-01");
$firstDayOfWeek = $firstDayOfMonth->format('w');

// Hitung bulan sebelumnya & berikutnya
$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Ambil event sesuai bulan & tahun
$events = [];
$result = $conn->query("SELECT * FROM events WHERE MONTH(date) = $currentMonth AND YEAR(date) = $currentYear ORDER BY date ASC, start_time ASC");
$now = new DateTime();

while ($row = $result->fetch_assoc()) {
    $row['weight'] = (int)$row['weight'];
    // Tambahkan pengecekan sudah lewat atau belum
    $eventDateTime = new DateTime($row['date'] . ' ' . $row['start_time']);
    $eventEnd = clone $eventDateTime;
    $eventEnd->modify("+{$row['duration']} minutes");
    $now = new DateTime();
    $row['hasPassed'] = $eventEnd <= $now;
    $events[] = $row;
}

// Algoritma penjadwalan interval berbobot
function weightedIntervalScheduling($events) {
    foreach ($events as &$ev) {
        $ev['weight'] = (int)$ev['weight']; 
    }
    unset($ev);

    usort($events, function($a, $b) {
        $a_end = (new DateTime($a['start_time']))->modify("+{$a['duration']} minutes");
        $b_end = (new DateTime($b['start_time']))->modify("+{$b['duration']} minutes");
        return $a_end <=> $b_end;
    });

    $n = count($events);
    $p = array_fill(0, $n, -1);

    for ($j = 0; $j < $n; $j++) {
        $start_j = new DateTime($events[$j]['start_time']);
        for ($i = $j - 1; $i >= 0; $i--) {
            $end_i = new DateTime($events[$i]['start_time']);
            $end_i->modify("+{$events[$i]['duration']} minutes");
            if ($end_i <= $start_j) {
                $p[$j] = $i;
                break;
            }
        }
    }

    $dp = array_fill(0, $n, 0);
    $sol = array_fill(0, $n, false);
    for ($j = 0; $j < $n; $j++) {
        $incl = $events[$j]['weight'];
        if ($p[$j] != -1) $incl += $dp[$p[$j]];
        $excl = $j > 0 ? $dp[$j-1] : 0;
        if ($incl > $excl) {
            $dp[$j] = $incl;
            $sol[$j] = true;
        } else {
            $dp[$j] = $excl;
            $sol[$j] = false;
        }
    }

    $selected = [];
    $j = $n - 1;
    while ($j >= 0) {
        if ($sol[$j]) {
            $selected[] = $events[$j];
            $j = $p[$j];
        } else {
            $j--;
        }
    }
    return array_reverse($selected);
}

// Fungsi label bobot untuk PHP
function labelBobot($weight) {
    switch ($weight) {
        case 4: return "Sangat Penting";
        case 3: return "Penting";
        case 2: return "Kurang Penting";
        default: return "Tidak Penting";
    }
}

// Jika ada event, gunakan algoritma penjadwalan interval berbobot
if (count($events) > 0) {
    $scheduledEvents = weightedIntervalScheduling($events);
    // Tambahkan hasPassed ke setiap event terjadwal
    foreach ($scheduledEvents as &$ev) {
        $eventDateTime = new DateTime($ev['date'] . ' ' . $ev['start_time']);
        $eventEnd = clone $eventDateTime;
        $eventEnd->modify("+{$ev['duration']} minutes");
        $now = new DateTime();
        $ev['hasPassed'] = $eventEnd <= $now;
    }
    unset($ev);
} else {
    $scheduledEvents = [];
}

$dataEvents = json_encode($scheduledEvents, JSON_NUMERIC_CHECK);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Penjadwalan Multi-Acara (Divide and Conquer)</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h2>Tambah Beberapa Acara</h2>
    <?php
    if (!empty($_SESSION['messages'])) {
        foreach ($_SESSION['messages'] as $msg) {
            $isError = stripos($msg, 'bentrok') !== false || stripos($msg, 'lewat') !== false || stripos($msg, 'Gagal') !== false;
            $icon = $isError
                ? '<i style="color:#b91c1c;">&#9888;</i>'
                : '<i style="color:#2563eb;">&#10003;</i>';
            $alertClass = $isError ? 'alert alert-error' : 'alert alert-success';
            echo "<div class='$alertClass'>$icon $msg</div>";
        }
        unset($_SESSION['messages']);
    }
    ?>

    <form method="post">
        <div id="multi-form">
            <div class="form-row">
                <input type="text" name="bulk_title[]" placeholder="Judul Acara" required>
                <input type="date" name="bulk_date[]" required>
                <input type="time" name="bulk_start[]" required>
                <input type="number" name="bulk_duration[]" placeholder="Durasi (menit)" required>
                <select name="bulk_weight[]" required>
                    <option value="4">Sangat Penting</option>
                    <option value="3">Penting</option>
                    <option value="2">Kurang Penting</option>
                    <option value="1">Tidak Penting</option>
                </select>
                <button type="button" onclick="removeRow(this)">Hapus</button>
            </div>
        </div>
        <button type="button" onclick="addRow()">Tambah Baris</button>
        <button type="submit">Cek & Simpan</button>
    </form>

    <?php include 'kalender.php'; ?>

<!-- Modal untuk detail jadwal -->
<div id="eventModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeModal()">&times;</span>
    <h3 id="modalTitle"></h3>
    <div id="modalEvents"></div>
  </div>
</div>

<script>
function closeModal() {
    document.getElementById('eventModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.calendar-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const date = btn.dataset.date;
            const events = JSON.parse(btn.dataset.events);
            document.getElementById('modalTitle').textContent = "Jadwal Tanggal " + date;
            let html = '';
            if (events.length === 0) {
                html = "<i>Tidak ada jadwal.</i>";
            } else {
                events.forEach(function(ev) {
                    let [h, m] = ev.start_time.split(':');
                    let startDate = new Date(0, 0, 0, parseInt(h), parseInt(m));
                    startDate.setMinutes(startDate.getMinutes() + parseInt(ev.duration));
                    let endHour = startDate.getHours();
                    let endMin = startDate.getMinutes();
                    let nextDay = false;
                    if (endHour >= 24) {
                        endHour = endHour % 24;
                        nextDay = true;
                    }
                    let endTime = String(endHour).padStart(2, '0') + ':' + String(endMin).padStart(2, '0');
                    if (nextDay) endTime += ' (+1 hari)';

                    html += `<div style="margin-bottom:8px;">
    <b>${ev.title}</b> <span style='font-size:12px;color:#888;'>[${labelBobotJS(ev.weight)}]</span><br>
    Mulai: ${ev.start_time}<br>
    Selesai: ${endTime}<br>
    Durasi: ${ev.duration} menit
    ${ev.hasPassed ? "<br><em>(Sudah lewat)</em>" : ""}
    <form method="post" style="display:inline;">
        <input type="hidden" name="delete_event_id" value="${ev.id}">
        <button type="submit" onclick="return confirm('Hapus jadwal ini?')">Hapus</button>
    </form>
</div>`;
                });
            }
            document.getElementById('modalEvents').innerHTML = html;
            document.getElementById('eventModal').style.display = 'block';
        });
    });
});

window.onclick = function(event) {
    var modal = document.getElementById('eventModal');
    if (event.target == modal) {
        modal.style.display = "none";
    }
}

function addRow() {
    const container = document.getElementById('multi-form');
    const row = document.createElement('div');
    row.className = 'form-row';
    row.innerHTML = `
    <input type="text" name="bulk_title[]" placeholder="Judul Acara" required>
    <input type="date" name="bulk_date[]" required>
    <input type="time" name="bulk_start[]" required>
    <input type="number" name="bulk_duration[]" placeholder="Durasi (menit)" required>
    <select name="bulk_weight[]" required>
        <option value="4">Sangat Penting</option>
        <option value="3">Penting</option>
        <option value="2">Kurang Penting</option>
        <option value="1">Tidak Penting</option>
    </select>
    <button type="button" onclick="removeRow(this)">Hapus</button>
    `;
    container.appendChild(row);
}

function removeRow(button) {
    button.parentNode.remove();
}

function labelBobotJS(weight) {
    switch (parseInt(weight)) {
        case 4: return "Sangat Penting";
        case 3: return "Penting";
        case 2: return "Kurang Penting";
        default: return "Tidak Penting";
    }
}

document.addEventListener('DOMContentLoaded', function() {
    console.log(<?php echo json_encode($scheduledEvents); ?>);
});
</script>

</body>
</html>
