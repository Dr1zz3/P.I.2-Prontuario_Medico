<?php
include 'config.php';

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"));

$email = $data->email;
$senha = $data->senha;

$result = $conn->query("SELECT * FROM usuarios WHERE email='$email' AND senha='$senha'");

if ($result->num_rows > 0) {
    echo json_encode(["msg" => "Login realizado", "status" => "ok"]);
} else {
    echo json_encode(["msg" => "Email ou senha inválidos", "status" => "erro"]);
}
?>