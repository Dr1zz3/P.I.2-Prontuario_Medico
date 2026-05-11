<?php
include 'config.php';

header("Content-Type: application/json");

$method = $_SERVER['REQUEST_METHOD'];

if ($method == "GET") {
    $result = $conn->query("SELECT * FROM usuarios");

    $usuarios = [];

    while($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }

    echo json_encode($usuarios, JSON_PRETTY_PRINT);
}

if ($method == "POST") {
    $data = json_decode(file_get_contents("php://input"));

    $nome = $data->nome;
    $email = $data->email;
    $senha = $data->senha;
    $tipo = $data->tipo;

    $conn->query("INSERT INTO usuarios (nome, email, senha, tipo)
                  VALUES ('$nome', '$email', '$senha', '$tipo')");

    echo json_encode(["msg" => "Usuário criado"]);
}

if ($method == "DELETE") {
    $id = $_GET['id'];

    $conn->query("DELETE FROM usuarios WHERE id=$id");

    echo json_encode(["msg" => "Usuário deletado"]);
}

if ($method == "PUT") {
    $data = json_decode(file_get_contents("php://input"));

    $id = intval($data->id);
    $nome = $data->nome;
    $email = $data->email;
    $tipo = $data->tipo;

    if (isset($data->senha)) {
        $senha = $data->senha;
        $conn->query("UPDATE usuarios SET nome='$nome', email='$email', tipo='$tipo', senha='$senha' WHERE id=$id");
    } else {
        $conn->query("UPDATE usuarios SET nome='$nome', email='$email', tipo='$tipo' WHERE id=$id");
    }

    echo json_encode(["msg" => "Usuário atualizado"]);
}
?>