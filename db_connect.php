<?php
// db_connect.php

$envPath = '/home/raumhub/envs/farmer.env';
$env = parse_ini_file($envPath);

if ($env) {
    foreach ($env as $key => $value) {
        putenv("$key=$value");
    }

    $servername = getenv('DB_HOST');
    $username = getenv('DB_USER');
    $password = getenv('DB_PASS');
    $dbname = getenv('DB_NAME');

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $db = new mysqli($servername, $username, $password, $dbname);
        $db->set_charset("utf8mb4");
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        die("Connection failed.");
    }
} else {
    http_response_code(500);
    die("Error loading env file.");
}
?>
