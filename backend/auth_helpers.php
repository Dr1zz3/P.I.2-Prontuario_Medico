<?php
/**
 * Helpers de autorização — incluir no topo de toda API protegida.
 *
 * Uso:
 *     require_once __DIR__ . '/config.php';
 *     require_once __DIR__ . '/auth_helpers.php';
 *     exigirLogin();
 *     exigirPerfil('MEDICO');                  // só médico
 *     exigirPerfil('MEDICO', 'TECNICO_ENFERMAGEM'); // qualquer um dos dois
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Retorna o usuário logado (array com id/nome/email/perfil) ou null.
 */
function usuarioLogado(): ?array {
    return $_SESSION['usuario'] ?? null;
}

/**
 * Bloqueia com 401 se não houver sessão ativa.
 */
function exigirLogin(): void {
    if (!isset($_SESSION['usuario'])) {
        responder(401, ['status' => 'erro', 'msg' => 'Faça login para continuar.']);
    }
}

/**
 * Bloqueia com 403 se o perfil do usuário não estiver na lista permitida.
 * Os valores aceitos batem com o ENUM `perfil` em usuarios.
 */
function exigirPerfil(string ...$perfisPermitidos): void {
    exigirLogin();
    $perfil = $_SESSION['usuario']['perfil'] ?? '';
    if (!in_array($perfil, $perfisPermitidos, true)) {
        responder(403, [
            'status' => 'erro',
            'msg'    => 'Seu perfil não tem permissão para esta ação.',
        ]);
    }
}
