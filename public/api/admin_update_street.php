<?php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito.']);
    exit;
}

// Support both single rule format (backwards compatibility) and bulk rules format
$via = isset($_POST['via']) ? trim($_POST['via']) : '';
$localita = isset($_POST['localita']) ? trim($_POST['localita']) : '';
$resolveId = isset($_POST['resolve_id']) ? intval($_POST['resolve_id']) : 0;
$rulesJson = isset($_POST['rules']) ? trim($_POST['rules']) : '';

if (empty($via) || empty($localita)) {
    echo json_encode(['success' => false, 'error' => 'Dati della via incompleti.']);
    exit;
}

$viaUpper = strtoupper($via);
$localitaUpper = strtoupper($localita);

try {
    $pdo->beginTransaction();

    if (!empty($rulesJson)) {
        // Bulk nested rules edit
        $rules = json_decode($rulesJson, true);
        if (!is_array($rules)) {
            throw new \Exception('Il formato delle regole non è valido.');
        }

        foreach ($rules as $rule) {
            $ruleId = isset($rule['id']) ? intval($rule['id']) : 0;
            $tipoCivico = isset($rule['tipo_civico']) ? strtoupper(trim($rule['tipo_civico'])) : 'TUTTI';
            $daCivico = (isset($rule['da_civico']) && $rule['da_civico'] !== '') ? intval($rule['da_civico']) : null;
            $aCivico = (isset($rule['a_civico']) && $rule['a_civico'] !== '') ? intval($rule['a_civico']) : null;
            $zonaSigla = isset($rule['zona_sigla']) ? strtoupper(trim($rule['zona_sigla'])) : '';
            $isDelete = isset($rule['delete']) && $rule['delete'] === true;

            if ($isDelete) {
                if ($ruleId > 0) {
                    $stmtDel = $pdo->prepare("DELETE FROM smistamento_vie WHERE id = :id");
                    $stmtDel->execute(['id' => $ruleId]);
                }
            } else {
                if (empty($zonaSigla)) {
                    throw new \Exception('La sigla della zona è obbligatoria per tutte le regole.');
                }

                if ($ruleId > 0) {
                    // Update existing rule
                    $stmt = $pdo->prepare("UPDATE smistamento_vie SET via = :via, localita = :localita, tipo_civico = :tipo, da_civico = :da, a_civico = :a, zona_sigla = :zona WHERE id = :id");
                    $stmt->execute([
                        'via' => $viaUpper,
                        'localita' => $localitaUpper,
                        'tipo' => $tipoCivico,
                        'da' => $daCivico,
                        'a' => $aCivico,
                        'zona' => $zonaSigla,
                        'id' => $ruleId
                    ]);
                } else {
                    // Insert new rule
                    $stmt = $pdo->prepare("INSERT INTO smistamento_vie (via, localita, tipo_civico, da_civico, a_civico, zona_sigla) VALUES (:via, :localita, :tipo, :da, :a, :zona)");
                    $stmt->execute([
                        'via' => $viaUpper,
                        'localita' => $localitaUpper,
                        'tipo' => $tipoCivico,
                        'da' => $daCivico,
                        'a' => $aCivico,
                        'zona' => $zonaSigla
                    ]);
                }
            }
        }
        $message = "Regole dello stradario aggiornate con successo.";
    } else {
        // Backwards compatibility for single rule form submit
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $zonaSigla = isset($_POST['zona_sigla']) ? trim($_POST['zona_sigla']) : '';
        $tipoCivico = isset($_POST['tipo_civico']) ? trim($_POST['tipo_civico']) : 'TUTTI';
        $daCivico = (isset($_POST['da_civico']) && $_POST['da_civico'] !== '') ? intval($_POST['da_civico']) : null;
        $aCivico = (isset($_POST['a_civico']) && $_POST['a_civico'] !== '') ? intval($_POST['a_civico']) : null;

        if (empty($zonaSigla)) {
            throw new \Exception('Dati incompleti per la zona.');
        }

        $zonaSiglaUpper = strtoupper($zonaSigla);
        $tipoCivicoUpper = strtoupper($tipoCivico);

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE smistamento_vie SET via = :via, localita = :localita, tipo_civico = :tipo, da_civico = :da, a_civico = :a, zona_sigla = :zona WHERE id = :id");
            $stmt->execute([
                'via' => $viaUpper,
                'localita' => $localitaUpper,
                'tipo' => $tipoCivicoUpper,
                'da' => $daCivico,
                'a' => $aCivico,
                'zona' => $zonaSiglaUpper,
                'id' => $id
            ]);
            $message = "Via aggiornata con successo.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO smistamento_vie (via, localita, tipo_civico, da_civico, a_civico, zona_sigla) VALUES (:via, :localita, :tipo, :da, :a, :zona)");
            $stmt->execute([
                'via' => $viaUpper,
                'localita' => $localitaUpper,
                'tipo' => $tipoCivicoUpper,
                'da' => $daCivico,
                'a' => $aCivico,
                'zona' => $zonaSiglaUpper
            ]);
            $id = $pdo->lastInsertId();
            $message = "Nuova via creata con successo.";
        }
    }

    // If resolve_id is passed, remove from unmatched list
    if ($resolveId > 0) {
        $stmtDel = $pdo->prepare("DELETE FROM vie_incongruenti WHERE id = :resolve_id");
        $stmtDel->execute(['resolve_id' => $resolveId]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
} catch (\Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Errore nel salvataggio: ' . $e->getMessage()
    ]);
}
