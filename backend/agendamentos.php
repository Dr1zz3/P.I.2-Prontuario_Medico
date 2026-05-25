<?php
/**
 * API de Agenda.
 *
 * Endpoints:
 *   GET    ?acao=listar [&data=YYYY-MM-DD] [&medico_id=N] → agendamentos
 *   GET    ?acao=buscar&id=N                              → 1 agendamento
 *   GET    ?acao=medicos                                  → lista médicos p/ dropdown
 *   POST   ?acao=criar                                    → cria (RECEPCAO, ADMIN)
 *   PUT    ?acao=atualizar&id=N                           → edita (RECEPCAO, ADMIN)
 *   PUT    ?acao=status&id=N    body:{status}             → muda status
 *   DELETE ?acao=cancelar&id=N                            → CANCELADO (RECEPCAO, ADMIN)
 *
 * Regras:
 *   - Conflito de horário: mesmo médico não pode ter 2 agendamentos sobrepostos
 *   - Status válidos: AGENDADO, CONFIRMADO, EM_ATENDIMENTO, REALIZADO, CANCELADO, FALTOU
 *   - Médico só pode mudar status de seus próprios agendamentos
 *   - Recepção pode mudar qualquer status
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helpers.php';

exigirLogin();

$acao   = $_GET['acao']   ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$user   = usuarioLogado();

// ============================================================
// LISTAR MÉDICOS (para dropdown)
// ============================================================
if ($method === 'GET' && $acao === 'medicos') {
    $stmt = $pdo->query(
        'SELECT m.id, m.nome, m.crm_numero, m.crm_uf,
                e.nome AS especialidade
           FROM medicos m
           JOIN especialidades e ON e.id = m.especialidade_id
          WHERE m.ativo = TRUE
          ORDER BY m.nome'
    );
    responder(200, ['status' => 'ok', 'medicos' => $stmt->fetchAll()]);
}

// ============================================================
// LISTAR
// ============================================================
if ($method === 'GET' && $acao === 'listar') {
    $data      = trim($_GET['data']       ?? '');
    $medicoId  = (int)($_GET['medico_id'] ?? 0);

    $where = [];
    $params = [];

    if ($data !== '') {
        if (!DateTime::createFromFormat('Y-m-d', $data)) {
            responder(400, ['status' => 'erro', 'msg' => 'Data inválida (use YYYY-MM-DD).']);
        }
        $where[] = 'DATE(a.data_hora) = :data';
        $params[':data'] = $data;
    }
    if ($medicoId > 0) {
        $where[] = 'a.medico_id = :mid';
        $params[':mid'] = $medicoId;
    }

    // Se for MEDICO, só mostra os próprios agendamentos
    if ($user['perfil'] === 'MEDICO') {
        $stmt = $pdo->prepare('SELECT id FROM medicos WHERE usuario_id = :uid');
        $stmt->execute([':uid' => $user['id']]);
        $meuMedicoId = (int)$stmt->fetchColumn();
        if ($meuMedicoId > 0) {
            $where[] = 'a.medico_id = :meu_mid';
            $params[':meu_mid'] = $meuMedicoId;
        }
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT a.id, a.data_hora, a.duracao_min, a.tipo, a.status,
                   a.observacoes, a.paciente_id, a.medico_id,
                   p.nome AS paciente_nome, p.cpf AS paciente_cpf,
                   p.telefone AS paciente_telefone,
                   m.nome AS medico_nome, m.crm_numero, m.crm_uf,
                   e.nome AS especialidade
              FROM agendamentos a
              JOIN pacientes      p ON p.id = a.paciente_id
              JOIN medicos        m ON m.id = a.medico_id
              JOIN especialidades e ON e.id = m.especialidade_id
              $sqlWhere
             ORDER BY a.data_hora";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    responder(200, ['status' => 'ok', 'agendamentos' => $stmt->fetchAll()]);
}

// ============================================================
// BUSCAR
// ============================================================
if ($method === 'GET' && $acao === 'buscar') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare(
        'SELECT a.*, p.nome AS paciente_nome, p.cpf AS paciente_cpf,
                m.nome AS medico_nome
           FROM agendamentos a
           JOIN pacientes p ON p.id = a.paciente_id
           JOIN medicos   m ON m.id = a.medico_id
          WHERE a.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $a = $stmt->fetch();
    if (!$a) responder(404, ['status' => 'erro', 'msg' => 'Agendamento não encontrado.']);
    responder(200, ['status' => 'ok', 'agendamento' => $a]);
}

// ============================================================
// Helpers
// ============================================================
function validarConflito(PDO $pdo, int $medicoId, string $dataHora, int $duracao, ?int $idIgnorar = null): bool {
    $sql = 'SELECT id, data_hora, duracao_min FROM agendamentos
             WHERE medico_id = :mid
               AND status NOT IN ("CANCELADO", "FALTOU")
               AND DATE(data_hora) = DATE(:dh)';
    $params = [':mid' => $medicoId, ':dh' => $dataHora];
    if ($idIgnorar) {
        $sql .= ' AND id <> :ign';
        $params[':ign'] = $idIgnorar;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $iniNovo = strtotime($dataHora);
    $fimNovo = $iniNovo + ($duracao * 60);

    foreach ($stmt->fetchAll() as $existente) {
        $ini = strtotime($existente['data_hora']);
        $fim = $ini + ((int)$existente['duracao_min'] * 60);
        // Sobreposição: [iniNovo, fimNovo] cruza [ini, fim]
        if ($iniNovo < $fim && $ini < $fimNovo) {
            return true;
        }
    }
    return false;
}

function montarDadosAgendamento(array $body): array {
    $erros = [];
    $pid = (int)($body['paciente_id'] ?? 0);
    $mid = (int)($body['medico_id']   ?? 0);
    $dh  = trim($body['data_hora']    ?? '');
    if ($pid <= 0) $erros[] = 'paciente_id é obrigatório';
    if ($mid <= 0) $erros[] = 'medico_id é obrigatório';
    if ($dh === '') $erros[] = 'data_hora é obrigatória';
    elseif (!DateTime::createFromFormat('Y-m-d H:i:s', $dh)
         && !DateTime::createFromFormat('Y-m-d\TH:i', $dh)
         && !DateTime::createFromFormat('Y-m-d H:i', $dh)) {
        $erros[] = 'data_hora inválida (YYYY-MM-DD HH:MM)';
    }
    $tipo = strtoupper(trim($body['tipo'] ?? 'PRIMEIRA_CONSULTA'));
    if (!in_array($tipo, ['PRIMEIRA_CONSULTA', 'RETORNO', 'URGENCIA'], true)) {
        $erros[] = 'tipo inválido';
    }
    if ($erros) responder(400, ['status' => 'erro', 'msg' => implode('; ', $erros)]);

    // Normaliza data_hora p/ formato MySQL (substitui T por espaço, adiciona :00 se faltar segundos)
    $dh = str_replace('T', ' ', $dh);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dh)) $dh .= ':00';

    return [
        'paciente_id' => $pid,
        'medico_id'   => $mid,
        'data_hora'   => $dh,
        'duracao_min' => (int)($body['duracao_min'] ?? 30) ?: 30,
        'tipo'        => $tipo,
        'observacoes' => trim($body['observacoes'] ?? '') ?: null,
    ];
}

// ============================================================
// CRIAR
// ============================================================
if ($method === 'POST' && $acao === 'criar') {
    exigirPerfil('RECEPCAO', 'ADMIN');
    $d = montarDadosAgendamento(lerJson());

    if (validarConflito($pdo, $d['medico_id'], $d['data_hora'], $d['duracao_min'])) {
        responder(409, [
            'status' => 'erro',
            'msg'    => 'Conflito de horário: este médico já tem outro agendamento neste período.',
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO agendamentos
            (paciente_id, medico_id, data_hora, duracao_min, tipo,
             status, observacoes, criado_por)
         VALUES (:pid, :mid, :dh, :dur, :tipo, "AGENDADO", :obs, :cp)'
    );
    $stmt->execute([
        ':pid'  => $d['paciente_id'],
        ':mid'  => $d['medico_id'],
        ':dh'   => $d['data_hora'],
        ':dur'  => $d['duracao_min'],
        ':tipo' => $d['tipo'],
        ':obs'  => $d['observacoes'],
        ':cp'   => $user['id'],
    ]);
    responder(201, [
        'status' => 'ok',
        'msg'    => 'Agendamento criado.',
        'id'     => (int)$pdo->lastInsertId(),
    ]);
}

// ============================================================
// ATUALIZAR
// ============================================================
if ($method === 'PUT' && $acao === 'atualizar') {
    exigirPerfil('RECEPCAO', 'ADMIN');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare('SELECT id, status FROM agendamentos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $orig = $stmt->fetch();
    if (!$orig) responder(404, ['status' => 'erro', 'msg' => 'Agendamento não encontrado.']);
    if (in_array($orig['status'], ['REALIZADO', 'CANCELADO', 'FALTOU'], true)) {
        responder(409, [
            'status' => 'erro',
            'msg'    => 'Agendamentos REALIZADOS/CANCELADOS/FALTAS não podem ser editados.',
        ]);
    }

    $d = montarDadosAgendamento(lerJson());

    if (validarConflito($pdo, $d['medico_id'], $d['data_hora'], $d['duracao_min'], $id)) {
        responder(409, [
            'status' => 'erro',
            'msg'    => 'Conflito de horário com outro agendamento deste médico.',
        ]);
    }

    $stmt = $pdo->prepare(
        'UPDATE agendamentos SET
            paciente_id = :pid, medico_id = :mid, data_hora = :dh,
            duracao_min = :dur, tipo = :tipo, observacoes = :obs
          WHERE id = :id'
    );
    $stmt->execute([
        ':pid'  => $d['paciente_id'], ':mid' => $d['medico_id'],
        ':dh'   => $d['data_hora'],   ':dur' => $d['duracao_min'],
        ':tipo' => $d['tipo'],        ':obs' => $d['observacoes'],
        ':id'   => $id,
    ]);
    responder(200, ['status' => 'ok', 'msg' => 'Agendamento atualizado.']);
}

// ============================================================
// MUDAR STATUS
// ============================================================
if ($method === 'PUT' && $acao === 'status') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $novoStatus = strtoupper(trim(lerJson()['status'] ?? ''));
    $statusValidos = ['AGENDADO','CONFIRMADO','EM_ATENDIMENTO','REALIZADO','CANCELADO','FALTOU'];
    if (!in_array($novoStatus, $statusValidos, true)) {
        responder(400, ['status' => 'erro', 'msg' => 'status inválido.']);
    }

    // Médico só muda status dos próprios agendamentos
    if ($user['perfil'] === 'MEDICO') {
        $stmt = $pdo->prepare(
            'SELECT a.id FROM agendamentos a
              JOIN medicos m ON m.id = a.medico_id
             WHERE a.id = :id AND m.usuario_id = :uid'
        );
        $stmt->execute([':id' => $id, ':uid' => $user['id']]);
        if (!$stmt->fetchColumn()) {
            responder(403, [
                'status' => 'erro',
                'msg'    => 'Você só pode mudar status dos seus próprios agendamentos.',
            ]);
        }
    } elseif (!in_array($user['perfil'], ['RECEPCAO', 'ADMIN'], true)) {
        responder(403, ['status' => 'erro', 'msg' => 'Sem permissão.']);
    }

    $stmt = $pdo->prepare('UPDATE agendamentos SET status = :s WHERE id = :id');
    $stmt->execute([':s' => $novoStatus, ':id' => $id]);
    if ($stmt->rowCount() === 0) {
        responder(404, ['status' => 'erro', 'msg' => 'Agendamento não encontrado.']);
    }
    responder(200, ['status' => 'ok', 'msg' => 'Status atualizado.', 'novo_status' => $novoStatus]);
}

// ============================================================
// CANCELAR (atalho p/ status = CANCELADO)
// ============================================================
if ($method === 'DELETE' && $acao === 'cancelar') {
    exigirPerfil('RECEPCAO', 'ADMIN');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare('UPDATE agendamentos SET status = "CANCELADO" WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) {
        responder(404, ['status' => 'erro', 'msg' => 'Agendamento não encontrado.']);
    }
    responder(200, ['status' => 'ok', 'msg' => 'Agendamento cancelado.']);
}

responder(400, ['status' => 'erro', 'msg' => 'Ação desconhecida ou método inválido.']);
