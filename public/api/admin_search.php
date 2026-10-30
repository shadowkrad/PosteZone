<?php
require_once 'db.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$localita = isset($_GET['localita']) ? trim($_GET['localita']) : '';

try {
    $sql = "SELECT * FROM smistamento_vie WHERE 1=1";
    $params = [];

    if (!empty($q)) {
        $sql .= " AND via LIKE :q";
        $params['q'] = "%" . strtoupper($q) . "%";
    }

    if (!empty($localita)) {
        $sql .= " AND localita = :localita";
        $params['localita'] = strtoupper($localita);
    }

    $sql .= " ORDER BY localita, via LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Errore durante la ricerca: ' . $e->getMessage()
    ]);
}
