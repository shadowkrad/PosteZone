<?php
require_once 'db.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$via = isset($_GET['via']) ? trim($_GET['via']) : '';
$civico = isset($_GET['civico']) ? trim($_GET['civico']) : '';

if (empty($q) && empty($via)) {
    echo json_encode(['success' => false, 'error' => 'Specificare una via o un codice di ricerca.']);
    exit;
}

// Se viene fornito "q", proviamo prima a cercarlo come codice a barre esatto
if (!empty($q)) {
    $stmt = $pdo->prepare("SELECT * FROM smistamento_vie WHERE barcode = :q LIMIT 1");
    $stmt->execute(['q' => $q]);
    $result = $stmt->fetch();
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'match' => true,
            'match_type' => 'barcode',
            'data' => $result
        ]);
        exit;
    }
    
    // Se non trova corrispondenza esatta di codice a barre, trattiamo "q" come ricerca per la via
    $via = $q;
}

// Ricerca per Via e Civico
$viaUpper = strtoupper($via);
$stmt = $pdo->prepare("SELECT * FROM smistamento_vie WHERE via LIKE :via");
$stmt->execute(['via' => "%$viaUpper%"]);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo json_encode(['success' => true, 'match' => false, 'message' => 'Nessuna via corrispondente trovata.']);
    exit;
}

$matchedRow = null;
$bestMatch = null;

if ($civico !== '') {
    $civicoInt = intval($civico);
    $isPari = ($civicoInt % 2 === 0);
    
    foreach ($rows as $row) {
        $tipo = strtoupper($row['tipo_civico']);
        
        if ($tipo === 'TUTTI') {
            $bestMatch = $row;
        } elseif ($tipo === 'PARI' && $isPari) {
            $matchedRow = $row;
            break;
        } elseif ($tipo === 'DISPARI' && !$isPari) {
            $matchedRow = $row;
            break;
        } elseif ($tipo === 'INTERVALLO') {
            $da = $row['da_civico'];
            $a = $row['a_civico'];
            if ($civicoInt >= $da && $civicoInt <= $a) {
                $matchedRow = $row;
                break;
            }
        }
    }
} else {
    // Se non viene specificato il civico, prendiamo la prima regola (TUTTI o generica)
    foreach ($rows as $row) {
        if (strtoupper($row['tipo_civico']) === 'TUTTI') {
            $bestMatch = $row;
            break;
        }
    }
    if (!$bestMatch) {
        $bestMatch = $rows[0];
    }
}

if (!$matchedRow && $bestMatch) {
    $matchedRow = $bestMatch;
}

if ($matchedRow) {
    echo json_encode([
        'success' => true,
        'match' => true,
        'match_type' => 'address',
        'data' => $matchedRow
    ]);
} else {
    echo json_encode([
        'success' => true,
        'match' => false,
        'message' => 'Via trovata ma nessuna regola compatibile per il civico.',
        'suggestions' => $rows
    ]);
}
