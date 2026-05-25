<?php
/**
 * API de Pacientes — CRUD completo.
 *
 * Endpoints:
 *   GET    ?acao=listar [&busca=texto]   → lista pacientes ativos (filtro nome/cpf)
 *   GET    ?acao=buscar&id=N             → 1 paciente pelo id
 *   POST   ?acao=criar                   → cadastra (RECEPCAO/ADMIN)
 *   PUT    ?acao=atualizar&id=N          → atualiza (RECEPCAO/ADMIN/MEDICO)
 *   DELETE ?acao=desativar&id=N          → soft delete (RECEPCAO/ADMIN)
 *
 * Regras:
 *   - CPF é único — backend trata UNIQUE violation com mensagem amigável
 *   - Desativar = ativo = FALSE (paciente continua no histórico de consultas)
 *   - Médico pode editar dados clínicos (alergias, tipo sanguíneo) mas não desativar
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helpers.php';

exigirLogin();

$acao   = $_GET['acao']   ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET LISTAR
// ============================================================
if ($method === 'GET' && $acao === 'listar') {
    $busca = trim($_GET['busca'] ?? '');

    if ($busca !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, nome, cpf, data_nascimento, telefone, email, sexo
               FROM pacientes
              WHERE ativo = TRUE
                AND (nome LIKE :q OR cpf LIKE :q)
              ORDER BY nome
              LIMIT 100'
        );
        $stmt->execute([':q' => "%$busca%"]);
    } else {
        $stmt = $pdo->query(
            'SELECT id, nome, cpf, data_nascimento, telefone, email, sexo
               FROM pacientes
              WHERE ativo = TRUE
              ORDER BY nome
              LIMIT 100'
        );
    }
    responder(200, ['status' => 'ok', 'pacientes' => $stmt->fetchAll()]);
}

// ============================================================
// GET BUSCAR
// ============================================================
if ($method === 'GET' && $acao === 'buscar') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare(
        'SELECT p.*, c.nome AS convenio_nome
           FROM pacientes p
      LEFT JOIN convenios c ON c.id = p.convenio_id
          WHERE p.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();
    if (!$p) responder(404, ['status' => 'erro', 'msg' => 'Paciente não encontrado.']);
    responder(200, ['status' => 'ok', 'paciente' => $p]);
}

// ============================================================
// Helpers de validação
// ============================================================
function limpar(string $s): string { return trim($s); }
function limparCpf(string $cpf): string { return preg_replace('/\D/', '', $cpf); }
function limparCep(string $cep): string { return preg_replace('/\D/', '', $cep); }

/** Validação simples de CPF (formato — não dígito verificador, p/ projeto acadêmico) */
function cpfFormatoValido(string $cpf): bool {
    return preg_match('/^\d{11}$/', $cpf) === 1;
}

function montarDadosPaciente(array $body, bool $exigirCpf = true): array {
    $erros = [];

    $nome = limpar($body['nome'] ?? '');
    if ($nome === '') $erros[] = 'nome é obrigatório';

    $cpf = limparCpf($body['cpf'] ?? '');
    if ($exigirCpf) {
        if ($cpf === '')                  $erros[] = 'CPF é obrigatório';
        elseif (!cpfFormatoValido($cpf))  $erros[] = 'CPF deve ter 11 dígitos';
    }

    $dataNasc = limpar($body['data_nascimento'] ?? '');
    if ($dataNasc === '') $erros[] = 'data de nascimento é obrigatória';
    elseif (!DateTime::createFromFormat('Y-m-d', $dataNasc)) {
        $erros[] = 'data de nascimento deve estar no formato YYYY-MM-DD';
    }

    $sexo = strtoupper(limpar($body['sexo'] ?? ''));
    if (!in_array($sexo, ['M', 'F', 'O'], true)) $erros[] = 'sexo deve ser M, F ou O';

    if ($erros) responder(400, ['status' => 'erro', 'msg' => implode('; ', $erros)]);

    return [
        'cpf'             => $cpf,
        'nome'            => $nome,
        'nome_social'     => limpar($body['nome_social']     ?? '') ?: null,
        'data_nascimento' => $dataNasc,
        'sexo'            => $sexo,
        'rg'              => limpar($body['rg']              ?? '') ?: null,
        'cartao_sus'      => limpar($body['cartao_sus']      ?? '') ?: null,
        'email'           => limpar($body['email']           ?? '') ?: null,
        'telefone'        => limpar($body['telefone']        ?? '') ?: null,
        'cep'             => limparCep($body['cep']          ?? '') ?: null,
        'logradouro'      => limpar($body['logradouro']      ?? '') ?: null,
        'numero'          => limpar($body['numero']          ?? '') ?: null,
        'complemento'     => limpar($body['complemento']     ?? '') ?: null,
        'bairro'          => limpar($body['bairro']          ?? '') ?: null,
        'cidade'          => limpar($body['cidade']          ?? '') ?: null,
        'uf'              => strtoupper(limpar($body['uf']   ?? '')) ?: null,
        'tipo_sanguineo'  => limpar($body['tipo_sanguineo']  ?? '') ?: null,
        'alergias'        => limpar($body['alergias']        ?? '') ?: null,
        'convenio_id'     => isset($body['convenio_id']) && $body['convenio_id'] !== ''
                              ? (int)$body['convenio_id'] : null,
        'numero_convenio' => limpar($body['numero_convenio'] ?? '') ?: null,
        'consentimento_lgpd' => !empty($body['consentimento_lgpd']),
    ];
}

