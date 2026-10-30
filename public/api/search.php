<?php
require_once 'db.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$via = isset($_GET['via']) ? trim($_GET['via']) : '';
$localita = isset($_GET['localita']) ? mb_strtoupper(trim($_GET['localita']), 'UTF-8') : '';
$civico = isset($_GET['civico']) ? trim($_GET['civico']) : '';

if ($q === '' && $via === '') {
    echo json_encode(['success' => false, 'error' => 'Specificare una via o un codice di ricerca.']);
    exit;
}

// Se viene fornito "q", proviamo prima a cercarlo come codice a barre esatto
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM smistamento_vie WHERE barcode = :q LIMIT 1");
    $stmt->execute(['q' => $q]);
    $result = $stmt->fetch();

    if ($result) {
        $result['verifiche'] = openFlags($pdo, $result['via'], $result['localita']);
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

$viaUpper = mb_strtoupper($via, 'UTF-8');
$locFilter = $localita !== '' ? ' AND localita = :localita' : '';

// Prima corrispondenza esatta sul nome della via, poi ricerca parziale come ripiego
$params = ['via' => $viaUpper];
if ($localita !== '') {
    $params['localita'] = $localita;
}
$stmt = $pdo->prepare("SELECT * FROM smistamento_vie WHERE via = :via$locFilter ORDER BY localita, id");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    $params['via'] = "%$viaUpper%";
    $stmt = $pdo->prepare("SELECT * FROM smistamento_vie WHERE via LIKE :via$locFilter ORDER BY localita, via, id");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

if (empty($rows)) {
    echo json_encode(['success' => true, 'match' => false, 'message' => 'Nessuna via corrispondente trovata.']);
    exit;
}

// La stessa via puo' esistere in piu' comuni: non scegliamo a caso, chiediamo di specificare
$places = distinctPlaces($rows);
if (count($places) > 1) {
    echo json_encode([
        'success' => true,
        'match' => false,
        'ambiguous' => true,
        'message' => 'Più vie corrispondenti: seleziona il comune.',
        'options' => array_slice($places, 0, 30)
    ]);
    exit;
}

$civicoInt = $civico !== '' ? intval($civico) : null;
$matchedRow = pickRule($rows, $civicoInt);

if ($matchedRow) {
    $matchedRow['verifiche'] = openFlags($pdo, $matchedRow['via'], $matchedRow['localita']);
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
        'civico_required' => $civicoInt === null,
        'message' => $civicoInt === null
            ? 'La via ha zone diverse per civico: specifica il numero civico.'
            : 'Via trovata ma nessuna regola compatibile per il civico.',
        'suggestions' => $rows
    ]);
}
