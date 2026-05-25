<?php
/**
 * API de Sinais Vitais — preenchido pela TÉCNICA DE ENFERMAGEM.
 *
 * Endpoints:
 *   GET  ?acao=listar&paciente_id=N    → histórico de aferições do paciente
 *   GET  ?acao=buscar&id=N             → 1 aferição específica
 *   POST ?acao=criar                   → cria RASCUNHO  (TECNICO_ENFERMAGEM)
 *        body: { paciente_id, pressao_sistolica, pressao_diastolica,
 *                frequencia_cardiaca, frequencia_respiratoria,
 *                saturacao_o2, temperatura, peso_kg, altura_cm, observacoes }
 *   PUT  ?acao=salvar&id=N             → atualiza RASCUNHO (autor)
 *   PUT  ?acao=assinar&id=N            → RASCUNHO → ASSINADO (autor)
 *   PUT  ?acao=suspender&id=N          → ASSINADO → SUSPENSO (autor)
 *        body: { justificativa }
 *
 * Permissões:
 *   - Médico/Admin: podem LER (visualizam o que a técnica anotou)
 *   - Técnica de enfermagem: CRUD completo, mas só na própria aferição
 *   - Recepção: SEM acesso (sigilo)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helpers.php';

exigirLogin();

$acao   = $_GET['acao']   ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$user   = usuarioLogado();

// ============================================================
// GET LISTAR
// ============================================================
if ($method === 'GET' && $acao === 'listar') {
    exigirPerfil('MEDICO', 'TECNICO_ENFERMAGEM', 'ADMIN');

    $pacienteId = (int)($_GET['paciente_id'] ?? 0);
    if ($pacienteId <= 0) {
        responder(400, ['status' => 'erro', 'msg' => 'paciente_id é obrigatório.']);
    }

    $stmt = $pdo->prepare(
        'SELECT s.*, u.nome AS autor_nome
           FROM sinais_vitais s
           JOIN usuarios     u ON u.id = s.autor_usuario_id
          WHERE s.paciente_id = :pid
          ORDER BY s.data_afericao DESC'
    );
    $stmt->execute([':pid' => $pacienteId]);
    $lista = $stmt->fetchAll();

    foreach ($lista as &$it) {
        $it['eu_sou_autor'] = ((int)$it['autor_usuario_id'] === (int)$user['id']);
    }

    responder(200, ['status' => 'ok', 'sinais' => $lista]);
}

// ============================================================
// GET BUSCAR
// ============================================================
if ($method === 'GET' && $acao === 'buscar') {
    exigirPerfil('MEDICO', 'TECNICO_ENFERMAGEM', 'ADMIN');

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare(
        'SELECT s.*, u.nome AS autor_nome
           FROM sinais_vitais s
           JOIN usuarios     u ON u.id = s.autor_usuario_id
          WHERE s.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $sv = $stmt->fetch();
    if (!$sv) responder(404, ['status' => 'erro', 'msg' => 'Registro não encontrado.']);

    $sv['eu_sou_autor'] = ((int)$sv['autor_usuario_id'] === (int)$user['id']);
    responder(200, ['status' => 'ok', 'sinais' => $sv]);
}

// ============================================================
// Helpers
// ============================================================
function intOuNull($v) {
    if ($v === '' || $v === null) return null;
    $n = (int)$v;
    return $n > 0 ? $n : null;
}
function floatOuNull($v) {
    if ($v === '' || $v === null) return null;
    $n = (float)$v;
    return $n > 0 ? $n : null;
}

function montarDadosSinais(array $body): array {
    return [
        'pressao_sistolica'       => intOuNull($body['pressao_sistolica']       ?? null),
        'pressao_diastolica'      => intOuNull($body['pressao_diastolica']      ?? null),
        'frequencia_cardiaca'     => intOuNull($body['frequencia_cardiaca']     ?? null),
        'frequencia_respiratoria' => intOuNull($body['frequencia_respiratoria'] ?? null),
        'saturacao_o2'            => intOuNull($body['saturacao_o2']            ?? null),
        'temperatura'             => floatOuNull($body['temperatura']           ?? null),
        'peso_kg'                 => floatOuNull($body['peso_kg']               ?? null),
        'altura_cm'               => intOuNull($body['altura_cm']               ?? null),
        'observacoes'             => trim($body['observacoes']                  ?? '') ?: null,
    ];
}

function pegarRegistroComoAutor(PDO $pdo, int $id, int $usuarioId): array {
    $stmt = $pdo->prepare('SELECT * FROM sinais_vitais WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $sv = $stmt->fetch();
    if (!$sv) responder(404, ['status' => 'erro', 'msg' => 'Registro não encontrado.']);
    if ((int)$sv['autor_usuario_id'] !== $usuarioId) {
        responder(403, [
            'status' => 'erro',
            'msg'    => 'Apenas o autor pode realizar esta ação.',
        ]);
    }
    return $sv;
}

// ============================================================
// POST CRIAR
// ============================================================
if ($method === 'POST' && $acao === 'criar') {
    exigirPerfil('TECNICO_ENFERMAGEM');

    $body = lerJson();
    $pacienteId = (int)($body['paciente_id'] ?? 0);
    if ($pacienteId <= 0) {
        responder(400, ['status' => 'erro', 'msg' => 'paciente_id é obrigatório.']);
    }

    $d = montarDadosSinais($body);

    $sql = 'INSERT INTO sinais_vitais
            (paciente_id, autor_usuario_id, pressao_sistolica, pressao_diastolica,
             frequencia_cardiaca, frequencia_respiratoria, saturacao_o2, temperatura,
             peso_kg, altura_cm, observacoes, status)
            VALUES
            (:pid, :uid, :ps, :pd, :fc, :fr, :sat, :temp,
             :peso, :altura, :obs, "RASCUNHO")';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':pid'   => $pacienteId,
        ':uid'   => $user['id'],
        ':ps'    => $d['pressao_sistolica'],
        ':pd'    => $d['pressao_diastolica'],
        ':fc'    => $d['frequencia_cardiaca'],
        ':fr'    => $d['frequencia_respiratoria'],
        ':sat'   => $d['saturacao_o2'],
        ':temp'  => $d['temperatura'],
        ':peso'  => $d['peso_kg'],
        ':altura'=> $d['altura_cm'],
        ':obs'   => $d['observacoes'],
    ]);
    responder(201, [
        'status' => 'ok', 'msg' => 'Rascunho criado.',
        'id'     => (int)$pdo->lastInsertId(),
    ]);
}

// ============================================================
// PUT SALVAR (atualiza rascunho)
// ============================================================
if ($method === 'PUT' && $acao === 'salvar') {
    exigirPerfil('TECNICO_ENFERMAGEM');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $sv = pegarRegistroComoAutor($pdo, $id, (int)$user['id']);
    if ($sv['status'] !== 'RASCUNHO') {
        responder(409, ['status' => 'erro', 'msg' => 'Só rascunhos podem ser editados.']);
    }

    $d = montarDadosSinais(lerJson());
    $stmt = $pdo->prepare(
        'UPDATE sinais_vitais SET
            pressao_sistolica       = :ps,
            pressao_diastolica      = :pd,
            frequencia_cardiaca     = :fc,
            frequencia_respiratoria = :fr,
            saturacao_o2            = :sat,
            temperatura             = :temp,
            peso_kg                 = :peso,
            altura_cm               = :altura,
            observacoes             = :obs
          WHERE id = :id'
    );
    $stmt->execute([
        ':ps'=>$d['pressao_sistolica'], ':pd'=>$d['pressao_diastolica'],
        ':fc'=>$d['frequencia_cardiaca'], ':fr'=>$d['frequencia_respiratoria'],
        ':sat'=>$d['saturacao_o2'], ':temp'=>$d['temperatura'],
        ':peso'=>$d['peso_kg'], ':altura'=>$d['altura_cm'],
        ':obs'=>$d['observacoes'], ':id'=>$id,
    ]);
    responder(200, ['status' => 'ok', 'msg' => 'Rascunho atualizado.']);
}

// ============================================================
// PUT ASSINAR
// ============================================================
if ($method === 'PUT' && $acao === 'assinar') {
    exigirPerfil('TECNICO_ENFERMAGEM');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $sv = pegarRegistroComoAutor($pdo, $id, (int)$user['id']);
    if ($sv['status'] !== 'RASCUNHO') {
        responder(409, ['status' => 'erro', 'msg' => 'Só rascunhos podem ser assinados.']);
    }

    // Bloqueia assinar registro totalmente vazio
    $temAlgo = $sv['pressao_sistolica'] || $sv['pressao_diastolica']
            || $sv['frequencia_cardiaca'] || $sv['frequencia_respiratoria']
            || $sv['saturacao_o2'] || $sv['temperatura']
            || $sv['peso_kg'] || $sv['altura_cm'];
    if (!$temAlgo) {
        responder(400, [
            'status' => 'erro',
            'msg'    => 'Não é possível assinar registro sem nenhum dado.',
        ]);
    }

    $pdo->prepare(
        'UPDATE sinais_vitais SET status="ASSINADO", assinado_em=NOW() WHERE id=:id'
    )->execute([':id' => $id]);

    responder(200, ['status' => 'ok', 'msg' => 'Aferição assinada. Agora é imutável.']);
}

// ============================================================
// PUT SUSPENDER
// ============================================================
if ($method === 'PUT' && $acao === 'suspender') {
    exigirPerfil('TECNICO_ENFERMAGEM');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $body = lerJson();
    $justif = trim($body['justificativa'] ?? '');
    if ($justif === '') {
        responder(400, [
            'status' => 'erro',
            'msg'    => 'Justificativa é obrigatória para suspender.',
        ]);
    }

    $sv = pegarRegistroComoAutor($pdo, $id, (int)$user['id']);
    if ($sv['status'] !== 'ASSINADO') {
        responder(409, ['status' => 'erro', 'msg' => 'Só aferições assinadas podem ser suspensas.']);
    }

    $pdo->prepare(
        'UPDATE sinais_vitais SET
            status                  = "SUSPENSO",
            suspenso_em             = NOW(),
            suspenso_por            = :uid,
            justificativa_suspensao = :j
          WHERE id = :id'
    )->execute([':uid' => $user['id'], ':j' => $justif, ':id' => $id]);

    responder(200, ['status' => 'ok', 'msg' => 'Aferição suspensa.']);
}

responder(400, ['status' => 'erro', 'msg' => 'Ação desconhecida ou método inválido.']);
