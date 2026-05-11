<?php
$options = [
    "http" => [
        "method" => "DELETE"
    ]
];

$context = stream_context_create($options);

echo file_get_contents(
    "http://localhost/Prontuario_Medico/BackEnd/usuarios.php?id=1",
    false,
    $context
);
?>