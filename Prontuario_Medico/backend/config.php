<?php
$conn = new mysqli("localhost", "root", "1234", "Prontuario_Medico");

if ($conn->connect_error) {
    die("Erro na conexão: " . $conn->connect_error);
}
?>