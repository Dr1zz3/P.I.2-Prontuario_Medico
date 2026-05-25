<?php
/**
 * SEED de medicamentos comuns brasileiros.
 *
 * Rode UMA vez:  http://localhost/prontuario_medico/backend/seed_medicamentos.php
 *
 * Insere ~35 medicamentos de uso frequente em consultório, separados entre
 * comuns e antibióticos.
 *
 * Após usar, mude JA_SEMEADO p/ true (ou apague o arquivo).
 */

const JA_SEMEADO = false;

require_once __DIR__ . '/config.php';

if (JA_SEMEADO) {
    responder(403, ['status' => 'erro', 'msg' => 'Seed desativado.']);
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM medicamentos')->fetchColumn();
if ($total > 0) {
    responder(409, [
        'status' => 'erro',
        'msg'    => "Já existem $total medicamentos. Seed não executou (evita duplicar).",
    ]);
}

// Formato: [nome_comercial, principio_ativo, apresentacao, fabricante, anvisa, e_antibiotico]
$medicamentos = [
    // ----- Analgésicos / Antitérmicos -----
    ['Novalgina',           'Dipirona sódica',          'comprimido 500mg',     'Sanofi',       null, 0],
    ['Tylenol',             'Paracetamol',              'comprimido 750mg',     'Janssen',      null, 0],
    ['Dorflex',             'Dipirona + Orfenadrina + Cafeína', 'comprimido', 'Sanofi',       null, 0],
    ['Aspirina',            'Ácido acetilsalicílico',   'comprimido 500mg',     'Bayer',        null, 0],

    // ----- Anti-inflamatórios -----
    ['Ibuprofeno',          'Ibuprofeno',               'comprimido 600mg',     'Genérico',     null, 0],
    ['Cataflam',            'Diclofenaco potássico',    'comprimido 50mg',      'Novartis',     null, 0],
    ['Voltaren',            'Diclofenaco sódico',       'comprimido 50mg',      'Novartis',     null, 0],
    ['Nimesulida',          'Nimesulida',               'comprimido 100mg',     'Genérico',     null, 0],
    ['Meticorten',          'Prednisona',               'comprimido 20mg',      'Schering',     null, 0],
    ['Decadron',            'Dexametasona',             'comprimido 4mg',       'Aché',         null, 0],

    // ----- Antialérgicos -----
    ['Claritin',            'Loratadina',               'comprimido 10mg',      'Bayer',        null, 0],
    ['Zyrtec',              'Cetirizina',               'comprimido 10mg',      'UCB',          null, 0],
    ['Allegra',             'Fexofenadina',             'comprimido 120mg',     'Sanofi',       null, 0],
    ['Polaramine',          'Dexclorfeniramina',        'comprimido 2mg',       'Schering',     null, 0],

    // ----- Gastrointestinais -----
    ['Omeprazol',           'Omeprazol',                'cápsula 20mg',         'Genérico',     null, 0],
    ['Pantoprazol',         'Pantoprazol',              'comprimido 40mg',      'Genérico',     null, 0],
    ['Ranitidina',          'Ranitidina',               'comprimido 150mg',     'Genérico',     null, 0],
    ['Buscopan composto',   'Escopolamina + Dipirona',  'comprimido',           'Boehringer',   null, 0],
    ['Plasil',              'Metoclopramida',           'comprimido 10mg',      'Sanofi',       null, 0],
    ['Dramin',              'Dimenidrinato',            'comprimido 100mg',     'Cosmed',       null, 0],
    ['Bromoprida',          'Bromoprida',               'comprimido 10mg',      'Genérico',     null, 0],

    // ----- Respiratórios -----
    ['Aerolin',             'Salbutamol',               'spray 100mcg/dose',    'GSK',          null, 0],
    ['Predsim',             'Prednisolona',             'solução oral 3mg/ml',  'EMS',          null, 0],

    // ----- Cardiovasculares / Metabólicos -----
    ['Atenolol',            'Atenolol',                 'comprimido 50mg',      'Genérico',     null, 0],
    ['Losartana',           'Losartana potássica',      'comprimido 50mg',      'Genérico',     null, 0],
    ['Sinvastatina',        'Sinvastatina',             'comprimido 20mg',      'Genérico',     null, 0],
    ['Glifage XR',          'Metformina',               'comprimido 500mg',     'Merck',        null, 0],

    // ============== ANTIBIÓTICOS ==============
    ['Amoxil',              'Amoxicilina',              'cápsula 500mg',        'GSK',          null, 1],
    ['Clavulin',            'Amoxicilina + Clavulanato','comprimido 500/125mg', 'GSK',          null, 1],
    ['Zitromax',            'Azitromicina',             'comprimido 500mg',     'Pfizer',       null, 1],
    ['Keflex',              'Cefalexina',               'cápsula 500mg',        'EMS',          null, 1],
    ['Cipro',               'Ciprofloxacino',           'comprimido 500mg',     'Bayer',        null, 1],
    ['Vibramicina',         'Doxiciclina',              'cápsula 100mg',        'Pfizer',       null, 1],
    ['Dalacin',             'Clindamicina',             'cápsula 300mg',        'Pfizer',       null, 1],
    ['Flagyl',              'Metronidazol',             'comprimido 400mg',     'Sanofi',       null, 1],
    ['Bactrim',             'Sulfametoxazol + Trimetoprima', 'comprimido 800+160mg', 'Roche', null, 1],
    ['Macrodantina',        'Nitrofurantoína',          'cápsula 100mg',        'Aché',         null, 1],
];

$stmt = $pdo->prepare(
    'INSERT INTO medicamentos
        (nome_comercial, principio_ativo, apresentacao, fabricante,
         registro_anvisa, e_antibiotico)
     VALUES (:nc, :pa, :ap, :fb, :ra, :ea)'
);

$pdo->beginTransaction();
try {
    foreach ($medicamentos as [$nc, $pa, $ap, $fb, $ra, $ea]) {
        $stmt->execute([
            ':nc' => $nc, ':pa' => $pa, ':ap' => $ap,
            ':fb' => $fb, ':ra' => $ra, ':ea' => $ea,
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    responder(500, ['status' => 'erro', 'msg' => 'Falha no seed: ' . $e->getMessage()]);
}

responder(200, [
    'status' => 'ok',
    'msg'    => count($medicamentos) . ' medicamentos cadastrados.',
    'comuns'       => count(array_filter($medicamentos, fn($m) => $m[5] === 0)),
    'antibioticos' => count(array_filter($medicamentos, fn($m) => $m[5] === 1)),
    'aviso'  => 'Apague este arquivo ou mude JA_SEMEADO p/ true.',
]);
