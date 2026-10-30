<?php
$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

$requiredConfig = [
    'DB_HOST' => $host,
    'DB_USER' => $user,
    'DB_PASSWORD' => $password,
];
$missingConfig = array_filter(
    $requiredConfig,
    static fn($value) => $value === false || $value === ''
);

if ($missingConfig) {
    throw new RuntimeException(
        'Missing required database configuration: ' . implode(', ', array_keys($missingConfig))
    );
}

$port = getenv('DB_PORT');
$dbname = getenv('DB_NAME');
$sslmode = getenv('DB_SSLMODE');
$port = $port !== false && $port !== '' ? $port : '5432';
$dbname = $dbname !== false && $dbname !== '' ? $dbname : 'postgres';
$sslmode = $sslmode !== false && $sslmode !== '' ? $sslmode : 'require';

foreach ([$host, $port, $dbname, $sslmode] as $dsnValue) {
    if (str_contains($dsnValue, ';') || str_contains($dsnValue, "\0")) {
        throw new RuntimeException('Invalid PostgreSQL connection configuration.');
    }
}

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode";
$conn = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
?>
