<?php
/**
 * Autenticação e controle de sessão.
 *
 * Endpoints:
 *   POST /auth.php?acao=login    body: { email, senha }
 *   POST /auth.php?acao=logout
 *   GET  /auth.php?acao=me       (devolve usuário logado, se houver)
 *
 * Diferenças vs. login.php original:
 *   - Prepared statements (sem SQL injection)
 *   - Senhas com password_hash / password_verify (não texto puro)
 *   - Cria sessão PHP com perfil — base para autorização nas outras APIs
 *   - Devolve perfil pro frontend escolher o dashboard certo
 */

require_once __DIR__ . '/config.php';

session_start();

$acao   = $_GET['acao'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// LOGIN
// ============================================================
if ($acao === 'login' && $method === 'POST') {
    $dados = lerJson();
    $email = trim($dados['email'] ?? '');
    $senha = $dados['senha'] ?? '';

    if ($email === '' || $senha === '') {
        responder(400, ['status' => 'erro', 'msg' => 'Email e senha são obrigatórios.']);
    }

    $stmt = $pdo->prepare(
        'SELECT id, nome, email, senha_hash, perfil, ativo
           FROM usuarios
          WHERE email = :email
          LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !$user['ativo'] || !password_verify($senha, $user['senha_hash'])) {
        responder(401, ['status' => 'erro', 'msg' => 'Email ou senha inválidos.']);
    }

    // Regenera o ID de sessão pra evitar fixation
    session_regenerate_id(true);

    $_SESSION['usuario'] = [
        'id'     => (int)$user['id'],
        'nome'   => $user['nome'],
        'email'  => $user['email'],
        'perfil' => $user['perfil'],
    ];

    // Atualiza último acesso
    $pdo->prepare('UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id')
        ->execute([':id' => $user['id']]);

    responder(200, [
        'status'  => 'ok',
        'msg'     => 'Login realizado.',
        'usuario' => $_SESSION['usuario'],
    ]);
}

// ============================================================
// LOGOUT
// ============================================================
if ($acao === 'logout' && $method === 'POST') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    responder(200, ['status' => 'ok', 'msg' => 'Sessão encerrada.']);
}

// ============================================================
// ME (verificar sessão atual)
// ============================================================
if ($acao === 'me' && $method === 'GET') {
    if (!isset($_SESSION['usuario'])) {
        responder(401, ['status' => 'erro', 'msg' => 'Não autenticado.']);
    }
    responder(200, ['status' => 'ok', 'usuario' => $_SESSION['usuario']]);
}

// ============================================================
// Ação inválida
// ============================================================
responder(400, ['status' => 'erro', 'msg' => 'Ação desconhecida ou método inválido.']);


// ============================================================
// HELPERS DE AUTORIZAÇÃO (usados pelas outras APIs)
//
// Carregue este arquivo no topo das outras APIs com:
//     require_once __DIR__ . '/auth_helpers.php';
// (vou separar isso num arquivo próprio em seguida pra não
//  precisar incluir esse com lógica de endpoint.)
// ============================================================
