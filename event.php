<?php
// event.php

function createEvent($conn, $title, $date, $start_time, $duration) {
    $stmt = $conn->prepare("INSERT INTO events (title, date, start_time, duration) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", $title, $date, $start_time, $duration);
    return $stmt->execute();
}

function updateEvent($conn, $id, $title, $date, $start_time, $duration) {
    $stmt = $conn->prepare("UPDATE events SET title = ?, date = ?, start_time = ?, duration = ? WHERE id = ?");
    $stmt->bind_param("sssii", $title, $date, $start_time, $duration, $id);
    return $stmt->execute();
}

function deleteEvent($conn, $id) {
    $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
    $stmt->bind_param("i", $id);
    return $stmt->execute();
}

function getEventsByDate($conn, $date) {
    $stmt = $conn->prepare("SELECT * FROM events WHERE date = ? ORDER BY start_time ASC");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    return $stmt->get_result();
}

function getAllEvents($conn) {
    $result = $conn->query("SELECT * FROM events ORDER BY date ASC, start_time ASC");
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getEventsByMonthAndYear($conn, $month, $year) {
    $result = $conn->query("SELECT * FROM events WHERE MONTH(date) = $month AND YEAR(date) = $year");
    return $result->fetch_all(MYSQLI_ASSOC);
}
?>


