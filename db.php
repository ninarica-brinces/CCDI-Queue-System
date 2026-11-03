<?php
$host = getenv('DB_HOST');
$configuredPort = getenv('DB_PORT');
$port = $configuredPort === false || $configuredPort === '' ? 5432 : $configuredPort;
$dbname = getenv('DB_NAME');
$configuredSslmode = getenv('DB_SSLMODE');
$sslmode = $configuredSslmode === false || $configuredSslmode === '' ? 'require' : $configuredSslmode;
$user = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

$requiredConfig = [
    'DB_HOST' => $host,
    'DB_NAME' => $dbname,
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

if (filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
    throw new RuntimeException('DB_PORT must be a valid TCP port between 1 and 65535.');
}

if (!in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
    throw new RuntimeException('DB_SSLMODE must be a supported PostgreSQL SSL mode.');
}

foreach ([$host, (string) $port, $dbname, $sslmode] as $dsnValue) {
    if (str_contains($dsnValue, ';') || str_contains($dsnValue, "\0")) {
        throw new RuntimeException('Invalid PostgreSQL connection configuration.');
    }
}

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode";
$conn = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
