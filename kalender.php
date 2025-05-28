<?php
// Pastikan variabel bulan/tahun sudah ada
if (!isset($currentMonth) || !isset($currentYear)) {
    $currentDate = new DateTime();
    $currentMonth = $currentDate->format('m');
    $currentYear = $currentDate->format('Y');
}

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

if (!isset($events)) {
    // Agar bisa include langsung, ambil bulan/tahun sekarang jika variabel belum ada
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);
    $firstDayOfMonth = new DateTime("{$currentYear}-{$currentMonth}-01");
    $firstDayOfWeek = $firstDayOfMonth->format('w');
    $events = [];
}
$today = (new DateTime())->format('Y-m-d');
?>

<div class="calendar-nav">
    <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>">&laquo; Sebelumnya</a>
    <span><b><?php echo date("F Y", strtotime("$currentYear-$currentMonth-01")); ?></b></span>
    <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>">Berikutnya &raquo;</a>
</div>
<div class="calendar">
<?php
echo '<table class="calendar-table">';
echo '<tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr><tr>';

// Kolom kosong sebelum hari pertama bulan
for ($i = 0; $i < $firstDayOfWeek; $i++) {
    echo '<td></td>';
}

for ($day = 1; $day <= $daysInMonth; $day++) {
    $dateStr = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
    $isToday = ($dateStr == $today);

    // Siapkan data event untuk tanggal ini
    $eventList = [];
    foreach ($events as $ev) {
        if ($ev['date'] === $dateStr) {
            $eventList[] = [
                'id' => $ev['id'],
                'title' => htmlspecialchars($ev['title']),
                'start_time' => htmlspecialchars($ev['start_time']),
                'duration' => htmlspecialchars($ev['duration']),
                'weight' => $ev['weight'], 
                'hasPassed' => $ev['hasPassed'] ? 1 : 0
            ];
        }
    }
    $eventData = htmlspecialchars(json_encode($eventList), ENT_QUOTES, 'UTF-8');

    // Baris baru setiap minggu
    if (($firstDayOfWeek + $day - 1) % 7 == 0 && $day != 1) {
        echo '</tr><tr>';
    }

    echo '<td' . ($isToday ? ' class="today"' : '') . '>';
    // Jadikan tanggal sebagai tombol
    echo '<button class="calendar-btn" data-date="' . $dateStr . '" data-events="' . $eventData . '">';
    echo '<span class="day-number">' . $day . '</span>';
    echo '</button>';
    echo '</td>';
}

// Kolom kosong setelah hari terakhir bulan
$remainingCols = (7 - (($daysInMonth + $firstDayOfWeek) % 7)) % 7;
for ($i = 0; $i < $remainingCols; $i++) {
    echo '<td></td>';
}

echo '</tr></table>';
?>
</div>