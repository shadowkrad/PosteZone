<?php
require_once 'db.php';

$rawText = isset($_POST['text']) ? trim($_POST['text']) : '';

if ($rawText === '') {
    echo json_encode(['success' => false, 'error' => 'Nessun testo fornito per la ricerca.']);
    exit;
}

// Log di debug disattivato di default: contiene indirizzi di destinatari (dati personali).
// Abilitabile con OCR_DEBUG_LOG=1; il file sta accanto al database, fuori dalla web root.
function ocrLog(string $message): void
{
    global $dbPath;
    if (getenv('OCR_DEBUG_LOG') !== '1') {
        return;
    }
    file_put_contents(dirname($dbPath) . '/ocr_debug.log', '[' . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND | LOCK_EX);
}

$textUpper = mb_strtoupper($rawText, 'UTF-8');
ocrLog("RAW OCR TEXT:\n$rawText");

/** Cerca $needle come parola/frase intera in $haystack. */
function containsWhole(string $haystack, string $needle): bool
{
    return $needle !== '' && preg_match('/(?<![A-Z0-9])' . preg_quote($needle, '/') . '(?![A-Z0-9])/', $haystack) === 1;
}

// Vie il cui nome compare nel testo (a parole intere: "VIA ROMA" non cattura "VIA ROMANA")
$stmt = $pdo->prepare("SELECT * FROM smistamento_vie WHERE INSTR(:text, via) > 0");
$stmt->execute(['text' => $textUpper]);
$rows = array_values(array_filter($stmt->fetchAll(), fn($r) => containsWhole($textUpper, $r['via'])));

if (empty($rows)) {
    ocrLog('MATCH RESULT: NO MATCH FOUND');
    echo json_encode(['success' => true, 'match' => false, 'message' => 'Nessun indirizzo riconosciuto nel testo.']);
    exit;
}

// Vince il nome di via piu' lungo (il piu' specifico)
$maxLen = max(array_map(fn($r) => strlen($r['via']), $rows));
$rows = array_values(array_filter($rows, fn($r) => strlen($r['via']) === $maxLen));
$viaName = $rows[0]['via'];

// Se piu' vie con lo stesso nome sono in comuni diversi, cerchiamo il comune nel testo
$places = distinctPlaces($rows);
if (count($places) > 1) {
    $textNoVia = str_replace($viaName, ' ', $textUpper);
    $detected = array_values(array_filter($places, fn($p) => containsWhole($textNoVia, $p['localita'])));
    if (count($detected) >= 1) {
        $keep = array_column($detected, 'localita');
        $rows = array_values(array_filter($rows, fn($r) => in_array($r['localita'], $keep, true)));
        $places = distinctPlaces($rows);
    }
}

// Civico dopo il nome della via
$civicoInt = null;
$pattern = '/(?<![A-Z0-9])' . preg_quote($viaName, '/') . '(?:\s*,\s*|\s+)(?:N\.?\s*|N°\s*)?(\d+)/';
if (preg_match($pattern, $textUpper, $matches)) {
    $civicoInt = intval($matches[1]);
}

if (count($places) > 1) {
    ocrLog('MATCH RESULT: AMBIGUOUS ' . json_encode($places, JSON_UNESCAPED_UNICODE));
    echo json_encode([
        'success' => true,
        'match' => false,
        'ambiguous' => true,
        'message' => 'Via presente in più comuni e comune non riconosciuto: seleziona quello corretto.',
        'options' => array_slice($places, 0, 30),
        'parsed_civico' => $civicoInt,
        'extracted_text' => $rawText
    ]);
    exit;
}

$matchedRow = pickRule($rows, $civicoInt);

if ($matchedRow) {
    if ($civicoInt !== null) {
        $matchedRow['parsed_civico'] = $civicoInt;
    }
    ocrLog('MATCH RESULT: ' . $matchedRow['via'] . ' (' . $matchedRow['localita'] . ') -> ' . $matchedRow['zona_sigla']);
    echo json_encode([
        'success' => true,
        'match' => true,
        'match_type' => 'ocr',
        'data' => $matchedRow,
        'extracted_text' => $rawText
    ]);
} else {
    ocrLog('MATCH RESULT: NO RULE COMPATIBLE');
    echo json_encode([
        'success' => true,
        'match' => false,
        'civico_required' => $civicoInt === null,
        'message' => $civicoInt === null
            ? 'Via riconosciuta ma civico non letto: inseriscilo a mano.'
            : 'Indirizzo parziale trovato ma nessuna regola compatibile.',
        'parsed_civico' => $civicoInt,
        'extracted_text' => $rawText
    ]);
}
