<?php
/**
 * API de Receitas — emitidas pelo médico.
 *
 * Endpoints:
 *   GET  ?acao=listar_paciente&paciente_id=N  → todas receitas de um paciente
 *   GET  ?acao=buscar&id=N                    → 1 receita + itens + dados pra impressão
 *   POST ?acao=criar                          → cria RASCUNHO (MEDICO)
 *        body: { evolucao_id, tipo, validade_dias, observacoes,
 *                comprador_nome, comprador_cpf, comprador_rg, // só p/ ANTIBIOTICO
 *                itens: [{ descricao_livre, posologia, quantidade, uso_continuo }] }
 *   PUT  ?acao=salvar&id=N                    → atualiza RASCUNHO (autor)
 *   PUT  ?acao=assinar&id=N                   → RASCUNHO → ASSINADO (autor)
 *   PUT  ?acao=suspender&id=N                 → ASSINADO → SUSPENSO (autor)
 *
 * Regras:
 *   - Toda receita está vinculada a uma evolução (FK NOT NULL no schema)
 *   - Tipo SIMPLES (medicamentos comuns) ou ANTIBIOTICO (em 2 vias)
 *   - Tarja preta NÃO é emitida aqui (receita física da Vigilância Sanitária)
 *   - Triggers do banco bloqueiam alteração após ASSINADO
 *   - Apenas o autor pode assinar/suspender
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helpers.php';

exigirLogin();

$acao   = $_GET['acao']   ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$user   = usuarioLogado();

// ============================================================
// GET LISTAR (por paciente)
// ============================================================
if ($method === 'GET' && $acao === 'listar_paciente') {
    exigirPerfil('MEDICO', 'ADMIN');

    $pacienteId = (int)($_GET['paciente_id'] ?? 0);
    if ($pacienteId <= 0) {
        responder(400, ['status' => 'erro', 'msg' => 'paciente_id é obrigatório.']);
    }

    $stmt = $pdo->prepare(
        'SELECT r.id, r.tipo, r.status, r.data_emissao, r.validade_dias,
                r.observacoes, r.assinado_em, r.suspenso_em,
                r.justificativa_suspensao, r.autor_usuario_id,
                m.nome AS medico_nome, m.crm_numero, m.crm_uf,
                e.id AS evolucao_id, e.data_atendimento AS evolucao_data
           FROM receitas r
           JOIN medicos   m ON m.id = r.medico_id
           JOIN evolucoes e ON e.id = r.evolucao_id
          WHERE r.paciente_id = :pid
          ORDER BY r.data_emissao DESC'
    );
    $stmt->execute([':pid' => $pacienteId]);
    $lista = $stmt->fetchAll();

    // Pra cada receita, anexa os itens
    if ($lista) {
        $ids = array_column($lista, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, receita_id, descricao_livre, posologia, quantidade, uso_continuo
               FROM receita_itens WHERE receita_id IN ($in) ORDER BY id"
        );
        $stmt->execute($ids);
        $itens = $stmt->fetchAll();
        foreach ($lista as &$r) {
            $r['itens'] = array_values(array_filter($itens,
                fn($i) => (int)$i['receita_id'] === (int)$r['id']));
            $r['eu_sou_autor'] = ((int)$r['autor_usuario_id'] === (int)$user['id']);
        }
    }

    responder(200, ['status' => 'ok', 'receitas' => $lista]);
}

// ============================================================
// GET BUSCAR (1 receita, com dados completos pra impressão)
// ============================================================
if ($method === 'GET' && $acao === 'buscar') {
    exigirPerfil('MEDICO', 'ADMIN');

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    // Receita + médico + clínica + paciente (tudo p/ impressão)
    $stmt = $pdo->prepare(
        'SELECT r.*,
                m.nome AS medico_nome, m.crm_numero, m.crm_uf,
                esp.nome AS especialidade,
                p.nome AS paciente_nome, p.cpf AS paciente_cpf,
                p.data_nascimento AS paciente_nascimento,
                p.logradouro AS paciente_logradouro,
                p.numero AS paciente_numero,
                p.complemento AS paciente_complemento,
                p.bairro AS paciente_bairro,
                p.cidade AS paciente_cidade,
                p.uf AS paciente_uf,
                p.cep AS paciente_cep,
                c.nome AS clinica_nome,
                c.telefone AS clinica_telefone,
                c.logradouro AS clinica_logradouro,
                c.numero AS clinica_numero,
                c.bairro AS clinica_bairro,
                c.cidade AS clinica_cidade,
                c.uf AS clinica_uf,
                c.cep AS clinica_cep,
                c.logo_url AS clinica_logo
           FROM receitas r
           JOIN medicos        m ON m.id = r.medico_id
           JOIN especialidades esp ON esp.id = m.especialidade_id
           JOIN pacientes      p ON p.id = r.paciente_id
      LEFT JOIN clinica        c ON c.id = 1
          WHERE r.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $rec = $stmt->fetch();
    if (!$rec) responder(404, ['status' => 'erro', 'msg' => 'Receita não encontrada.']);

    // Itens
    $stmt = $pdo->prepare(
        'SELECT id, descricao_livre, posologia, quantidade, uso_continuo
           FROM receita_itens WHERE receita_id = :rid ORDER BY id'
    );
    $stmt->execute([':rid' => $id]);
    $rec['itens'] = $stmt->fetchAll();
    $rec['eu_sou_autor'] = ((int)$rec['autor_usuario_id'] === (int)$user['id']);

    responder(200, ['status' => 'ok', 'receita' => $rec]);
}

// ============================================================
// Helpers
// ============================================================
function medicoIdDoUsuario(PDO $pdo, int $usuarioId): ?int {
    $stmt = $pdo->prepare('SELECT id FROM medicos WHERE usuario_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $usuarioId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function pegarReceitaComoAutor(PDO $pdo, int $id, int $usuarioId): array {
    $stmt = $pdo->prepare('SELECT * FROM receitas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    if (!$r) responder(404, ['status' => 'erro', 'msg' => 'Receita não encontrada.']);
    if ((int)$r['autor_usuario_id'] !== $usuarioId) {
        responder(403, ['status' => 'erro', 'msg' => 'Apenas o autor pode esta ação.']);
    }
    return $r;
}

function inserirItens(PDO $pdo, int $receitaId, array $itens): void {
    if (empty($itens)) return;
    $stmt = $pdo->prepare(
        'INSERT INTO receita_itens (receita_id, descricao_livre, posologia, quantidade, uso_continuo)
         VALUES (:rid, :desc, :pos, :qtd, :uc)'
    );
    foreach ($itens as $it) {
        $desc = trim($it['descricao_livre'] ?? '');
        $pos  = trim($it['posologia']       ?? '');
        if ($desc === '' || $pos === '') continue;
        $stmt->execute([
            ':rid'  => $receitaId,
            ':desc' => $desc,
            ':pos'  => $pos,
            ':qtd'  => trim($it['quantidade'] ?? '') ?: null,
            ':uc'   => !empty($it['uso_continuo']) ? 1 : 0,
        ]);
    }
}

// ============================================================
// POST CRIAR
// ============================================================
if ($method === 'POST' && $acao === 'criar') {
    exigirPerfil('MEDICO');
    $body = lerJson();

    $evolucaoId = (int)($body['evolucao_id'] ?? 0);
    if ($evolucaoId <= 0) {
        responder(400, ['status' => 'erro', 'msg' => 'evolucao_id é obrigatório.']);
    }
    $tipo = strtoupper(trim($body['tipo'] ?? 'SIMPLES'));
    if (!in_array($tipo, ['SIMPLES', 'ANTIBIOTICO'], true)) {
        responder(400, ['status' => 'erro', 'msg' => 'tipo inválido (SIMPLES ou ANTIBIOTICO).']);
    }

    // Pega paciente_id da evolução (garante consistência)
    $stmt = $pdo->prepare('SELECT paciente_id FROM evolucoes WHERE id = :id');
    $stmt->execute([':id' => $evolucaoId]);
    $pacienteId = $stmt->fetchColumn();
    if (!$pacienteId) {
        responder(400, ['status' => 'erro', 'msg' => 'Evolução não encontrada.']);
    }

    $medicoId = medicoIdDoUsuario($pdo, (int)$user['id']);
    if (!$medicoId) {
        responder(403, ['status' => 'erro', 'msg' => 'Seu usuário não está cadastrado como médico.']);
    }

    $validade = (int)($body['validade_dias'] ?? 30) ?: 30;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO receitas
                (evolucao_id, paciente_id, medico_id, autor_usuario_id,
                 tipo, validade_dias, comprador_nome, comprador_cpf, comprador_rg,
                 observacoes, status)
             VALUES
                (:ev, :pid, :mid, :uid,
                 :tipo, :val, :cn, :cc, :cr,
                 :obs, "RASCUNHO")'
        );
        $stmt->execute([
            ':ev'   => $evolucaoId,
            ':pid'  => $pacienteId,
            ':mid'  => $medicoId,
            ':uid'  => $user['id'],
            ':tipo' => $tipo,
            ':val'  => $validade,
            ':cn'   => trim($body['comprador_nome'] ?? '') ?: null,
            ':cc'   => preg_replace('/\D/', '', $body['comprador_cpf'] ?? '') ?: null,
            ':cr'   => trim($body['comprador_rg'] ?? '') ?: null,
            ':obs'  => trim($body['observacoes']    ?? '') ?: null,
        ]);
        $receitaId = (int)$pdo->lastInsertId();

        inserirItens($pdo, $receitaId, $body['itens'] ?? []);

        $pdo->commit();
        responder(201, ['status' => 'ok', 'msg' => 'Rascunho criado.', 'id' => $receitaId]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        responder(500, ['status' => 'erro', 'msg' => $e->getMessage()]);
    }
}

// ============================================================
// PUT SALVAR (atualiza rascunho + reescreve itens)
// ============================================================
if ($method === 'PUT' && $acao === 'salvar') {
    exigirPerfil('MEDICO');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $rec = pegarReceitaComoAutor($pdo, $id, (int)$user['id']);
    if ($rec['status'] !== 'RASCUNHO') {
        responder(409, ['status' => 'erro', 'msg' => 'Só rascunhos podem ser editados.']);
    }

    $body = lerJson();
    $validade = (int)($body['validade_dias'] ?? $rec['validade_dias']) ?: 30;
    $tipo = strtoupper(trim($body['tipo'] ?? $rec['tipo']));
    if (!in_array($tipo, ['SIMPLES', 'ANTIBIOTICO'], true)) {
        responder(400, ['status' => 'erro', 'msg' => 'tipo inválido.']);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE receitas SET
                tipo            = :tipo,
                validade_dias   = :val,
                comprador_nome  = :cn,
                comprador_cpf   = :cc,
                comprador_rg    = :cr,
                observacoes     = :obs
              WHERE id = :id'
        );
        $stmt->execute([
            ':tipo' => $tipo,
            ':val'  => $validade,
            ':cn'   => trim($body['comprador_nome'] ?? '') ?: null,
            ':cc'   => preg_replace('/\D/', '', $body['comprador_cpf'] ?? '') ?: null,
            ':cr'   => trim($body['comprador_rg'] ?? '') ?: null,
            ':obs'  => trim($body['observacoes']    ?? '') ?: null,
            ':id'   => $id,
        ]);
        // Apaga itens antigos e regrava
        $pdo->prepare('DELETE FROM receita_itens WHERE receita_id = :rid')
            ->execute([':rid' => $id]);
        inserirItens($pdo, $id, $body['itens'] ?? []);

        $pdo->commit();
        responder(200, ['status' => 'ok', 'msg' => 'Rascunho atualizado.']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        responder(500, ['status' => 'erro', 'msg' => $e->getMessage()]);
    }
}

// ============================================================
// PUT ASSINAR
// ============================================================
if ($method === 'PUT' && $acao === 'assinar') {
    exigirPerfil('MEDICO');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $rec = pegarReceitaComoAutor($pdo, $id, (int)$user['id']);
    if ($rec['status'] !== 'RASCUNHO') {
        responder(409, ['status' => 'erro', 'msg' => 'Só rascunhos podem ser assinados.']);
    }

    // Tem que ter pelo menos 1 item
    $qtdItens = (int)$pdo->prepare('SELECT COUNT(*) FROM receita_itens WHERE receita_id = ?')
        ->execute([$id]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM receita_itens WHERE receita_id = :id');
    $stmt->execute([':id' => $id]);
    if ((int)$stmt->fetchColumn() === 0) {
        responder(400, ['status' => 'erro', 'msg' => 'Receita sem medicamentos não pode ser assinada.']);
    }

    // Pra ANTIBIOTICO, exige dados do comprador
    if ($rec['tipo'] === 'ANTIBIOTICO') {
        if (empty($rec['comprador_nome']) || empty($rec['comprador_cpf'])) {
            responder(400, [
                'status' => 'erro',
                'msg'    => 'Receita de antibiótico exige nome e CPF do comprador.',
            ]);
        }
    }

    $pdo->prepare(
        'UPDATE receitas SET status="ASSINADO", assinado_em=NOW() WHERE id=:id'
    )->execute([':id' => $id]);

    responder(200, ['status' => 'ok', 'msg' => 'Receita assinada. Agora é imutável.']);
}

// ============================================================
// PUT SUSPENDER
// ============================================================
if ($method === 'PUT' && $acao === 'suspender') {
    exigirPerfil('MEDICO');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $body = lerJson();
    $justif = trim($body['justificativa'] ?? '');
    if ($justif === '') {
        responder(400, ['status' => 'erro', 'msg' => 'Justificativa é obrigatória.']);
    }

    $rec = pegarReceitaComoAutor($pdo, $id, (int)$user['id']);
    if ($rec['status'] !== 'ASSINADO') {
        responder(409, ['status' => 'erro', 'msg' => 'Só receitas assinadas podem ser suspensas.']);
    }

    $pdo->prepare(
        'UPDATE receitas SET
            status                  = "SUSPENSO",
            suspenso_em             = NOW(),
            suspenso_por            = :uid,
            justificativa_suspensao = :j
          WHERE id = :id'
    )->execute([':uid' => $user['id'], ':j' => $justif, ':id' => $id]);

    responder(200, ['status' => 'ok', 'msg' => 'Receita suspensa.']);
}

responder(400, ['status' => 'erro', 'msg' => 'Ação desconhecida ou método inválido.']);
