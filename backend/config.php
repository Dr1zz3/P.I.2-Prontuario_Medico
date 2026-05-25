<?php
/**
 * Configuração de conexão com o banco de dados.
 *
 * Usa PDO com prepared statements REAIS (ATTR_EMULATE_PREPARES = false)
 * para eliminar SQL injection — diferente do mysqli concatenado do
 * projeto original.
 *
 * Toda outra script PHP do backend DEVE começar com:
 *     require_once __DIR__ . '/config.php';
 * e usar a variável global $pdo.
 */

// --- Parâmetros de conexão (ajuste se seu XAMPP usar outra senha) ---
const DB_HOST    = '127.0.0.1';
const DB_PORT    = 3306;
const DB_NAME    = 'prontuario_medico';
const DB_USER    = 'root';
const DB_PASS    = '';
const DB_CHARSET = 'utf8mb4';

// --- Conexão PDO ---
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
);

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $e) {
    // Em produção real esse log iria pra arquivo, não pra resposta.
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'erro',
        'msg'    => 'Falha ao conectar no banco.',
        // 'debug'  => $e->getMessage(), // descomente p/ diagnóstico
    ]);
    exit;
}

// --- Headers padrão das APIs JSON ---
header('Content-Type: application/json; charset=utf-8');

/**
 * Lê o corpo da requisição como JSON e devolve array associativo.
 * Retorna [] se não houver body ou JSON inválido.
 */
function lerJson(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $dados = json_decode($raw, true);
    return is_array($dados) ? $dados : [];
}

/**
 * Resposta JSON padronizada e encerra o script.
 */
function responder(int $httpStatus, array $payload): void {
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
