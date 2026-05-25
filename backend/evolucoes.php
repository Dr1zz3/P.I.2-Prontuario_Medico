<?php
/**
 * API de Evoluções (história clínica) — a feature central do sistema.
 *
 * Endpoints (todos exigem login):
 *   GET  ?acao=listar&paciente_id=N        → lista todas evoluções do paciente
 *   GET  ?acao=buscar&id=N                 → 1 evolução pelo id
 *   POST ?acao=criar                       → cria RASCUNHO  (perfil MEDICO)
 *        body: { paciente_id, queixa_principal, anamnese, exame_fisico,
 *                hipotese_diagnostica, conduta }
 *   PUT  ?acao=salvar&id=N                 → atualiza RASCUNHO (perfil MEDICO + autor)
 *        body: idem criar (sem paciente_id)
 *   PUT  ?acao=assinar&id=N                → RASCUNHO → ASSINADO (autor)
 *   PUT  ?acao=suspender&id=N              → ASSINADO → SUSPENSO (autor)
 *        body: { justificativa }
 *
 * Regras críticas (combinadas com os triggers do schema):
 *   - Triggers do banco já bloqueiam UPDATE/DELETE em registros ASSINADOS/SUSPENSOS.
 *     Esta API adiciona checagem em PHP pra mensagens amigáveis e
 *     pra validar "só o autor pode assinar/suspender" (que trigger não consegue).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helpers.php';

exigirLogin();

$acao   = $_GET['acao']   ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$user   = usuarioLogado();

// ============================================================
// GET: LISTAR EVOLUÇÕES DE UM PACIENTE
// (qualquer perfil clínico vê — recepção não)
// ============================================================
if ($method === 'GET' && $acao === 'listar') {
    exigirPerfil('MEDICO', 'ADMIN');

    $pacienteId = (int)($_GET['paciente_id'] ?? 0);
    if ($pacienteId <= 0) {
        responder(400, ['status' => 'erro', 'msg' => 'paciente_id é obrigatório.']);
    }

    $stmt = $pdo->prepare(
        'SELECT e.id, e.data_atendimento, e.status,
                e.queixa_principal, e.anamnese, e.exame_fisico,
                e.hipotese_diagnostica, e.conduta,
                e.assinado_em, e.suspenso_em, e.justificativa_suspensao,
                e.autor_usuario_id,
                m.nome AS medico_nome, m.crm_numero, m.crm_uf
           FROM evolucoes e
           JOIN medicos   m ON m.id = e.medico_id
          WHERE e.paciente_id = :pid
          ORDER BY e.data_atendimento DESC'
    );
    $stmt->execute([':pid' => $pacienteId]);
    $lista = $stmt->fetchAll();

    // Marca em cada item se o usuário atual é o autor (pra UI saber
    // quando exibir botão "Suspender")
    foreach ($lista as &$it) {
        $it['eu_sou_autor'] = ((int)$it['autor_usuario_id'] === (int)$user['id']);
    }

    responder(200, ['status' => 'ok', 'evolucoes' => $lista]);
}

// ============================================================
// GET: BUSCAR 1 EVOLUÇÃO
// ============================================================
if ($method === 'GET' && $acao === 'buscar') {
    exigirPerfil('MEDICO', 'ADMIN');

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare(
        'SELECT e.*, m.nome AS medico_nome, m.crm_numero, m.crm_uf
           FROM evolucoes e
           JOIN medicos   m ON m.id = e.medico_id
          WHERE e.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $evo = $stmt->fetch();
    if (!$evo) responder(404, ['status' => 'erro', 'msg' => 'Evolução não encontrada.']);

    $evo['eu_sou_autor'] = ((int)$evo['autor_usuario_id'] === (int)$user['id']);
    responder(200, ['status' => 'ok', 'evolucao' => $evo]);
}

// ============================================================
// HELPER: localiza o medico.id do usuário logado
// ============================================================
function medicoIdDoUsuario(PDO $pdo, int $usuarioId): ?int {
    $stmt = $pdo->prepare('SELECT id FROM medicos WHERE usuario_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $usuarioId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

// ============================================================
// POST: CRIAR EVOLUÇÃO (RASCUNHO)
// ============================================================
if ($method === 'POST' && $acao === 'criar') {
    exigirPerfil('MEDICO');
    $body = lerJson();

    $pacienteId = (int)($body['paciente_id'] ?? 0);
    if ($pacienteId <= 0) {
        responder(400, ['status' => 'erro', 'msg' => 'paciente_id é obrigatório.']);
    }

    $medicoId = medicoIdDoUsuario($pdo, (int)$user['id']);
    if (!$medicoId) {
        responder(403, [
            'status' => 'erro',
            'msg'    => 'Seu usuário não está cadastrado como médico (sem CRM no sistema).',
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO evolucoes
            (paciente_id, medico_id, autor_usuario_id,
             queixa_principal, anamnese, exame_fisico,
             hipotese_diagnostica, conduta, status)
         VALUES
            (:pid, :mid, :uid, :qp, :an, :ef, :hd, :co, "RASCUNHO")'
    );
    $stmt->execute([
        ':pid' => $pacienteId,
        ':mid' => $medicoId,
        ':uid' => $user['id'],
        ':qp'  => trim($body['queixa_principal']     ?? '') ?: null,
        ':an'  => trim($body['anamnese']             ?? '') ?: null,
        ':ef'  => trim($body['exame_fisico']         ?? '') ?: null,
        ':hd'  => trim($body['hipotese_diagnostica'] ?? '') ?: null,
        ':co'  => trim($body['conduta']              ?? '') ?: null,
    ]);
    responder(201, [
        'status' => 'ok',
        'msg'    => 'Rascunho criado.',
        'id'     => (int)$pdo->lastInsertId(),
    ]);
}

// ============================================================
// HELPER: carrega evolução com checagem de autoria
// ============================================================
function carregarEvolucaoComoAutor(PDO $pdo, int $id, int $usuarioId): array {
    $stmt = $pdo->prepare('SELECT * FROM evolucoes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $evo = $stmt->fetch();
    if (!$evo) responder(404, ['status' => 'erro', 'msg' => 'Evolução não encontrada.']);
    if ((int)$evo['autor_usuario_id'] !== $usuarioId) {
        responder(403, [
            'status' => 'erro',
            'msg'    => 'Apenas o autor pode realizar esta ação na evolução.',
        ]);
    }
    return $evo;
}

// ============================================================
// PUT: SALVAR (atualizar rascunho)
// ============================================================
if ($method === 'PUT' && $acao === 'salvar') {
    exigirPerfil('MEDICO');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $evo = carregarEvolucaoComoAutor($pdo, $id, (int)$user['id']);
    if ($evo['status'] !== 'RASCUNHO') {
        responder(409, [
            'status' => 'erro',
            'msg'    => 'Só é possível editar evoluções em RASCUNHO.',
        ]);
    }

    $body = lerJson();
    $stmt = $pdo->prepare(
        'UPDATE evolucoes SET
            queixa_principal     = :qp,
            anamnese             = :an,
            exame_fisico         = :ef,
            hipotese_diagnostica = :hd,
            conduta              = :co
          WHERE id = :id'
    );
    $stmt->execute([
        ':qp' => trim($body['queixa_principal']     ?? '') ?: null,
        ':an' => trim($body['anamnese']             ?? '') ?: null,
        ':ef' => trim($body['exame_fisico']         ?? '') ?: null,
        ':hd' => trim($body['hipotese_diagnostica'] ?? '') ?: null,
        ':co' => trim($body['conduta']              ?? '') ?: null,
        ':id' => $id,
    ]);
    responder(200, ['status' => 'ok', 'msg' => 'Rascunho atualizado.']);
}

// ============================================================
// PUT: ASSINAR (RASCUNHO → ASSINADO, fica imutável)
// ============================================================
if ($method === 'PUT' && $acao === 'assinar') {
    exigirPerfil('MEDICO');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $evo = carregarEvolucaoComoAutor($pdo, $id, (int)$user['id']);
    if ($evo['status'] !== 'RASCUNHO') {
        responder(409, [
            'status' => 'erro',
            'msg'    => 'Apenas evoluções em RASCUNHO podem ser assinadas.',
        ]);
    }
    // Bloqueia assinar evolução vazia
    if (empty(trim(($evo['queixa_principal'] ?? '') . ($evo['anamnese'] ?? '')
              . ($evo['exame_fisico'] ?? '') . ($evo['hipotese_diagnostica'] ?? '')
              . ($evo['conduta'] ?? '')))) {
        responder(400, [
            'status' => 'erro',
            'msg'    => 'Não é possível assinar evolução em branco.',
        ]);
    }

    $pdo->prepare(
        'UPDATE evolucoes SET status = "ASSINADO", assinado_em = NOW() WHERE id = :id'
    )->execute([':id' => $id]);

    responder(200, ['status' => 'ok', 'msg' => 'Evolução assinada. Agora é imutável.']);
}

// ============================================================
// PUT: SUSPENDER (ASSINADO → SUSPENSO, exige justificativa)
// ============================================================
if ($method === 'PUT' && $acao === 'suspender') {
    exigirPerfil('MEDICO');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $body = lerJson();
    $justif = trim($body['justificativa'] ?? '');
    if ($justif === '') {
        responder(400, [
            'status' => 'erro',
            'msg'    => 'A justificativa é obrigatória para suspender.',
        ]);
    }

    $evo = carregarEvolucaoComoAutor($pdo, $id, (int)$user['id']);
    if ($evo['status'] !== 'ASSINADO') {
        responder(409, [
            'status' => 'erro',
            'msg'    => 'Apenas evoluções ASSINADAS podem ser suspensas.',
        ]);
    }

    $pdo->prepare(
        'UPDATE evolucoes SET
            status                  = "SUSPENSO",
            suspenso_em             = NOW(),
            suspenso_por            = :uid,
            justificativa_suspensao = :j
          WHERE id = :id'
    )->execute([
        ':uid' => $user['id'],
        ':j'   => $justif,
        ':id'  => $id,
    ]);

    responder(200, ['status' => 'ok', 'msg' => 'Evolução suspensa.']);
}

// ============================================================
responder(400, ['status' => 'erro', 'msg' => 'Ação desconhecida ou método inválido.']);
