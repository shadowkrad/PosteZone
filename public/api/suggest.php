<?php
require_once 'db.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$qUpper = strtoupper($q);

// Trova le prime 10 vie univoche che iniziano con o contengono il testo digitato
$stmt = $pdo->prepare("
    SELECT DISTINCT via, localita 
    FROM smistamento_vie 
    WHERE via LIKE :q 
    ORDER BY via ASC 
    LIMIT 10
");
$stmt->execute(['q' => "%$qUpper%"]);
$rows = $stmt->fetchAll();

echo json_encode($rows);
