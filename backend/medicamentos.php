<?php
/**
 * API de Catálogo de Medicamentos.
 *
 * Endpoints:
 *   GET    ?acao=listar [&busca=texto]   → lista ativos (filtro por nome/princípio)
 *   GET    ?acao=buscar&id=N             → 1 medicamento
 *   POST   ?acao=criar                   → cadastra (ADMIN, MEDICO)
 *   PUT    ?acao=atualizar&id=N          → edita (ADMIN, MEDICO)
 *   DELETE ?acao=desativar&id=N          → soft delete (ADMIN)
 *   POST   ?acao=importar_csv            → upload CSV multipart (ADMIN)
 *          Cabeçalho do CSV esperado:
 *          nome_comercial,principio_ativo,apresentacao,fabricante,registro_anvisa,e_antibiotico
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helpers.php';

exigirLogin();

$acao   = $_GET['acao']   ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// LISTAR
// ============================================================
if ($method === 'GET' && $acao === 'listar') {
    $busca = trim($_GET['busca'] ?? '');
    if ($busca !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, nome_comercial, principio_ativo, apresentacao,
                    fabricante, e_antibiotico
               FROM medicamentos
              WHERE ativo = TRUE
                AND (nome_comercial LIKE :q OR principio_ativo LIKE :q)
              ORDER BY nome_comercial
              LIMIT 200'
        );
        $stmt->execute([':q' => "%$busca%"]);
    } else {
        $stmt = $pdo->query(
            'SELECT id, nome_comercial, principio_ativo, apresentacao,
                    fabricante, e_antibiotico
               FROM medicamentos
              WHERE ativo = TRUE
              ORDER BY nome_comercial
              LIMIT 500'
        );
    }
    responder(200, ['status' => 'ok', 'medicamentos' => $stmt->fetchAll()]);
}

// ============================================================
// BUSCAR
// ============================================================
if ($method === 'GET' && $acao === 'buscar') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);
    $stmt = $pdo->prepare('SELECT * FROM medicamentos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $m = $stmt->fetch();
    if (!$m) responder(404, ['status' => 'erro', 'msg' => 'Medicamento não encontrado.']);
    responder(200, ['status' => 'ok', 'medicamento' => $m]);
}

// ============================================================
// Helpers
// ============================================================
function montarDadosMed(array $body): array {
    $erros = [];
    $nome = trim($body['nome_comercial']  ?? '');
    $prin = trim($body['principio_ativo'] ?? '');
    if ($nome === '') $erros[] = 'nome_comercial é obrigatório';
    if ($prin === '') $erros[] = 'principio_ativo é obrigatório';
    if ($erros) responder(400, ['status' => 'erro', 'msg' => implode('; ', $erros)]);

    return [
        'nome_comercial'  => $nome,
        'principio_ativo' => $prin,
        'apresentacao'    => trim($body['apresentacao']    ?? '') ?: null,
        'fabricante'      => trim($body['fabricante']      ?? '') ?: null,
        'registro_anvisa' => trim($body['registro_anvisa'] ?? '') ?: null,
        'e_antibiotico'   => !empty($body['e_antibiotico']) ? 1 : 0,
    ];
}

// ============================================================
// CRIAR
// ============================================================
if ($method === 'POST' && $acao === 'criar') {
    exigirPerfil('ADMIN', 'MEDICO');
    $d = montarDadosMed(lerJson());
    $stmt = $pdo->prepare(
        'INSERT INTO medicamentos
            (nome_comercial, principio_ativo, apresentacao, fabricante,
             registro_anvisa, e_antibiotico)
         VALUES (:nc, :pa, :ap, :fb, :ra, :ea)'
    );
    $stmt->execute([
        ':nc' => $d['nome_comercial'],   ':pa' => $d['principio_ativo'],
        ':ap' => $d['apresentacao'],     ':fb' => $d['fabricante'],
        ':ra' => $d['registro_anvisa'],  ':ea' => $d['e_antibiotico'],
    ]);
    responder(201, ['status' => 'ok', 'msg' => 'Medicamento cadastrado.',
                    'id' => (int)$pdo->lastInsertId()]);
}

// ============================================================
// ATUALIZAR
// ============================================================
if ($method === 'PUT' && $acao === 'atualizar') {
    exigirPerfil('ADMIN', 'MEDICO');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $d = montarDadosMed(lerJson());
    $stmt = $pdo->prepare(
        'UPDATE medicamentos SET
            nome_comercial  = :nc,
            principio_ativo = :pa,
            apresentacao    = :ap,
            fabricante      = :fb,
            registro_anvisa = :ra,
            e_antibiotico   = :ea
          WHERE id = :id'
    );
    $stmt->execute([
        ':nc' => $d['nome_comercial'],   ':pa' => $d['principio_ativo'],
        ':ap' => $d['apresentacao'],     ':fb' => $d['fabricante'],
        ':ra' => $d['registro_anvisa'],  ':ea' => $d['e_antibiotico'],
        ':id' => $id,
    ]);
    if ($stmt->rowCount() === 0) {
        responder(404, ['status' => 'erro', 'msg' => 'Medicamento não encontrado.']);
    }
    responder(200, ['status' => 'ok', 'msg' => 'Medicamento atualizado.']);
}

// ============================================================
// DESATIVAR
// ============================================================
if ($method === 'DELETE' && $acao === 'desativar') {
    exigirPerfil('ADMIN');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare('UPDATE medicamentos SET ativo = FALSE WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) {
        responder(404, ['status' => 'erro', 'msg' => 'Medicamento não encontrado.']);
    }
    responder(200, ['status' => 'ok', 'msg' => 'Medicamento desativado.']);
}

// ============================================================
// IMPORTAR CSV
// Espera multipart/form-data com campo "arquivo".
// Formato do CSV (1ª linha = cabeçalho):
//   nome_comercial,principio_ativo,apresentacao,fabricante,registro_anvisa,e_antibiotico
// e_antibiotico aceita: 1/0, true/false, sim/nao
// ============================================================
if ($method === 'POST' && $acao === 'importar_csv') {
    exigirPerfil('ADMIN');

    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        responder(400, ['status' => 'erro', 'msg' => 'Envie um arquivo no campo "arquivo".']);
    }

    $tmp = $_FILES['arquivo']['tmp_name'];
    $handle = fopen($tmp, 'r');
    if (!$handle) responder(500, ['status' => 'erro', 'msg' => 'Falha ao abrir o CSV.']);

    // Detecta delimitador (vírgula ou ponto-e-vírgula)
    $primeira = fgets($handle);
    $delim = (substr_count($primeira, ';') > substr_count($primeira, ',')) ? ';' : ',';
    rewind($handle);

    $cabecalho = fgetcsv($handle, 0, $delim);
    if (!$cabecalho) responder(400, ['status' => 'erro', 'msg' => 'CSV vazio.']);

    // Normaliza cabeçalhos
    $cab = array_map(fn($c) => strtolower(trim($c, "\"' \t\n\r\0\x0B\xEF\xBB\xBF")), $cabecalho);

    $idxNome = array_search('nome_comercial',  $cab);
    $idxPrin = array_search('principio_ativo', $cab);
    if ($idxNome === false || $idxPrin === false) {
        responder(400, [
            'status' => 'erro',
            'msg'    => 'Cabeçalho deve conter pelo menos "nome_comercial" e "principio_ativo".',
        ]);
    }
    $idxApres = array_search('apresentacao',    $cab);
    $idxFab   = array_search('fabricante',      $cab);
    $idxReg   = array_search('registro_anvisa', $cab);
    $idxAtb   = array_search('e_antibiotico',   $cab);

    $stmt = $pdo->prepare(
        'INSERT INTO medicamentos
            (nome_comercial, principio_ativo, apresentacao, fabricante,
             registro_anvisa, e_antibiotico)
         VALUES (:nc, :pa, :ap, :fb, :ra, :ea)'
    );

    $inseridos = 0; $ignorados = 0; $linha = 1;
    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($handle, 0, $delim)) !== false) {
            $linha++;
            $nome = trim($row[$idxNome] ?? '');
            $prin = trim($row[$idxPrin] ?? '');
            if ($nome === '' || $prin === '') { $ignorados++; continue; }

            $atbRaw = ($idxAtb !== false) ? strtolower(trim($row[$idxAtb] ?? '')) : '0';
            $eAntibiotico = in_array($atbRaw, ['1','true','sim','yes','s']) ? 1 : 0;

            $stmt->execute([
                ':nc' => $nome,
                ':pa' => $prin,
                ':ap' => ($idxApres !== false ? trim($row[$idxApres] ?? '') : '') ?: null,
                ':fb' => ($idxFab   !== false ? trim($row[$idxFab]   ?? '') : '') ?: null,
                ':ra' => ($idxReg   !== false ? trim($row[$idxReg]   ?? '') : '') ?: null,
                ':ea' => $eAntibiotico,
            ]);
            $inseridos++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        responder(500, ['status' => 'erro', 'msg' => 'Erro na linha ' . $linha . ': ' . $e->getMessage()]);
    }
    fclose($handle);

    responder(200, [
        'status'    => 'ok',
        'msg'       => "Importação concluída: $inseridos inseridos, $ignorados ignorados.",
        'inseridos' => $inseridos,
        'ignorados' => $ignorados,
    ]);
}

responder(400, ['status' => 'erro', 'msg' => 'Ação desconhecida ou método inválido.']);
