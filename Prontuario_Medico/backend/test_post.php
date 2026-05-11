<?php
$dados = [
    "nome" => "Matheus",
    "email" => "matheus@email.com",
    "senha" => "123",
    "tipo" => "paciente"
];

$options = [
    "http" => [
        "method" => "POST",
        "header" => "Content-Type: application/json",
        "content" => json_encode($dados)
    ]
];

$context = stream_context_create($options);

echo file_get_contents("http://localhost/Prontuario_Medico/BackEnd/usuarios.php", false, $context);
?>