<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

include 'config.php';

header("Content-Type: application/json");

$method = $_SERVER['REQUEST_METHOD'];

if ($method == "POST") {

    $data = json_decode(file_get_contents("php://input"));

    $nome = $data->nome;
    $data_nascimento = $data->data_nascimento;
    $peso = $data->peso;
    $altura = $data->altura;
    $pressao = $data->pressao;
    $diagnostico = $data->diagnostico;
    $receita = $data->receita;
    $observacoes = $data->observacoes;

    $conn->query("
        INSERT INTO prontuarios 
        (diagnostico, receita, observacoes, peso, altura, pressao) 
        VALUES 
        ('$diagnostico', '$receita', '$observacoes', '$peso', '$altura', '$pressao')
    ");

    echo json_encode(["msg" => "Prontuário salvo com sucesso"]);
}
?>