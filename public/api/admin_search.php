<?php
require_once 'db.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$localita = isset($_GET['localita']) ? trim($_GET['localita']) : '';

try {
    $sql = "SELECT s.*, (SELECT group_concat(v.motivo, char(10)) FROM verifiche v
                         WHERE v.via = s.via AND v.localita = s.localita AND v.stato = 'APERTA') AS verifiche
            FROM smistamento_vie s WHERE 1=1";
    $params = [];

    if (!empty($q)) {
        $sql .= " AND s.via LIKE :q";
        $params['q'] = "%" . strtoupper($q) . "%";
    }

    if (!empty($localita)) {
        $sql .= " AND s.localita = :localita";
        $params['localita'] = strtoupper($localita);
    }

    $sql .= " ORDER BY s.localita, s.via LIMIT 50";

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
