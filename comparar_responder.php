<?php
require_once __DIR__ . '/ai_helper.php';
require_once __DIR__ . '/mongo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_response(['error' => 'JSON inválido.'], 400);
}

$paperIds = $input['paperIds'] ?? [];
if (!is_array($paperIds)) {
    json_response(['error' => 'paperIds debe ser una lista.'], 400);
}

$docs = obtenerResumenesPorIds($paperIds);
if (count($docs) < 2) {
    json_response(['error' => 'Selecciona al menos 2 artículos para comparar.'], 400);
}

$bloques = [];
$contador = 1;
foreach ($docs as $doc) {
    $titulo = trim((string)($doc['titulo'] ?? 'Artículo sin título'));
    $resumen = trim((string)($doc['resumen'] ?? ''));
    if ($resumen === '') continue;
    $bloques[] = "ARTÍCULO {$contador}\nTítulo: {$titulo}\nResumen:\n{$resumen}";
    $contador++;
}

if (count($bloques) < 2) {
    json_response(['error' => 'Los artículos seleccionados no tienen resumen suficiente.'], 400);
}

$contenido = implode("\n\n=========================\n\n", $bloques);

$prompt = "Compara los siguientes artículos científicos usando únicamente sus resúmenes.\n\n" .
    "No inventes información. Si algún aspecto no aparece, escribe: No especificado en el resumen.\n" .
    "Entrega una respuesta clara en español con este formato:\n\n" .
    "1. Tabla comparativa REAL en formato Markdown. No uses guiones para simular tabla.\n" .
    "Columnas obligatorias: Artículo, Tema central, Objetivo, Metodología, Resultados, Conclusión.\n" .
    "Ejemplo de formato: | Artículo | Tema central | Objetivo | Metodología | Resultados | Conclusión |\n" .
    "Debajo debe ir la línea separadora de Markdown con guiones por columna.\n\n" .
    "2. Similitudes principales\n" .
    "Usa viñetas con •.\n\n" .
    "3. Diferencias principales\n" .
    "Usa viñetas con •.\n\n" .
    "4. Análisis académico final\n" .
    "Explica cuál aporta más según el objetivo, método y resultados reportados.\n\n" .
    "RESÚMENES:\n{$contenido}";

$result = call_openai_chat([
    ['role' => 'system', 'content' => 'Eres un analista académico experto en comparar artículos científicos. No inventes datos.'],
    ['role' => 'user', 'content' => $prompt]
], null, 0.2);

if (!$result['ok']) {
    json_response(['error' => $result['error'], 'details' => $result['details'] ?? null], $result['status']);
}

json_response(['comparacion' => trim($result['content'])]);
