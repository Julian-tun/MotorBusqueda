<?php
require_once __DIR__ . '/ai_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}

if (!isset($_FILES['pdfFile']) || $_FILES['pdfFile']['error'] !== 0) {
    json_response(['error' => 'No se recibió el PDF correctamente.'], 400);
}

$model = app_config()['openai_model'] ?? 'gpt-4o-mini';
$tempPdf = $_FILES['pdfFile']['tmp_name'];

try {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        json_response(['error' => 'No se encontró vendor/autoload.php. Ejecuta composer install.'], 500);
    }
    require_once __DIR__ . '/vendor/autoload.php';
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($tempPdf);
    $text = $pdf->getText();
} catch (Exception $e) {
    json_response(['error' => 'No se pudo extraer texto del PDF: ' . $e->getMessage()], 422);
}

$text = mb_substr($text, 0, (int)(app_config()['max_pdf_chars'] ?? 18000));
if (trim($text) === '') {
    json_response(['error' => 'El PDF no contiene texto legible para procesar.'], 422);
}

$paperId = md5($text);
$mongoLoaded = false;
if (file_exists(__DIR__ . '/mongo.php')) {
    require_once __DIR__ . '/mongo.php';
    $mongoLoaded = true;
    $cached = obtenerResumenCache($paperId);
    if ($cached && isset($cached['resumen'])) {
        json_response([
            'mensaje' => 'Resumen encontrado en caché.',
            'resumen' => $cached['resumen'],
            'paperId' => $paperId,
            'archivoResumen' => 'descargar_resumen.php?paperId=' . urlencode($paperId),
            'fuente' => 'MongoDB'
        ]);
    }
}

$prompt = "Resume el siguiente PDF científico en español. Incluye: resumen completo, metodología, hallazgos principales y conclusión. Si no encuentras una sección, no inventes datos.\n\nTexto:\n" . $text;
$result = call_openai_chat([
    ['role' => 'system', 'content' => 'Eres un asistente académico experto en resumir artículos científicos.'],
    ['role' => 'user', 'content' => $prompt]
], $model);

if (!$result['ok']) {
    json_response(['error' => $result['error'], 'details' => $result['details'] ?? null], $result['status']);
}

$resumen = $result['content'];
if ($mongoLoaded) {
    guardarResumenCache($paperId, 'PDF subido', $resumen);
}

json_response([
    'mensaje' => 'Resumen generado correctamente.',
    'resumen' => $resumen,
    'paperId' => $paperId,
    'archivoResumen' => 'descargar_resumen.php?paperId=' . urlencode($paperId),
    'fuente' => $mongoLoaded ? 'OpenAI + MongoDB' : 'OpenAI'
]);
