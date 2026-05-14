<?php
require_once __DIR__ . '/ai_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$abstract = trim($input['abstract'] ?? '');
if ($abstract === '') {
    json_response(['error' => 'No llegó el abstract para resumir.'], 400);
}

$config = app_config();
$abstract = mb_substr($abstract, 0, (int)($config['max_abstract_chars'] ?? 5000));

$prompt = "Analiza el siguiente abstract de un artículo científico y responde en español con formato claro:\n\n1. Resumen breve\n2. Metodología detectada\n3. Conclusión principal\n4. Por qué podría ser útil para una investigación\n\nSi el abstract no menciona metodología, indícalo de forma breve.\n\nAbstract:\n" . $abstract;

$result = call_openai_chat([
    ['role' => 'system', 'content' => 'Eres un asistente académico experto en resumir artículos científicos de forma clara y confiable.'],
    ['role' => 'user', 'content' => $prompt]
]);

if (!$result['ok']) {
    json_response(['error' => $result['error'], 'details' => $result['details'] ?? null], $result['status']);
}

json_response(['resumen' => $result['content']]);
