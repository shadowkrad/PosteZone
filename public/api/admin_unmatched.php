<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $results = $pdo->query("SELECT * FROM vie_incongruenti ORDER BY localita, excel_via")->fetchAll();
        echo json_encode([
            'success' => true,
            'results' => $results
        ]);
    } catch (\Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Errore nel caricamento: ' . $e->getMessage()
        ]);
    }
    exit;
}

if ($method === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if ($action === 'delete' && $id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM vie_incongruenti WHERE id = :id");
            $stmt->execute(['id' => $id]);
            echo json_encode([
                'success' => true,
                'message' => 'Anomalia rimossa con successo.'
            ]);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => 'Errore nella rimozione: ' . $e->getMessage()
            ]);
        }
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Operazione non consentita.']);
