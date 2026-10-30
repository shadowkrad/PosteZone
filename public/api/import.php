<?php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito.']);
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Errore nel caricamento del file.']);
    exit;
}

$fileTmpPath = $_FILES['csv_file']['tmp_name'];

// Leggi le prime righe per rilevare il delimitatore
$file = fopen($fileTmpPath, 'r');
if (!$file) {
    echo json_encode(['success' => false, 'error' => 'Impossibile aprire il file temporaneo.']);
    exit;
}

$firstLine = fgets($file);
fclose($file);

$delimiter = ',';
if (strpos($firstLine, ';') !== false) {
    $delimiter = ';';
}

$file = fopen($fileTmpPath, 'r');
// Salta l'intestazione se presente (proviamo a rilevare se contiene parole chiave)
$header = fgetcsv($file, 0, $delimiter);
$hasHeader = false;

if ($header) {
    $headerLower = array_map('strtolower', $header);
    if (in_array('via', $headerLower) || in_array('zona_sigla', $headerLower) || in_array('sigla_zona', $headerLower)) {
        $hasHeader = true;
    } else {
        // Se non è un header, rimettiamo il puntatore all'inizio
        rewind($file);
    }
}

try {
    $clear = isset($_POST['clear']) && $_POST['clear'] === 'true';
    $replace = isset($_POST['replace']) && $_POST['replace'] === 'true';

    // Copia di sicurezza prima di modificare lo stradario: se fallisce, non si importa nulla
    $backupDir = dirname($dbPath) . '/backups';
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true)) {
        throw new \Exception('Impossibile creare la cartella dei backup.');
    }
    $backupFile = $backupDir . '/pre-import-' . date('Ymd-His') . '.db';
    $pdo->exec("VACUUM INTO " . $pdo->quote($backupFile));

    $pdo->beginTransaction();
    
    // Se richiesto, svuota la tabella prima dell'inserimento
    if ($clear) {
        $pdo->exec("DELETE FROM smistamento_vie");
        try { $pdo->exec("DELETE FROM sqlite_sequence WHERE name='smistamento_vie'"); } catch (\Exception $ex) {}
    }
    
    $stmt = $pdo->prepare("INSERT INTO smistamento_vie (via, localita, tipo_civico, da_civico, a_civico, zona_sigla, barcode) VALUES (:via, :localita, :tipo_civico, :da_civico, :a_civico, :zona_sigla, :barcode)");
    
    // Modalità "sostituisci": elimina le regole esistenti delle sole vie presenti nel file
    $replaced = 0;
    if ($replace && !$clear) {
        $keys = [];
        $scan = fopen($fileTmpPath, 'r');
        if ($hasHeader) {
            fgetcsv($scan, 0, $delimiter);
        }
        while (($r = fgetcsv($scan, 0, $delimiter)) !== FALSE) {
            if (count($r) >= 6 && trim($r[0]) !== '' && trim($r[5]) !== '') {
                $keys[strtoupper(trim($r[0])) . "|" . strtoupper(trim($r[1]))] = [strtoupper(trim($r[0])), strtoupper(trim($r[1]))];
            }
        }
        fclose($scan);

        $del = $pdo->prepare("DELETE FROM smistamento_vie WHERE via = :via AND localita = :localita");
        foreach ($keys as [$k_via, $k_loc]) {
            $del->execute(['via' => $k_via, 'localita' => $k_loc]);
            $replaced += $del->rowCount();
        }
    }

    $imported = 0;
    while (($row = fgetcsv($file, 0, $delimiter)) !== FALSE) {
        // Assicuriamoci che ci siano abbastanza colonne (almeno via, localita, zona_sigla)
        if (count($row) < 3) {
            continue;
        }
        
        $via = trim($row[0]);
        $localita = trim($row[1]);
        $tipo_civico = isset($row[2]) && !empty(trim($row[2])) ? strtoupper(trim($row[2])) : 'TUTTI';
        $da_civico = isset($row[3]) && trim($row[3]) !== '' ? intval(trim($row[3])) : null;
        $a_civico = isset($row[4]) && trim($row[4]) !== '' ? intval(trim($row[4])) : null;
        $zona_sigla = trim($row[5]);
        $barcode = isset($row[6]) && trim($row[6]) !== '' ? trim($row[6]) : null;
        
        if (empty($via) || empty($zona_sigla)) {
            continue;
        }
        
        $stmt->execute([
            'via' => strtoupper($via),
            'localita' => strtoupper($localita),
            'tipo_civico' => $tipo_civico,
            'da_civico' => $da_civico,
            'a_civico' => $a_civico,
            'zona_sigla' => strtoupper($zona_sigla),
            'barcode' => $barcode
        ]);
        $imported++;
    }
    
    fclose($file);
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Importazione completata con successo. Inseriti $imported record"
            . ($replace && !$clear ? ", sostituite $replaced regole esistenti" : '')
            . ". Copia di sicurezza: " . basename($backupFile) . "."
    ]);
    
} catch (\Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Errore durante l\'importazione: ' . $e->getMessage()
    ]);
}
