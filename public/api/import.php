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
    $pdo->beginTransaction();
    
    // Se richiesto, svuota la tabella prima dell'inserimento
    $clear = isset($_POST['clear']) && $_POST['clear'] === 'true';
    if ($clear) {
        $pdo->exec("DELETE FROM smistamento_vie");
        try { $pdo->exec("DELETE FROM sqlite_sequence WHERE name='smistamento_vie'"); } catch (\Exception $ex) {}
    }
    
    $stmt = $pdo->prepare("INSERT INTO smistamento_vie (via, localita, tipo_civico, da_civico, a_civico, zona_sigla, barcode) VALUES (:via, :localita, :tipo_civico, :da_civico, :a_civico, :zona_sigla, :barcode)");
    
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
        'message' => "Importazione completata con successo. Inseriti $imported record."
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
