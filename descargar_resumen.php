<?php
require_once __DIR__ . '/mongo.php';

if (!isset($_GET['paperId'])) {
    http_response_code(400);
    die("❌ Falta el parámetro 'paperId'.");
}

$paperId = $_GET['paperId'];
$doc = obtenerResumenCache($paperId);

if (!$doc || !isset($doc['resumen'])) {
    http_response_code(404);
    die("⚠️ Resumen no encontrado en la base de datos.");
}

// Forzar descarga como archivo de texto
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="resumen_' . $paperId . '.txt"');

echo $doc['resumen'];
?>
