<?php
// PosteZone - Database connection (SQLite)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Support DB_PATH env var, fallback to /data/postezone.db or local directory
$dbPath = getenv('DB_PATH') ?: (file_exists('/data') ? '/data/postezone.db' : __DIR__ . '/../../data/postezone.db');

$dir = dirname($dbPath);
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}

$dsn = "sqlite:" . $dbPath;
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, null, null, $options);
    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("PRAGMA synchronous = NORMAL;");
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // Ensure core schema exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS smistamento_vie (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            via TEXT NOT NULL,
            localita TEXT NOT NULL,
            tipo_civico TEXT NOT NULL DEFAULT 'TUTTI',
            da_civico INTEGER DEFAULT NULL,
            a_civico INTEGER DEFAULT NULL,
            zona_sigla TEXT NOT NULL,
            barcode TEXT DEFAULT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_via ON smistamento_vie(via);
        CREATE INDEX IF NOT EXISTS idx_localita ON smistamento_vie(localita);
        CREATE INDEX IF NOT EXISTS idx_barcode ON smistamento_vie(barcode);

        CREATE TABLE IF NOT EXISTS vie_incongruenti (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            excel_via TEXT NOT NULL,
            excel_tipo TEXT DEFAULT NULL,
            expected TEXT NOT NULL,
            zona TEXT NOT NULL,
            localita TEXT NOT NULL
        );
    ");
} catch (\PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Connessione al database SQLite fallita: ' . $e->getMessage()
    ]);
    exit;
}
