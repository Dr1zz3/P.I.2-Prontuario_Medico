<?php
$dados = [
    "id" => 1,
    "nome" => "Matheus Atualizado",
    "email" => "novo@email.com"
];

$options = [
    "http" => [
        "method" => "PUT",
        "header" => "Content-Type: application/json",
        "content" => json_encode($dados)
    ]
];

$context = stream_context_create($options);

echo file_get_contents(
    "http://localhost/Prontuario_Medico/BackEnd/usuarios.php",
    false,
    $context
);
?>