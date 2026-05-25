/**
 * Lógica de sessão compartilhada pelos dashboards.
 *
 * Uso (em cada dashboard.html):
 *   <script src="../assets/js/sessao.js"></script>
 *   <script>
 *     window.addEventListener('DOMContentLoaded', () => {
 *         verificarSessao('MEDICO');          // perfil exigido (ou lista)
 *     });
 *   </script>
 *
 * Faz duas coisas:
 *   1. Confere com o backend se a sessão está válida; senão, manda pro login.
 *   2. Confere se o perfil do usuário tem permissão pra ver esta tela.
 *   3. Preenche elementos com id "user-nome" e "user-perfil".
 */

const API_AUTH = '../backend/auth.php';

/** Mapa de perfis → rótulo amigável */
const ROTULOS_PERFIL = {
    'ADMIN':              'Administrador',
    'MEDICO':             'Médico(a)',
    'RECEPCAO':           'Recepção',
    'TECNICO_ENFERMAGEM': 'Téc. Enfermagem',
};

/**
 * Verifica sessão e, opcionalmente, exige perfil.
 * @param  {...string} perfisPermitidos  ex.: 'MEDICO' ou 'MEDICO', 'ADMIN'
 */
async function verificarSessao(...perfisPermitidos) {
    try {
        const resp = await fetch(API_AUTH + '?acao=me', {
            credentials: 'include',
        });
        if (!resp.ok) {
            window.location.href = 'login.html';
            return;
        }
        const json = await resp.json();
        const u = json.usuario;

        if (perfisPermitidos.length > 0 && !perfisPermitidos.includes(u.perfil)) {
            alert('Seu perfil não tem acesso a esta tela.');
            window.location.href = 'login.html';
            return;
        }

        // Preenche header se existir
        const nomeEl   = document.getElementById('user-nome');
        const perfilEl = document.getElementById('user-perfil');
        if (nomeEl)   nomeEl.textContent   = u.nome;
        if (perfilEl) perfilEl.textContent = ROTULOS_PERFIL[u.perfil] ?? u.perfil;
    } catch (err) {
        console.error('Erro ao verificar sessão:', err);
        window.location.href = 'login.html';
    }
}

/** Logout: chama o backend e volta pro login. */
async function fazerLogout() {
    try {
        await fetch(API_AUTH + '?acao=logout', {
            method: 'POST',
            credentials: 'include',
        });
    } catch (_) { /* ignora — vamos sair de qualquer jeito */ }
    window.location.href = 'login.html';
}
