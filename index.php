<?php
session_start();
include("db.php");

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Fungsi bantu untuk membandingkan dua interval waktu
function isOverlap($start1, $end1, $start2, $end2) {
    return $start1 < $end2 && $end1 > $start2;
}

// Fungsi untuk cek bentrok dengan pendekatan linear (lebih akurat)
function isConflict($conn, $date, $start_time, $duration, $exclude_id = null) {
    // Ambil semua event pada tanggal yang sama
    $sql = "SELECT id, start_time, duration, weight, created_at FROM events WHERE date = ?";
    if ($exclude_id) {
        $sql .= " AND id != ?";
    }
    $sql .= " ORDER BY created_at DESC"; // Prioritaskan yang lebih baru
    $stmt = $conn->prepare($sql);
    if ($exclude_id) {
        $stmt->bind_param("si", $date, $exclude_id);
    } else {
        $stmt->bind_param("s", $date);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $events = $result->fetch_all(MYSQLI_ASSOC);

    // Buat DateTime object untuk event yang akan dicek
    $new_start = new DateTime($start_time);
    $new_end = clone $new_start;
    $new_end->modify("+{$duration} minutes");

    $conflicts = [];
    foreach ($events as $event) {
        $existing_start = new DateTime($event['start_time']);
        $existing_end = clone $existing_start;
        $existing_end->modify("+{$event['duration']} minutes");

        if (isOverlap($new_start, $new_end, $existing_start, $existing_end)) {
            $conflicts[] = $event;
        }
    }

    return $conflicts;
}

// Fungsi untuk cari slot kosong dengan Divide and Conquer
function getNextAvailableSlot($conn, $date, $duration) {
    // Ambil semua event pada tanggal yang sama, diurutkan berdasarkan waktu mulai
    $stmt = $conn->prepare("SELECT start_time, duration FROM events WHERE date = ? ORDER BY start_time ASC, created_at DESC");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $events = $result->fetch_all(MYSQLI_ASSOC);

    // Batas jam operasional (07:00 - 21:00)
    $start_of_day = new DateTime("07:00");
    $end_of_day = new DateTime("21:00");

    // Jika tidak ada event sama sekali
    if (empty($events)) {
        $available_end = clone $start_of_day;
        $available_end->modify("+{$duration} minutes");
        if ($available_end <= $end_of_day) {
            return $start_of_day->format('H:i');
        }
        return null;
    }

    // Buat array slot yang sudah terisi
    $busy_slots = [];
    foreach ($events as $event) {
        $start = new DateTime($event['start_time']);
        $end = clone $start;
        $end->modify("+{$event['duration']} minutes");
        $busy_slots[] = ['start' => $start, 'end' => $end];
    }

    // Fungsi rekursif untuk mencari slot dengan Divide and Conquer
    function findAvailableSlot($busy_slots, $duration, $start_search, $end_search, $left, $right) {
        if ($left > $right) {
            // Cek slot antara end_search dan end_of_day
            $last_end = $right >= 0 ? $busy_slots[$right]['end'] : $start_search;
            $potential_start = $last_end;
            $potential_end = clone $potential_start;
            $potential_end->modify("+{$duration} minutes");
            
            if ($potential_end <= $end_search) {
                return $potential_start->format('H:i');
            }
            return null;
        }

        $mid = intdiv($left + $right, 2);
        $current_start = $busy_slots[$mid]['start'];
        $current_end = $busy_slots[$mid]['end'];

        // Cek slot sebelum current event
        $prev_end = $mid > 0 ? $busy_slots[$mid - 1]['end'] : $start_search;
        $gap_start = $prev_end;
        $gap_end = $current_start;

        $gap_minutes = ($gap_end->getTimestamp() - $gap_start->getTimestamp()) / 60;
        if ($gap_minutes >= $duration) {
            return $gap_start->format('H:i');
        }

        // Cari di sebelah kiri
        $left_result = findAvailableSlot($busy_slots, $duration, $start_search, $end_search, $left, $mid - 1);
        if ($left_result !== null) {
            return $left_result;
        }

        // Cari di sebelah kanan
        return findAvailableSlot($busy_slots, $duration, $start_search, $end_search, $mid + 1, $right);
    }

    // Mulai pencarian
    return findAvailableSlot($busy_slots, $duration, $start_of_day, $end_of_day, 0, count($busy_slots) - 1);
}

// Proses form tambah
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm_replace'])) {
        // Proses konfirmasi penggantian jadwal
        $replace_decision = $_POST['replace_decision'];
        $new_event = $_SESSION['pending_event'];
        
        foreach ($replace_decision as $event_id => $decision) {
            if ($decision === 'replace') {
                // Hapus event yang lama
                $conn->query("DELETE FROM events WHERE id = $event_id");
                
                // Simpan event baru
                $stmt = $conn->prepare("INSERT INTO events (title, date, start_time, duration, weight) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssii", $new_event['title'], $new_event['date'], $new_event['start'], $new_event['duration'], $new_event['weight']);
                $stmt->execute();
                
                $_SESSION['messages'][] = "Jadwal '<strong>{$new_event['title']}</strong>' telah menggantikan jadwal yang lebih rendah prioritasnya.";
            } else {
                $_SESSION['messages'][] = "Jadwal '<strong>{$new_event['title']}</strong>' tidak disimpan karena Anda memilih untuk tidak menggantikan jadwal yang ada.";
            }
        }
        
        unset($_SESSION['pending_event']);
        unset($_SESSION['conflicting_events']);
        header("Location: index.php");
        exit;
    } elseif (isset($_POST['bulk_title'])) {
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
            // Urutkan: bobot tertinggi dulu, lalu waktu mulai, lalu urutan input (yang baru lebih tinggi)
            usort($events, function($a, $b) {
                if ($b['weight'] !== $a['weight']) {
                    return $b['weight'] - $a['weight'];
                }
                if ($a['start_dt'] != $b['start_dt']) {
                    return $a['start_dt'] <=> $b['start_dt'];
                }
                return $b['index'] - $a['index']; // Yang lebih baru (index lebih besar) lebih tinggi
            });

            $chosen = [];
            foreach ($events as $ev) {
                $bentrok = false;
                foreach ($chosen as $sel) {
                    if (isOverlap($ev['start_dt'], $ev['end_dt'], $sel['start_dt'], $sel['end_dt'])) {
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
            
            // Cek konflik dengan database
            $conflicts = isConflict($conn, $ev['date'], $ev['start'], $ev['duration']);
            
            if (!empty($conflicts)) {
                $hasHigherPriority = false;
                $conflictingEvents = [];
                
                foreach ($conflicts as $conflict) {
                    if ($ev['weight'] > $conflict['weight']) {
                        $hasHigherPriority = true;
                        $conflictingEvents[] = $conflict;
                    } elseif ($ev['weight'] == $conflict['weight']) {
                        // Jika prioritas sama, otomatis ganti dengan yang baru
                        $hasHigherPriority = true;
                        $conflictingEvents[] = $conflict;
                    }
                }
                
                if ($hasHigherPriority) {
                    // Simpan event yang akan ditambahkan di session
                    $_SESSION['pending_event'] = $ev;
                    $_SESSION['conflicting_events'] = $conflictingEvents;
                    $_SESSION['messages'] = $messages;
                    
                    // Redirect ke halaman konfirmasi
                    header("Location: confirm_replace.php");
                    exit;
                } else {
                    $messages[] = "Jadwal '<strong>{$ev['title']}</strong>' bentrok dengan jadwal di database dan tidak disimpan karena prioritas lebih rendah.";
                }
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
}

// Hapus event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event_id'])) {
    $id = intval($_POST['delete_event_id']);
    $conn->query("DELETE FROM events WHERE id = $id");
    header("Location: index.php");
    exit;
}

// Hapus event yang sudah lewat dan beri notifikasi
$now = new DateTime();
$deletedPastEvents = [];
$result = $conn->query("SELECT id, title FROM events WHERE STR_TO_DATE(CONCAT(date, ' ', start_time), '%Y-%m-%d %H:%i') + INTERVAL duration MINUTE < '".$now->format('Y-m-d H:i:s')."'");
while ($row = $result->fetch_assoc()) {
    $deletedPastEvents[] = $row['title'];
    $conn->query("DELETE FROM events WHERE id = ".$row['id']);
}

if (!empty($deletedPastEvents)) {
    foreach ($deletedPastEvents as $eventTitle) {
        $_SESSION['messages'][] = "Jadwal '<strong>$eventTitle</strong>' telah dihapus karena sudah lewat.";
    }
}

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
$result = $conn->query("SELECT * FROM events WHERE MONTH(date) = $currentMonth AND YEAR(date) = $currentYear ORDER BY date ASC, start_time ASC, created_at DESC");
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
    foreach ($events as $i => &$ev) {
        $ev['weight'] = (int)$ev['weight'];
        $ev['input_order'] = $i; // Simpan urutan input sebagai penentu
    }
    unset($ev);

    // Urutkan berdasarkan: 
    // 1. Waktu selesai 
    // 2. Jika sama, urutkan berdasarkan input_order (yang lebih baru/akhir lebih tinggi)
    usort($events, function($a, $b) {
        $a_end = (new DateTime($a['start_time']))->modify("+{$a['duration']} minutes");
        $b_end = (new DateTime($b['start_time']))->modify("+{$b['duration']} minutes");
        
        if ($a_end == $b_end) {
            return $b['input_order'] - $a['input_order']; // Yang lebih baru diprioritaskan
        }
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

// Cek jadwal yang akan dimulai dalam 15 menit
$upcomingEvents = [];
$now = new DateTime();
$fifteenMinutesLater = clone $now;
$fifteenMinutesLater->modify('+15 minutes');

foreach ($scheduledEvents as $event) {
    if ($event['hasPassed']) continue;
    
    $eventDateTime = new DateTime($event['date'] . ' ' . $event['start_time']);
    if ($eventDateTime > $now && $eventDateTime <= $fifteenMinutesLater) {
        $upcomingEvents[] = $event;
    }
}

// Cek jadwal untuk pengingat harian (1 hari sebelum)
$dailyReminderEvents = [];
$tomorrow = clone $now;
$tomorrow->modify('+1 day');
$tomorrowDate = $tomorrow->format('Y-m-d');

foreach ($scheduledEvents as $event) {
    if ($event['date'] === $tomorrowDate && !$event['hasPassed']) {
        $dailyReminderEvents[] = $event;
    }
}

// Cek jadwal yang akan datang dalam 1 hari (untuk notifikasi)
$oneDayEvents = [];
$oneDayLater = clone $now;
$oneDayLater->modify('+1 day');

foreach ($scheduledEvents as $event) {
    if ($event['hasPassed']) continue;
    
    $eventDateTime = new DateTime($event['date'] . ' ' . $event['start_time']);
    $timeDiff = $eventDateTime->diff($now);
    
    // Jika jadwal kurang dari atau sama dengan 1 hari lagi
    if ($timeDiff->days <= 1 && $eventDateTime > $now) {
        $oneDayEvents[] = $event;
    }
}

$dataEvents = json_encode($scheduledEvents, JSON_NUMERIC_CHECK);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Penjadwalan Multi-Acara (Divide and Conquer)</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Notification Styles */
        #upcomingNotifications {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 300px;
        }
        
        .notification {
            padding: 15px;
            margin-bottom: 10px;
            color: white;
            border-radius: 4px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: opacity 0.5s ease;
            opacity: 1;
        }
        
        .notification.reminder {
            background-color: #4CAF50;
        }
        
        .notification.daily {
            background-color: #2196F3;
        }
        
        .notification.error {
            background-color: #f44336;
        }
        
        .notification.warning {
            background-color: #ff9800;
        }
        
        .notification-content {
            display: flex;
            align-items: center;
        }
        
        .notification i {
            margin-right: 10px;
            font-size: 20px;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 5px;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
        }
        
        /* Replace Confirmation Styles */
        .conflict-event {
            background-color: #fff3cd;
            padding: 10px;
            margin-bottom: 10px;
            border-left: 4px solid #ffc107;
        }
        
        .new-event {
            background-color: #d4edda;
            padding: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .radio-option {
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <!-- Notifikasi jadwal akan dimulai -->
    <div id="upcomingNotifications"></div>

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
    // Fungsi untuk menampilkan notifikasi
    function showNotification(title, message, type = 'reminder') {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <strong>${title}</strong> - ${message}
            </div>
        `;
        
        const container = document.getElementById('upcomingNotifications');
        container.appendChild(notification);
        
        // Hilangkan notifikasi setelah waktu tertentu
        let timeout = 5000; // 5 detik default
        if (type === 'reminder') timeout = 300000; // 5 menit untuk pengingat
        if (type === 'daily') timeout = 60000; // 1 menit untuk pengingat harian
        
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => {
                container.removeChild(notification);
            }, 500);
        }, timeout);
    }

    // Tampilkan notifikasi untuk jadwal yang akan datang dalam 1 hari
    function showOneDayNotifications() {
        const oneDayEvents = <?php echo json_encode($oneDayEvents); ?>;
        const now = new Date();
        
        oneDayEvents.forEach(event => {
            const eventDate = new Date(event.date + 'T' + event.start_time);
            const timeDiff = (eventDate - now) / 1000 / 60 / 60; // Selisih dalam jam
            
            // Hanya tampilkan jika belum pernah ditampilkan untuk event ini
            if (!localStorage.getItem(`oneday_notif_${event.id}`)) {
                let timeLeft = '';
                if (timeDiff < 1) {
                    // Kurang dari 1 jam
                    const minutes = Math.floor(timeDiff * 60);
                    timeLeft = `${minutes} menit lagi`;
                } else if (timeDiff < 24) {
                    // Kurang dari 24 jam
                    const hours = Math.floor(timeDiff);
                    const minutes = Math.floor((timeDiff - hours) * 60);
                    timeLeft = `${hours} jam ${minutes} menit lagi`;
                } else {
                    // 1 hari tepat
                    timeLeft = "1 hari lagi";
                }
                
                showNotification(
                    'Pengingat Jadwal', 
                    `${event.title} akan dimulai pada ${event.start_time} (${timeLeft})`,
                    'reminder'
                );
                
                // Simpan flag di localStorage
                localStorage.setItem(`oneday_notif_${event.id}`, 'shown');
                
                // Hapus flag setelah event lewat
                const timeUntilEvent = eventDate - now;
                setTimeout(() => {
                    localStorage.removeItem(`oneday_notif_${event.id}`);
                }, timeUntilEvent + 60000); // 1 menit setelah event
            }
        });
    }

    // Cek pengingat jadwal
    function checkReminders() {
        const upcomingEvents = <?php echo json_encode($upcomingEvents); ?>;
        const dailyReminders = <?php echo json_encode($dailyReminderEvents); ?>;
        const now = new Date();
        
        // Pengecekan jadwal 15 menit lagi
        upcomingEvents.forEach(event => {
            const eventDate = new Date(event.date + 'T' + event.start_time);
            const timeDiff = (eventDate - now) / 1000 / 60; // Selisih dalam menit
            
            if (timeDiff > 0 && timeDiff <= 15) {
                if (!localStorage.getItem(`notif_15min_${event.id}`)) {
                    showNotification(
                        'Pengingat 15 Menit', 
                        `${event.title} akan dimulai pada ${event.start_time}`,
                        'reminder'
                    );
                    localStorage.setItem(`notif_15min_${event.id}`, 'shown');
                    
                    // Hapus flag setelah event lewat
                    setTimeout(() => {
                        localStorage.removeItem(`notif_15min_${event.id}`);
                    }, (timeDiff * 60 * 1000) + 60000);
                }
            }
        });
        
        // Pengecekan pengingat harian
        dailyReminders.forEach(event => {
            if (!localStorage.getItem(`daily_reminder_${event.id}`)) {
                showNotification(
                    'Pengingat Harian', 
                    `Besok ada acara ${event.title} pukul ${event.start_time}`,
                    'daily'
                );
                localStorage.setItem(`daily_reminder_${event.id}`, 'shown');
                
                // Hapus flag setelah 24 jam
                setTimeout(() => {
                    localStorage.removeItem(`daily_reminder_${event.id}`);
                }, 24 * 60 * 60 * 1000);
            }
        });
    }

    // Jalankan pengecekan saat halaman dimuat
    document.addEventListener('DOMContentLoaded', function() {
        // Tampilkan notifikasi penghapusan jika ada
        <?php if (!empty($deletedPastEvents)): ?>
            <?php foreach ($deletedPastEvents as $eventTitle): ?>
                showNotification('Jadwal Dihapus', '<?php echo addslashes($eventTitle); ?> telah dihapus karena sudah lewat', 'error');
            <?php endforeach; ?>
        <?php endif; ?>

        // Tampilkan notifikasi untuk jadwal 1 hari ke depan
        showOneDayNotifications();
        
        // Jalankan pengecekan reminder
        checkReminders();
        
        // Set interval untuk pengecekan reminder (setiap 1 menit)
        setInterval(checkReminders, 60000);

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

    function closeModal() {
        document.getElementById('eventModal').style.display = "none";
    }
    </script>
</body>
</html>