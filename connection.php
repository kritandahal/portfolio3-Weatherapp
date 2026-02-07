<?php

$serverName = "sql208.infinityfree.com";
$userName = "if0_41002523";
$password = "AYfFegtlRN";
$dbName = "if0_41002523_weather";

// 1. Initial Connection (catch exceptions when MySQL is not available)
try {
    $conn = mysqli_connect($serverName, $userName, $password);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(["error" => "Database connection failed", "details" => $e->getMessage()]);
    exit();
}
if (!$conn) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(["error" => "Database connection failed", "details" => mysqli_connect_error()]);
    exit();
}

// 2. Create Database (if allowed)
$sqlCreateDB = "CREATE DATABASE IF NOT EXISTS `" . mysqli_real_escape_string($conn, $dbName) . "`";
mysqli_query($conn, $sqlCreateDB);
mysqli_select_db($conn, $dbName);

// 3. Create Table (with necessary fields and Timestamp)
$sqlCreateTable = "CREATE TABLE IF NOT EXISTS weather (
    city VARCHAR(100) PRIMARY KEY,
    temp FLOAT,
    weather_main VARCHAR(100),
    description VARCHAR(255),
    humidity INT,
    pressure INT,
    wind_speed FLOAT,
    wind_deg INT,
    icon VARCHAR(50),
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
if (!mysqli_query($conn, $sqlCreateTable)) {
    // non-fatal, but log for debugging
    error_log("Create table failed: " . mysqli_error($conn));
}

// 4. Handle Logic
// Allow overriding city via CLI for local testing: `php connection.php`
$cityName = isset($_GET['q']) && $_GET['q'] !== '' ? $_GET['q'] : "manchester";
if (php_sapi_name() === 'cli' && isset($argv) && isset($argv[1]) && $argv[1] !== '') {
    $cityName = $argv[1];
}
$apiKey = "8c4fe80da85e18c08ccbc2ffda011139";

// Escape input for SQL and URL-encode for API
$cityEscaped = mysqli_real_escape_string($conn, $cityName);
$cityForUrl = urlencode($cityName);

// Check if data exists and is less than 2 hours old
$checkData = "SELECT *, (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(last_updated)) as seconds_old FROM weather WHERE city = '$cityEscaped'";
$result = mysqli_query($conn, $checkData);
$row = $result ? mysqli_fetch_assoc($result) : null;

if (!$row || $row['seconds_old'] > 7200) {
    // Data not found OR older than 2 hours (7200 seconds)
    $apiUrl = "https://api.openweathermap.org/data/2.5/weather?units=metric&q={$cityForUrl}&appid={$apiKey}";

    $apiResponse = @file_get_contents($apiUrl);
    if ($apiResponse === false) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(["error" => "Failed to fetch weather data from API"]);
        exit();
    }

    $data = json_decode($apiResponse, true);
    if (!is_array($data) || empty($data) || isset($data['cod']) && (int)$data['cod'] !== 200) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(["error" => "City not found or API error", "api_response" => $data]);
        exit();
    }

    $city = isset($data['name']) ? $data['name'] : $cityName;
    $temp = isset($data['main']['temp']) ? (float)$data['main']['temp'] : null;
    $main = isset($data['weather'][0]['main']) ? $data['weather'][0]['main'] : null;
    $desc = isset($data['weather'][0]['description']) ? $data['weather'][0]['description'] : null;
    $hum = isset($data['main']['humidity']) ? (int)$data['main']['humidity'] : null;
    $pres = isset($data['main']['pressure']) ? (int)$data['main']['pressure'] : null;
    $ws = isset($data['wind']['speed']) ? (float)$data['wind']['speed'] : null;
    $wd = isset($data['wind']['deg']) ? (int)$data['wind']['deg'] : null;
    $icon = isset($data['weather'][0]['icon']) ? $data['weather'][0]['icon'] : null;

    // INSERT or UPDATE existing city data (use escaped values)
    $cityIns = mysqli_real_escape_string($conn, $city);
    $mainIns = mysqli_real_escape_string($conn, (string)$main);
    $descIns = mysqli_real_escape_string($conn, (string)$desc);
    $iconIns = mysqli_real_escape_string($conn, (string)$icon);

    $upsertQuery = "INSERT INTO weather (city, temp, weather_main, description, humidity, pressure, wind_speed, wind_deg, icon) VALUES ('$cityIns', " . ($temp !== null ? $temp : 'NULL') . ", '$mainIns', '$descIns', " . ($hum !== null ? $hum : 'NULL') . ", " . ($pres !== null ? $pres : 'NULL') . ", " . ($ws !== null ? $ws : 'NULL') . ", " . ($wd !== null ? $wd : 'NULL') . ", '$iconIns') ON DUPLICATE KEY UPDATE temp=VALUES(temp), weather_main=VALUES(weather_main), description=VALUES(description), humidity=VALUES(humidity), pressure=VALUES(pressure), wind_speed=VALUES(wind_speed), wind_deg=VALUES(wind_deg), icon=VALUES(icon), last_updated=NOW();";

    if (!mysqli_query($conn, $upsertQuery)) {
        $sqlError = mysqli_error($conn);
        error_log("Upsert failed: " . $sqlError);
        // If running in CLI, include SQL error in output for debugging
        if (php_sapi_name() === 'cli') {
            header('Content-Type: application/json');
            echo json_encode(["error" => "Upsert failed", "sql_error" => $sqlError, "query" => $upsertQuery]);
            exit();
        }
    }

    // Fetch the fresh record to return
    $result = mysqli_query($conn, "SELECT * FROM weather WHERE city = '$cityIns'");
    $row = $result ? mysqli_fetch_assoc($result) : null;
}

// Return data as JSON
header('Content-Type: application/json');
echo json_encode($row ?: ["error" => "No data available"]);
?>
