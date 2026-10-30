<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Eseguire da riga di comando."); }
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../public/api/db.php';

// Ensure table exists and clear it
$pdo->exec("CREATE TABLE IF NOT EXISTS vie_incongruenti (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    excel_via TEXT NOT NULL,
    excel_tipo TEXT NULL,
    expected TEXT NOT NULL,
    zona TEXT NOT NULL,
    localita TEXT NOT NULL
);");

$pdo->exec("DELETE FROM vie_incongruenti;");
try { $pdo->exec("DELETE FROM sqlite_sequence WHERE name='vie_incongruenti'"); } catch (\Exception $ex) {}

$extractedDir = __DIR__ . '/extracted_xlsx';

if (!is_dir($extractedDir)) {
    die("Extracted folder not found at $extractedDir. Run unzip on host first.");
}

// Load Shared Strings
$sharedStrings = [];
$ssPath = $extractedDir . '/xl/sharedStrings.xml';
if (file_exists($ssPath)) {
    $xml = simplexml_load_file($ssPath);
    foreach ($xml->si as $si) {
        $text = '';
        if (isset($si->t)) {
            $text = (string)$si->t;
        } else {
            foreach ($si->r as $r) {
                if (isset($r->t)) {
                    $text .= (string)$r->t;
                }
            }
        }
        $sharedStrings[] = $text;
    }
}

function parseSheet($xmlPath, $sharedStrings) {
    if (!file_exists($xmlPath)) return [];
    $xml = simplexml_load_file($xmlPath);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $c) {
            $r = (string)$c['r'];
            $colLetter = preg_replace('/[0-9]/', '', $r);
            $val = '';
            if (isset($c->v)) {
                $rawVal = (string)$c->v;
                if (isset($c['t']) && (string)$c['t'] === 's') {
                    $idx = (int)$rawVal;
                    $val = isset($sharedStrings[$idx]) ? $sharedStrings[$idx] : '';
                } else {
                    $val = $rawVal;
                }
            }
            $rowData[$colLetter] = $val;
        }
        $rows[] = $rowData;
    }
    return $rows;
}

$sheet1Rows = parseSheet($extractedDir . '/xl/worksheets/sheet1.xml', $sharedStrings);
$sheet2Rows = parseSheet($extractedDir . '/xl/worksheets/sheet2.xml', $sharedStrings);

$typeMapping = [
    'VIA' => 'VIA',
    'VLO' => 'VICOLO',
    'VICOLO' => 'VICOLO',
    'PZ' => 'PIAZZA',
    'PIAZZA' => 'PIAZZA',
    'VLE' => 'VIALE',
    'VIALE' => 'VIALE',
    'CS' => 'CASE SPARSE',
    'NA' => 'NUCLEO ABITATO',
    'CA' => 'CENTRO ABITATO',
    'STR' => 'STRADA',
    'STRADA' => 'STRADA',
    'TRAV' => 'TRAVERSA',
    'TRAVERSA' => 'TRAVERSA',
    'RACC' => 'RACCORDO',
    'SP' => 'STRADA PROVINCIALE',
    'SR' => 'STRADA REGIONALE',
    'SCA' => 'SCALINATA',
    'SCALINATA' => 'SCALINATA',
    'VLO' => 'VICOLO',
    'PZA' => 'PIAZZA',
    'CSO' => 'CORSO',
    'CORSO' => 'CORSO'
];

function getWordSet($string) {
    $string = strtoupper($string);
    // Replace accents and punctuation with space
    $string = str_replace(["'", '-', ',', '.', '/', '(', ')', "\n", "\r"], ' ', $string);
    $string = preg_replace('/[^A-Z0-9\s]/', '', $string);
    $words = preg_split('/\s+/', $string, -1, PREG_SPLIT_NO_EMPTY);
    sort($words);
    return array_unique($words);
}

function matchWords($set1, $set2) {
    return count($set1) === count($set2) && array_diff($set1, $set2) === array_diff($set2, $set1);
}

// Fetch all DB records for Cortona and Foiano
$dbCortona = $pdo->query("SELECT * FROM smistamento_vie WHERE localita = 'CORTONA'")->fetchAll();
$dbFoiano = $pdo->query("SELECT * FROM smistamento_vie WHERE localita = 'FOIANO DELLA CHIANA'")->fetchAll();

echo "DB records: Cortona=" . count($dbCortona) . ", Foiano=" . count($dbFoiano) . "\n\n";

