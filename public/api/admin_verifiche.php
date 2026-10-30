<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stato = isset($_GET['stato']) ? strtoupper(trim($_GET['stato'])) : 'APERTA';

    try {
        $sql = "SELECT id, via, localita, motivo, stato, creata_il, verificata_il FROM verifiche";
        $params = [];
        if ($stato === 'APERTA' || $stato === 'VERIFICATA') {
            $sql .= " WHERE stato = :stato";
            $params['stato'] = $stato;
        } elseif ($stato === 'NESSUNA') {
            $sql .= " WHERE 0 = 1"; // solo il conteggio delle aperte (badge del menu)
        }
        $sql .= " ORDER BY localita, via, id LIMIT 1000";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $open = (int)$pdo->query("SELECT COUNT(*) FROM verifiche WHERE stato = 'APERTA'")->fetchColumn();

        echo json_encode([
            'success' => true,
            'aperte' => $open,
            'results' => $stmt->fetchAll()
        ]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Errore nel caricamento: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    try {
        if ($action === 'resolve' && $id > 0) {
            $stmt = $pdo->prepare("UPDATE verifiche SET stato = 'VERIFICATA', verificata_il = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute(['id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Segnalazione chiusa.']);
            exit;
        }

        if ($action === 'reopen' && $id > 0) {
            $stmt = $pdo->prepare("UPDATE verifiche SET stato = 'APERTA', verificata_il = NULL WHERE id = :id");
            $stmt->execute(['id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Segnalazione riaperta.']);
            exit;
        }

        // Segnalazione manuale di un operatore (es. zona sbagliata trovata allo smistamento)
        if ($action === 'add') {
            $via = isset($_POST['via']) ? mb_strtoupper(trim($_POST['via']), 'UTF-8') : '';
            $localita = isset($_POST['localita']) ? mb_strtoupper(trim($_POST['localita']), 'UTF-8') : '';
            $motivo = isset($_POST['motivo']) ? trim($_POST['motivo']) : '';

            if ($via === '' || $localita === '' || $motivo === '') {
                echo json_encode(['success' => false, 'error' => 'Via, comune e motivo sono obbligatori.']);
                exit;
            }

            $motivo = 'Segnalato da operatore: ' . mb_substr($motivo, 0, 300, 'UTF-8');
            $stmt = $pdo->prepare("INSERT INTO verifiche (via, localita, motivo) VALUES (:via, :localita, :motivo)
                ON CONFLICT(via, localita, motivo) DO UPDATE SET stato = 'APERTA', verificata_il = NULL");
            $stmt->execute(['via' => $via, 'localita' => $localita, 'motivo' => $motivo]);
            echo json_encode(['success' => true, 'message' => 'Segnalazione registrata: un responsabile la verificherà.']);
            exit;
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Errore: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Operazione non consentita.']);
