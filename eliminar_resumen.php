<?php
require_once __DIR__ . '/ai_helper.php';
require_once __DIR__ . '/mongo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido.'], 405);
}

$paperId = trim($_POST['paperId'] ?? '');
if ($paperId === '') {
    json_response(['error' => 'No llegó el paperId.'], 400);
}

$ok = eliminarResumenCache($paperId);
if (!$ok) {
    json_response(['error' => getUltimoErrorMongo() ?: 'No se pudo eliminar el resumen.'], 500);
}

json_response(['ok' => true]);