function processImport($rows, $municipalityName, $dbRecords, $typeMapping, $pdo) {
    $matchedCount = 0;
    $updatedCount = 0;
    $unmatched = [];
    
    // Index DB records by word sets for fast lookup
    $indexedDb = [];
    foreach ($dbRecords as $dbRec) {
        $wSet = getWordSet($dbRec['via']);
        $wKey = implode(' ', $wSet);
        $indexedDb[$wKey][] = $dbRec;
    }

    foreach ($rows as $index => $row) {
        // Skip header row or empty rows
        $rawVia = isset($row['A']) ? trim($row['A']) : '';
        if (empty($rawVia) || $rawVia === 'CORTONA' || $rawVia === 'FOIANO' || $rawVia === 'V/P') {
            continue;
        }
        
        $rawTipo = isset($row['B']) ? trim($row['B']) : '';
        $rawZona = isset($row['C']) ? trim($row['C']) : '';
        $rawLoc = isset($row['D']) ? trim($row['D']) : '';
        
        if (empty($rawZona)) {
            continue; // Skip if no zone is defined
        }
        
        // Normalize type
        $fullTipo = isset($typeMapping[strtoupper($rawTipo)]) ? $typeMapping[strtoupper($rawTipo)] : strtoupper($rawTipo);
        
        // Clean name (remove parenthesis and their contents)
        $cleanViaName = preg_replace('/\s*\(.*?\)\s*/', ' ', $rawVia);
        $cleanViaName = trim(preg_replace('/\s+/', ' ', $cleanViaName));
        
        // Expected DB name format: do not prepend type if clean name already starts with it
        if (!empty($fullTipo) && strpos(strtoupper($cleanViaName), $fullTipo) === 0) {
            $expectedVia = $cleanViaName;
        } else {
            $expectedVia = trim($fullTipo . ' ' . $cleanViaName);
        }
        
        $excelWordSet = getWordSet($expectedVia);
        $excelWordKey = implode(' ', $excelWordSet);
        
        // Find match by word set
        $matches = [];
        if (isset($indexedDb[$excelWordKey])) {
            $matches = $indexedDb[$excelWordKey];
        } else {
            // Check if any indexed DB keys have all the words
            foreach ($indexedDb as $dbKey => $recs) {
                $dbWordSet = explode(' ', $dbKey);
                if (count($excelWordSet) === count($dbWordSet)) {
                    if (count(array_diff($excelWordSet, $dbWordSet)) === 0) {
                        $matches = $recs;
                        break;
                    }
                }
            }
            
            // FALLBACK 1: If still no match, search for a DB record using stop-word filtered core words
            if (empty($matches) && !empty($cleanViaName)) {
                $stopWords = ['DEGLI', 'DEL', 'DELLA', 'DEI', 'DI', 'DA', 'IL', 'LA', 'L', 'VIA', 'VICOLO', 'PIAZZA', 'VIALE', 'CORSO', 'STRADA', 'SCALINATA', 'TRAVERSA', 'NUCLEO', 'ABITATO', 'CENTRO', 'CASE', 'SPARSE'];
                $excelCoreWords = array_diff(getWordSet($cleanViaName), $stopWords);
                
                $candidates = [];
                foreach ($dbRecords as $dbRec) {
                    $dbCoreWords = array_diff(getWordSet($dbRec['via']), $stopWords);
                    if (!empty($excelCoreWords) && count($excelCoreWords) === count($dbCoreWords) && count(array_diff($excelCoreWords, $dbCoreWords)) === 0) {
                        $candidates[] = $dbRec;
                    }
                }
                // If we found exactly one unique street record matching, treat it as a match
                if (count($candidates) === 1) {
                    $matches = $candidates;
                }
            }
        }
        
        if (!empty($matches)) {
            $matchedCount++;
            foreach ($matches as $dbRec) {
                // Update the zone in the database
                $stmt = $pdo->prepare("UPDATE smistamento_vie SET zona_sigla = :zona WHERE id = :id");
                $stmt->execute([
                    'zona' => strtoupper($rawZona),
                    'id' => $dbRec['id']
                ]);
                $updatedCount++;
            }
        } else {
            $unmatched[] = [
                'excel_via' => $rawVia,
                'excel_tipo' => $rawTipo,
                'expected' => $expectedVia,
                'zona' => $rawZona
            ];
        }
    }
    
    return [
        'matched' => $matchedCount,
        'updated' => $updatedCount,
        'unmatched' => $unmatched
    ];
}

echo "--- Processing Cortona ---\n";
$resCortona = processImport($sheet1Rows, 'CORTONA', $dbCortona, $typeMapping, $pdo);
echo "Matched Excel rows: " . $resCortona['matched'] . "\n";
echo "Updated DB rows: " . $resCortona['updated'] . "\n";
echo "Unmatched count: " . count($resCortona['unmatched']) . "\n";
if (!empty($resCortona['unmatched'])) {
    echo "First 10 unmatched examples:\n";
    for ($i = 0; $i < min(10, count($resCortona['unmatched'])); $i++) {
        $u = $resCortona['unmatched'][$i];
        echo " - Excel: '{$u['excel_via']}' ({$u['excel_tipo']}) => Expected: '{$u['expected']}'\n";
    }
}

$resFoiano = processImport($sheet2Rows, 'FOIANO DELLA CHIANA', $dbFoiano, $typeMapping, $pdo);

$allUnmatched = [
    'Cortona' => $resCortona['unmatched'],
    'Foiano' => $resFoiano['unmatched']
];
file_put_contents(__DIR__ . '/unmatched_streets.json', json_encode($allUnmatched, JSON_PRETTY_PRINT));
echo "\nSaved all unmatched streets to unmatched_streets.json\n";

$stmtInsert = $pdo->prepare("INSERT INTO vie_incongruenti (excel_via, excel_tipo, expected, zona, localita) VALUES (:excel_via, :excel_tipo, :expected, :zona, :localita)");
foreach ($allUnmatched as $loc => $items) {
    $fullLocName = ($loc === 'Cortona') ? 'CORTONA' : 'FOIANO DELLA CHIANA';
    foreach ($items as $item) {
        $stmtInsert->execute([
            'excel_via' => $item['excel_via'],
            'excel_tipo' => $item['excel_tipo'],
            'expected' => $item['expected'],
            'zona' => $item['zona'],
            'localita' => $fullLocName
        ]);
    }
}
echo "Populated vie_incongruenti table with " . (count($allUnmatched['Cortona']) + count($allUnmatched['Foiano'])) . " unmatched records.\n";
