<?php
/**
 * API de Usuários do sistema (acesso/login).
 * REQUER perfil ADMIN.
 *
 * Endpoints:
 *   GET    ?acao=listar [&busca=texto]    → lista todos (ativos e inativos)
 *   GET    ?acao=buscar&id=N              → 1 usuário + dados médicos se for MEDICO
 *   GET    ?acao=especialidades           → lista p/ dropdown de cadastro de médico
 *   POST   ?acao=criar                    → cria usuário (+ médico se for MEDICO)
 *   PUT    ?acao=atualizar&id=N           → atualiza (sem mexer na senha)
 *   PUT    ?acao=alterar_senha&id=N       → troca senha
 *   PUT    ?acao=reativar&id=N            → reativa desativado
 *   DELETE ?acao=desativar&id=N           → soft delete (não pode desativar a si mesmo)
 *
 * Diferenças vs. usuarios.php antigo:
 *   - Prepared statements (sem SQL injection)
 *   - password_hash / password_verify (sem texto puro)
 *   - Permissão ADMIN explícita
 *   - Para MEDICO, valida CRM/CPF/especialidade
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helpers.php';

exigirLogin();
exigirPerfil('ADMIN');

$acao   = $_GET['acao']   ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$user   = usuarioLogado();

// ============================================================
// LISTAR ESPECIALIDADES (para dropdown na criação de médico)
// ============================================================
if ($method === 'GET' && $acao === 'especialidades') {
    $stmt = $pdo->query(
        'SELECT id, nome FROM especialidades WHERE ativo = TRUE ORDER BY nome'
    );
    responder(200, ['status' => 'ok', 'especialidades' => $stmt->fetchAll()]);
}

// ============================================================
// LISTAR
// ============================================================
if ($method === 'GET' && $acao === 'listar') {
    $busca = trim($_GET['busca'] ?? '');

    if ($busca !== '') {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.nome, u.email, u.perfil, u.ativo,
                    u.ultimo_acesso, u.criado_em,
                    m.crm_numero, m.crm_uf, e.nome AS especialidade
               FROM usuarios u
          LEFT JOIN medicos       m ON m.usuario_id = u.id
          LEFT JOIN especialidades e ON e.id = m.especialidade_id
              WHERE u.nome LIKE :q OR u.email LIKE :q
              ORDER BY u.nome
              LIMIT 200'
        );
        $stmt->execute([':q' => "%$busca%"]);
    } else {
        $stmt = $pdo->query(
            'SELECT u.id, u.nome, u.email, u.perfil, u.ativo,
                    u.ultimo_acesso, u.criado_em,
                    m.crm_numero, m.crm_uf, e.nome AS especialidade
               FROM usuarios u
          LEFT JOIN medicos       m ON m.usuario_id = u.id
          LEFT JOIN especialidades e ON e.id = m.especialidade_id
              ORDER BY u.ativo DESC, u.nome'
        );
    }
    responder(200, ['status' => 'ok', 'usuarios' => $stmt->fetchAll()]);
}

// ============================================================
// BUSCAR
// ============================================================
if ($method === 'GET' && $acao === 'buscar') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare(
        'SELECT u.id, u.nome, u.email, u.perfil, u.ativo,
                u.ultimo_acesso, u.criado_em,
                m.id AS medico_id, m.cpf AS medico_cpf,
                m.crm_numero, m.crm_uf, m.especialidade_id,
                e.nome AS especialidade_nome
           FROM usuarios u
      LEFT JOIN medicos       m ON m.usuario_id = u.id
      LEFT JOIN especialidades e ON e.id = m.especialidade_id
          WHERE u.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $u = $stmt->fetch();
    if (!$u) responder(404, ['status' => 'erro', 'msg' => 'Usuário não encontrado.']);
    responder(200, ['status' => 'ok', 'usuario' => $u]);
}

// ============================================================
// Helpers
// ============================================================
function validarDadosUsuario(array $body, bool $exigeSenha): array {
    $erros = [];
    $nome   = trim($body['nome']  ?? '');
    $email  = trim($body['email'] ?? '');
    $perfil = trim($body['perfil'] ?? '');

    if ($nome === '')   $erros[] = 'nome é obrigatório';
    if ($email === '')  $erros[] = 'email é obrigatório';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros[] = 'email inválido';

    $perfisOk = ['ADMIN','MEDICO','RECEPCAO','TECNICO_ENFERMAGEM'];
    if (!in_array($perfil, $perfisOk, true)) $erros[] = 'perfil inválido';

    if ($exigeSenha) {
        $senha = $body['senha'] ?? '';
        if (strlen($senha) < 4) $erros[] = 'senha deve ter pelo menos 4 caracteres';
    }

    if ($erros) responder(400, ['status' => 'erro', 'msg' => implode('; ', $erros)]);

    return ['nome' => $nome, 'email' => $email, 'perfil' => $perfil];
}

function validarDadosMedico(array $body): array {
    $erros = [];
    $cpf   = preg_replace('/\D/', '', $body['medico_cpf'] ?? '');
    $crm   = trim($body['crm_numero'] ?? '');
    $uf    = strtoupper(trim($body['crm_uf'] ?? ''));
    $espId = (int)($body['especialidade_id'] ?? 0);

    if (!preg_match('/^\d{11}$/', $cpf)) $erros[] = 'CPF do médico deve ter 11 dígitos';
    if ($crm === '') $erros[] = 'CRM número é obrigatório';
    if (!preg_match('/^[A-Z]{2}$/', $uf)) $erros[] = 'CRM UF inválida (2 letras)';
    if ($espId <= 0) $erros[] = 'especialidade é obrigatória';

    if ($erros) responder(400, ['status' => 'erro', 'msg' => implode('; ', $erros)]);

    return [
        'cpf' => $cpf, 'crm_numero' => $crm,
        'crm_uf' => $uf, 'especialidade_id' => $espId,
    ];
}

// ============================================================
// CRIAR
// ============================================================
if ($method === 'POST' && $acao === 'criar') {
    $body = lerJson();
    $u = validarDadosUsuario($body, true);
    $senhaHash = password_hash($body['senha'], PASSWORD_BCRYPT);

    // Se for MEDICO, valida dados extras antes de iniciar transação
    $dadosMed = null;
    if ($u['perfil'] === 'MEDICO') {
        $dadosMed = validarDadosMedico($body);
        // Nome do médico = nome do usuário (regra simples; pode ser separado depois)
        $dadosMed['nome'] = $u['nome'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash, perfil)
             VALUES (:n, :e, :h, :p)'
        );
        $stmt->execute([
            ':n' => $u['nome'], ':e' => $u['email'],
            ':h' => $senhaHash, ':p' => $u['perfil'],
        ]);
        $novoId = (int)$pdo->lastInsertId();

        if ($dadosMed) {
            $stmt = $pdo->prepare(
                'INSERT INTO medicos
                    (usuario_id, cpf, nome, crm_numero, crm_uf, especialidade_id)
                 VALUES (:uid, :cpf, :nome, :crm, :uf, :esp)'
            );
            $stmt->execute([
                ':uid'  => $novoId,
                ':cpf'  => $dadosMed['cpf'],
                ':nome' => $dadosMed['nome'],
                ':crm'  => $dadosMed['crm_numero'],
                ':uf'   => $dadosMed['crm_uf'],
                ':esp'  => $dadosMed['especialidade_id'],
            ]);
        }

        $pdo->commit();
        responder(201, [
            'status' => 'ok',
            'msg'    => 'Usuário criado.',
            'id'     => $novoId,
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23000') {
            // Pode ser email duplicado, CPF duplicado, ou CRM duplicado
            $msg = $e->getMessage();
            if (stripos($msg, 'email') !== false) {
                responder(409, ['status' => 'erro', 'msg' => 'E-mail já cadastrado.']);
            } elseif (stripos($msg, 'cpf') !== false) {
                responder(409, ['status' => 'erro', 'msg' => 'CPF de médico já cadastrado.']);
            } elseif (stripos($msg, 'crm') !== false) {
                responder(409, ['status' => 'erro', 'msg' => 'CRM já cadastrado.']);
            }
            responder(409, ['status' => 'erro', 'msg' => 'Dados duplicados (e-mail, CPF ou CRM).']);
        }
        throw $e;
    }
}

// ============================================================
// ATUALIZAR (nome, email, perfil — não mexe na senha)
// ============================================================
if ($method === 'PUT' && $acao === 'atualizar') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    // Verifica se usuário existe
    $stmt = $pdo->prepare('SELECT id, perfil FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $orig = $stmt->fetch();
    if (!$orig) responder(404, ['status' => 'erro', 'msg' => 'Usuário não encontrado.']);

    $body = lerJson();
    $u = validarDadosUsuario($body, false);

    // Bloqueia mudança de perfil entre MEDICO ↔ outro (porque tem FK em medicos)
    if ($orig['perfil'] !== $u['perfil']
        && ($orig['perfil'] === 'MEDICO' || $u['perfil'] === 'MEDICO')) {
        responder(409, [
            'status' => 'erro',
            'msg'    => 'Não é permitido trocar perfil entre MEDICO e outro (afeta vínculo com CRM/consultas). Crie um novo usuário se necessário.',
        ]);
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE usuarios SET nome = :n, email = :e, perfil = :p WHERE id = :id'
        );
        $stmt->execute([
            ':n' => $u['nome'], ':e' => $u['email'],
            ':p' => $u['perfil'], ':id' => $id,
        ]);

        // Se MEDICO, atualiza dados do médico também
        if ($u['perfil'] === 'MEDICO') {
            $dadosMed = validarDadosMedico($body);
            $stmt = $pdo->prepare(
                'UPDATE medicos SET
                    cpf = :cpf, nome = :nome, crm_numero = :crm,
                    crm_uf = :uf, especialidade_id = :esp
                  WHERE usuario_id = :uid'
            );
            $stmt->execute([
                ':cpf'  => $dadosMed['cpf'], ':nome' => $u['nome'],
                ':crm'  => $dadosMed['crm_numero'], ':uf' => $dadosMed['crm_uf'],
                ':esp'  => $dadosMed['especialidade_id'], ':uid' => $id,
            ]);
        }

        responder(200, ['status' => 'ok', 'msg' => 'Usuário atualizado.']);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            responder(409, ['status' => 'erro', 'msg' => 'E-mail/CPF/CRM já existe em outro usuário.']);
        }
        throw $e;
    }
}

// ============================================================
// ALTERAR SENHA
// ============================================================
if ($method === 'PUT' && $acao === 'alterar_senha') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $senha = lerJson()['senha'] ?? '';
    if (strlen($senha) < 4) {
        responder(400, ['status' => 'erro', 'msg' => 'Senha deve ter pelo menos 4 caracteres.']);
    }
    $hash = password_hash($senha, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE usuarios SET senha_hash = :h WHERE id = :id');
    $stmt->execute([':h' => $hash, ':id' => $id]);
    if ($stmt->rowCount() === 0) {
        responder(404, ['status' => 'erro', 'msg' => 'Usuário não encontrado.']);
    }
    responder(200, ['status' => 'ok', 'msg' => 'Senha alterada.']);
}

// ============================================================
// DESATIVAR (soft delete)
// ============================================================
if ($method === 'DELETE' && $acao === 'desativar') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    // Bloqueia desativar a si mesmo (evita lockout total)
    if ($id === (int)$user['id']) {
        responder(409, [
            'status' => 'erro',
            'msg'    => 'Você não pode desativar seu próprio usuário (proteção contra lockout).',
        ]);
    }

    // Bloqueia desativar o último ADMIN ativo
    $stmt = $pdo->prepare('SELECT perfil FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $alvo = $stmt->fetch();
    if (!$alvo) responder(404, ['status' => 'erro', 'msg' => 'Usuário não encontrado.']);
    if ($alvo['perfil'] === 'ADMIN') {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM usuarios WHERE perfil = 'ADMIN' AND ativo = TRUE"
        );
        if ((int)$stmt->fetchColumn() <= 1) {
            responder(409, [
                'status' => 'erro',
                'msg'    => 'Não é possível desativar o último ADMIN ativo do sistema.',
            ]);
        }
    }

    $stmt = $pdo->prepare('UPDATE usuarios SET ativo = FALSE WHERE id = :id');
    $stmt->execute([':id' => $id]);
    responder(200, ['status' => 'ok', 'msg' => 'Usuário desativado.']);
}

// ============================================================
// REATIVAR
// ============================================================
if ($method === 'PUT' && $acao === 'reativar') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare('UPDATE usuarios SET ativo = TRUE WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) {
        responder(404, ['status' => 'erro', 'msg' => 'Usuário não encontrado.']);
    }
    responder(200, ['status' => 'ok', 'msg' => 'Usuário reativado.']);
}

responder(400, ['status' => 'erro', 'msg' => 'Ação desconhecida ou método inválido.']);
