<?php
// db.php - Database connection and query handling

$host = 'localhost'; // Database host
$user = 'root'; // Database username
$password = ''; // Database password
$database = 'penjadwalan'; // Database name

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to execute a query and return results
function executeQuery($conn, $query, $params = [], $types = '') {
    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

$month = date('m');
$year = date('Y');
$result = $conn->query("SELECT * FROM events WHERE MONTH(date) = $month AND YEAR(date) = $year");

// Close connection when done
function closeConnection($conn) {
    $conn->close();
}
?>