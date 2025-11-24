<?php

require_once __DIR__ . '/mongo.php';

// 1. Verifica que venga paperId
if (!isset($_GET['paperId']) || $_GET['paperId'] === "") {
    http_response_code(400);
    die("Error: No llegó el paperId.");
}

$paperId = $_GET['paperId'];

// 2. Busca el resumen en Mongo
$doc = obtenerResumenCache($paperId);

if (!$doc) {
    http_response_code(404);
    die("Error: No existe ese paperId en MongoDB.");
}

if (!isset($doc['resumen']) || trim($doc['resumen']) === "") {
    http_response_code(404);
    die("Error: Ese documento existe, pero no tiene 'resumen'.");
}

// 3. Descargar como archivo de texto
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="resumen_' . $paperId . '.txt"');

echo $doc['resumen'];
?>
