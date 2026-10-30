<?php
// PosteZone - Database connection (SQLite)
header('Content-Type: application/json; charset=utf-8');

// Le API sono usate solo dalle pagine servite dallo stesso host: nessun CORS aperto.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
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

        -- Segnalazioni da far verificare a un operatore (conflitti nei dati, nomi simili, segnalazioni manuali)
        CREATE TABLE IF NOT EXISTS verifiche (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            via TEXT NOT NULL,
            localita TEXT NOT NULL,
            motivo TEXT NOT NULL,
            stato TEXT NOT NULL DEFAULT 'APERTA',
            creata_il TEXT DEFAULT CURRENT_TIMESTAMP,
            verificata_il TEXT DEFAULT NULL,
            UNIQUE (via, localita, motivo)
        );
        CREATE INDEX IF NOT EXISTS idx_verifiche_via ON verifiche(via, localita);
    ");
} catch (\PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Connessione al database SQLite fallita: ' . $e->getMessage()
    ]);
    exit;
}

/**
 * Sceglie la regola civico applicabile tra quelle di una stessa via/localita.
 * Le regole specifiche (PARI, DISPARI, INTERVALLO, PARI_INTERVALLO, DISPARI_INTERVALLO) hanno la precedenza su TUTTI.
 */
function pickRule(array $rows, ?int $civico): ?array
{
    $generic = null;
    foreach ($rows as $row) {
        $tipo = strtoupper($row['tipo_civico']);
        if ($tipo === 'TUTTI') {
            $generic = $generic ?? $row;
        } elseif ($civico !== null) {
            $inRange = $civico >= (int)$row['da_civico'] && $civico <= (int)$row['a_civico'];
            if (($tipo === 'PARI' && $civico % 2 === 0)
                || ($tipo === 'DISPARI' && $civico % 2 !== 0)
                || ($tipo === 'INTERVALLO' && $inRange)
                || ($tipo === 'PARI_INTERVALLO' && $inRange && $civico % 2 === 0)
                || ($tipo === 'DISPARI_INTERVALLO' && $inRange && $civico % 2 !== 0)) {
                return $row;
            }
        }
    }
    return $generic;
}

/** Motivi delle segnalazioni ancora aperte per una via. */
function openFlags(PDO $pdo, string $via, string $localita): array
{
    $stmt = $pdo->prepare("SELECT motivo FROM verifiche WHERE via = :via AND localita = :localita AND stato = 'APERTA' ORDER BY id");
    $stmt->execute(['via' => $via, 'localita' => $localita]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Coppie distinte via/localita presenti in un insieme di regole. */
function distinctPlaces(array $rows): array
{
    $places = [];
    foreach ($rows as $row) {
        $places[$row['via'] . '|' . $row['localita']] = ['via' => $row['via'], 'localita' => $row['localita']];
    }
    return array_values($places);
}