// ============================================================
// POST CRIAR
// ============================================================
if ($method === 'POST' && $acao === 'criar') {
    exigirPerfil('RECEPCAO', 'ADMIN');
    $d = montarDadosPaciente(lerJson(), true);

    $sql = 'INSERT INTO pacientes
            (cpf, nome, nome_social, data_nascimento, sexo, rg, cartao_sus,
             email, telefone, cep, logradouro, numero, complemento, bairro,
             cidade, uf, tipo_sanguineo, alergias, convenio_id, numero_convenio,
             consentimento_lgpd, data_consentimento)
            VALUES
            (:cpf, :nome, :nome_social, :data_nascimento, :sexo, :rg, :cartao_sus,
             :email, :telefone, :cep, :logradouro, :numero, :complemento, :bairro,
             :cidade, :uf, :tipo_sanguineo, :alergias, :convenio_id, :numero_convenio,
             :lgpd, :data_lgpd)';
    try {
        $stmt = $pdo->prepare($sql);
        $params = $d;
        $params['lgpd']      = $d['consentimento_lgpd'] ? 1 : 0;
        $params['data_lgpd'] = $d['consentimento_lgpd'] ? date('Y-m-d H:i:s') : null;
        unset($params['consentimento_lgpd']);

        $stmt->execute($params);
        responder(201, [
            'status' => 'ok',
            'msg'    => 'Paciente cadastrado.',
            'id'     => (int)$pdo->lastInsertId(),
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { // violation de unique
            responder(409, ['status' => 'erro', 'msg' => 'Já existe um paciente com esse CPF.']);
        }
        throw $e;
    }
}

// ============================================================
// PUT ATUALIZAR
// ============================================================
if ($method === 'PUT' && $acao === 'atualizar') {
    exigirPerfil('RECEPCAO', 'ADMIN', 'MEDICO');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    // Verifica se existe
    $stmt = $pdo->prepare('SELECT id, consentimento_lgpd FROM pacientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $orig = $stmt->fetch();
    if (!$orig) responder(404, ['status' => 'erro', 'msg' => 'Paciente não encontrado.']);

    $d = montarDadosPaciente(lerJson(), true);

    // Se LGPD não estava marcado e agora foi, registra data_consentimento
    $dataLgpd = null;
    if ($d['consentimento_lgpd'] && !$orig['consentimento_lgpd']) {
        $dataLgpd = date('Y-m-d H:i:s');
    }

    $sql = 'UPDATE pacientes SET
              cpf = :cpf, nome = :nome, nome_social = :nome_social,
              data_nascimento = :data_nascimento, sexo = :sexo, rg = :rg,
              cartao_sus = :cartao_sus, email = :email, telefone = :telefone,
              cep = :cep, logradouro = :logradouro, numero = :numero,
              complemento = :complemento, bairro = :bairro, cidade = :cidade,
              uf = :uf, tipo_sanguineo = :tipo_sanguineo, alergias = :alergias,
              convenio_id = :convenio_id, numero_convenio = :numero_convenio,
              consentimento_lgpd = :lgpd'
         . ($dataLgpd ? ', data_consentimento = :data_lgpd' : '')
         . ' WHERE id = :id';
    try {
        $stmt = $pdo->prepare($sql);
        $params = $d;
        $params['lgpd'] = $d['consentimento_lgpd'] ? 1 : 0;
        unset($params['consentimento_lgpd']);
        if ($dataLgpd) $params['data_lgpd'] = $dataLgpd;
        $params['id'] = $id;
        $stmt->execute($params);
        responder(200, ['status' => 'ok', 'msg' => 'Paciente atualizado.']);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            responder(409, ['status' => 'erro', 'msg' => 'CPF já cadastrado em outro paciente.']);
        }
        throw $e;
    }
}

// ============================================================
// DELETE DESATIVAR (soft delete)
// ============================================================
if ($method === 'DELETE' && $acao === 'desativar') {
    exigirPerfil('RECEPCAO', 'ADMIN');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) responder(400, ['status' => 'erro', 'msg' => 'id é obrigatório.']);

    $stmt = $pdo->prepare('UPDATE pacientes SET ativo = FALSE WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) {
        responder(404, ['status' => 'erro', 'msg' => 'Paciente não encontrado.']);
    }
    responder(200, ['status' => 'ok', 'msg' => 'Paciente desativado.']);
}

responder(400, ['status' => 'erro', 'msg' => 'Ação desconhecida ou método inválido.']);
