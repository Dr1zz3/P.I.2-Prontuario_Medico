<?php
/**
 * SCRIPT DE INSTALAÇÃO INICIAL — rode UMA vez após criar o banco
 * (executar schema.sql primeiro!).
 *
 * Acesse no navegador:  http://localhost/prontuario_medico/backend/instalar.php
 *
 * Cria:
 *   - 4 usuários de teste (um por perfil), senha "123456"
 *   - 1 clínica (timbre)
 *   - 1 especialidade (Clínica Geral)
 *   - 1 médico vinculado ao usuário "bruna@clinica.com"
 *   - 1 paciente de exemplo (Davi Silva)
 *
 * Após usar:
 *   - delete este arquivo, ou
 *   - mude $JA_INSTALADO para true abaixo.
 */

const JA_INSTALADO = false;

require_once __DIR__ . '/config.php';

if (JA_INSTALADO) {
    responder(403, ['status' => 'erro', 'msg' => 'Instalador desativado.']);
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
if ($total > 0) {
    responder(409, [
        'status' => 'erro',
        'msg'    => "Já existem $total usuários cadastrados. Instalador não executou.",
    ]);
}

$senhaHash = password_hash('123456', PASSWORD_BCRYPT);

$pdo->beginTransaction();
try {

    // ---------- 1. Clínica (singleton) ----------
    $pdo->prepare(
        'INSERT INTO clinica (id, nome, telefone, email, cep, logradouro, numero, bairro, cidade, uf)
         VALUES (1, :nome, :tel, :email, :cep, :log, :num, :bai, :cid, :uf)'
    )->execute([
        ':nome'  => 'Clínica MED+CLIN',
        ':tel'   => '(61) 3333-4444',
        ':email' => 'contato@medclin.com',
        ':cep'   => '70000000',
        ':log'   => 'SHIS QL 10',
        ':num'   => '100',
        ':bai'   => 'Lago Sul',
        ':cid'   => 'Brasília',
        ':uf'    => 'DF',
    ]);

    // ---------- 2. Especialidade ----------
    $pdo->prepare('INSERT INTO especialidades (nome) VALUES (:n)')
        ->execute([':n' => 'Clínica Geral']);
    $especialidadeId = (int)$pdo->lastInsertId();

    // ---------- 3. Usuários ----------
    $usuarios = [
        ['Admin do Sistema',  'admin@clinica.com',     'ADMIN'],
        ['Dra. Bruna Silva',  'bruna@clinica.com',     'MEDICO'],
        ['Carla Recepção',    'carla@clinica.com',     'RECEPCAO'],
        ['Tania Enfermagem',  'tania@clinica.com',     'TECNICO_ENFERMAGEM'],
    ];

    $insUsuario = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, perfil)
         VALUES (:nome, :email, :hash, :perfil)'
    );
    $ids = [];
    foreach ($usuarios as [$nome, $email, $perfil]) {
        $insUsuario->execute([
            ':nome' => $nome, ':email' => $email,
            ':hash' => $senhaHash, ':perfil' => $perfil,
        ]);
        $ids[$email] = (int)$pdo->lastInsertId();
    }

    // ---------- 4. Médico (registro CRM) vinculado à usuária Bruna ----------
    $pdo->prepare(
        'INSERT INTO medicos (usuario_id, cpf, nome, crm_numero, crm_uf, especialidade_id)
         VALUES (:uid, :cpf, :nome, :crm, :uf, :esp)'
    )->execute([
        ':uid'  => $ids['bruna@clinica.com'],
        ':cpf'  => '11122233344',
        ':nome' => 'Bruna Silva',
        ':crm'  => '12345',
        ':uf'   => 'DF',
        ':esp'  => $especialidadeId,
    ]);

    // ---------- 5. Paciente de exemplo ----------
    $pdo->prepare(
        'INSERT INTO pacientes
         (cpf, nome, data_nascimento, sexo, email, telefone,
          cep, logradouro, numero, bairro, cidade, uf,
          consentimento_lgpd, data_consentimento)
         VALUES
         (:cpf, :nome, :nasc, :sexo, :email, :tel,
          :cep, :log, :num, :bai, :cid, :uf,
          TRUE, NOW())'
    )->execute([
        ':cpf'  => '99988877766',
        ':nome' => 'Davi Silva Souza',
        ':nasc' => '2002-04-15',
        ':sexo' => 'M',
        ':email'=> 'davi.exemplo@email.com',
        ':tel'  => '(61) 99999-0000',
        ':cep'  => '71000000',
        ':log'  => 'QI 21',
        ':num'  => '12',
        ':bai'  => 'Guará',
        ':cid'  => 'Brasília',
        ':uf'   => 'DF',
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    responder(500, ['status' => 'erro', 'msg' => 'Falha na instalação: ' . $e->getMessage()]);
}

responder(200, [
    'status' => 'ok',
    'msg'    => 'Instalação concluída.',
    'criado' => [
        'usuarios'   => 4,
        'medicos'    => 1,
        'pacientes'  => 1,
        'clinica'    => 1,
    ],
    'login_padrao' => [
        'email' => 'qualquer um dos 4',
        'senha' => '123456',
    ],
    'aviso' => 'IMPORTANTE: apague este arquivo (instalar.php) ou mude JA_INSTALADO p/ true.',
]);
