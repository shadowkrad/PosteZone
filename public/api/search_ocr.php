<?php
require_once 'db.php';

$rawText = isset($_POST['text']) ? trim($_POST['text']) : '';

// Scrivi nel file di log per scopi di debug
$logFile = __DIR__ . '/ocr_debug.log';
$timestamp = date('Y-m-d H:i:s');
file_put_contents($logFile, "[$timestamp] RAW OCR TEXT:\n$rawText\n-------------------------\n", FILE_APPEND);

if (empty($rawText)) {
    echo json_encode(['success' => false, 'error' => 'Nessun testo fornito per la ricerca.']);
    exit;
}

$textUpper = strtoupper($rawText);

// Trova tutte le vie il cui nome è contenuto nel testo OCR
$stmt = $pdo->prepare("SELECT * FROM smistamento_vie WHERE INSTR(:text, via) > 0");
$stmt->execute(['text' => $textUpper]);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    file_put_contents($logFile, "[$timestamp] MATCH RESULT: NO MATCH FOUND\n=========================\n", FILE_APPEND);
    echo json_encode(['success' => true, 'match' => false, 'message' => 'Nessun indirizzo riconosciuto nel testo.']);
    exit;
}

$matchedRow = null;
$bestMatch = null;

foreach ($rows as $row) {
    $viaName = strtoupper($row['via']);
    $escapedVia = preg_quote($viaName, '/');
    $pattern = '/' . $escapedVia . '(?:\s+|,\s*|(?:\s+N\.?\s*))(\d+)/i';
    
    $civicoInt = null;
    if (preg_match($pattern, $textUpper, $matches)) {
        $civicoInt = intval($matches[1]);
    }
    
    $tipo = strtoupper($row['tipo_civico']);
    
    if ($civicoInt !== null) {
        $isPari = ($civicoInt % 2 === 0);
        
        if ($tipo === 'PARI' && $isPari) {
            $matchedRow = $row;
            $matchedRow['parsed_civico'] = $civicoInt;
            break;
        } elseif ($tipo === 'DISPARI' && !$isPari) {
            $matchedRow = $row;
            $matchedRow['parsed_civico'] = $civicoInt;
            break;
        } elseif ($tipo === 'INTERVALLO') {
            $da = $row['da_civico'];
            $a = $row['a_civico'];
            if ($civicoInt >= $da && $civicoInt <= $a) {
                $matchedRow = $row;
                $matchedRow['parsed_civico'] = $civicoInt;
                break;
            }
        }
    }
    
    if ($tipo === 'TUTTI') {
        $bestMatch = $row;
        if ($civicoInt !== null) {
            $bestMatch['parsed_civico'] = $civicoInt;
        }
    }
}

if (!$matchedRow && $bestMatch) {
    $matchedRow = $bestMatch;
}

if (!$matchedRow && !empty($rows)) {
    $matchedRow = $rows[0];
}

if ($matchedRow) {
    $matchDetails = "MATCH: " . $matchedRow['via'] . " (" . $matchedRow['localita'] . ") -> " . $matchedRow['zona_sigla'];
    file_put_contents($logFile, "[$timestamp] MATCH RESULT: $matchDetails\n=========================\n", FILE_APPEND);
    
    echo json_encode([
        'success' => true,
        'match' => true,
        'match_type' => 'ocr',
        'data' => $matchedRow,
        'extracted_text' => $rawText
    ]);
} else {
    file_put_contents($logFile, "[$timestamp] MATCH RESULT: NO RULE COMPATIBLE\n=========================\n", FILE_APPEND);
    echo json_encode([
        'success' => true,
        'match' => false,
        'message' => 'Indirizzo parziale trovato ma nessuna regola compatibile.',
        'extracted_text' => $rawText
    ]);
}
