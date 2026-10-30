<?php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito.']);
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$zonaSigla = isset($_POST['zona_sigla']) ? trim($_POST['zona_sigla']) : '';

if ($id <= 0 || empty($zonaSigla)) {
    echo json_encode(['success' => false, 'error' => 'Dati incompleti per l\'assegnazione della zona.']);
    exit;
}

$zonaSiglaUpper = strtoupper($zonaSigla);

try {
    $stmt = $pdo->prepare("UPDATE smistamento_vie SET zona_sigla = :zona_sigla WHERE id = :id");
    $stmt->execute([
        'zona_sigla' => $zonaSiglaUpper,
        'id' => $id
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Zona di smistamento aggiornata con successo.',
        'new_zona' => $zonaSiglaUpper
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Errore durante l\'aggiornamento del database: ' . $e->getMessage()
    ]);
}
