<?php
require_once __DIR__ . '/ai_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_response(['error' => 'Solicitud inválida.'], 400);
}

$title = trim($input['title'] ?? 'Artículo científico');
$pdfUrl = trim($input['pdfUrl'] ?? '');
$abstract = trim($input['abstract'] ?? '');
$paperId = trim($input['paperId'] ?? md5($title . $pdfUrl . $abstract));

if ($pdfUrl === '') {
    json_response(['error' => 'Este resultado no tiene PDF abierto disponible para resumir completo.'], 400);
}

$mongoLoaded = false;
if (file_exists(__DIR__ . '/mongo.php')) {
    require_once __DIR__ . '/mongo.php';
    $mongoLoaded = true;
    $cached = obtenerResumenCache($paperId);
    if ($cached && isset($cached['resumen']) && trim($cached['resumen']) !== '') {
        json_response([
            'mensaje' => 'Resumen encontrado en caché.',
            'resumen' => $cached['resumen'],
            'paperId' => $paperId,
            'archivoResumen' => 'descargar_resumen.php?paperId=' . urlencode($paperId),
            'fuente' => 'MongoDB'
        ]);
    }
}

$tempPdf = tempnam(sys_get_temp_dir(), 'paper_') . '.pdf';
$ch = curl_init($pdfUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_USERAGENT => 'MotorBusquedaAI/1.0',
    CURLOPT_SSL_VERIFYPEER => true
]);
$pdfBinary = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($curlError || $httpCode < 200 || $httpCode >= 300 || !$pdfBinary) {
    json_response(['error' => 'No se pudo descargar el PDF del artículo.', 'details' => $curlError ?: 'HTTP ' . $httpCode], 502);
}

file_put_contents($tempPdf, $pdfBinary);

try {
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        @unlink($tempPdf);
        json_response(['error' => 'No se encontró vendor/autoload.php. Ejecuta composer install.'], 500);
    }
    require_once __DIR__ . '/vendor/autoload.php';
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($tempPdf);
    $text = $pdf->getText();
} catch (Exception $e) {
    @unlink($tempPdf);
    json_response(['error' => 'No se pudo extraer texto del PDF.', 'details' => $e->getMessage()], 422);
}
@unlink($tempPdf);

$config = app_config();
$text = mb_substr($text, 0, (int)($config['max_pdf_chars'] ?? 18000));
if (trim($text) === '') {
    json_response(['error' => 'El PDF no contiene texto legible para procesar.'], 422);
}

$prompt = "Analiza el siguiente artículo científico y genera una respuesta profesional en español con esta estructura:\n\n1. Ficha rápida del artículo\n2. Resumen completo\n3. Objetivo del estudio\n4. Metodología\n5. Resultados o hallazgos principales\n6. Conclusión\n7. Palabras clave sugeridas\n\nSi alguna sección no aparece en el texto, indícalo sin inventar información.\n\nTítulo: {$title}\n\nAbstract disponible:\n{$abstract}\n\nTexto extraído del PDF:\n{$text}";

$result = call_openai_chat([
    ['role' => 'system', 'content' => 'Eres un asistente académico experto en lectura crítica de artículos científicos. No inventes datos.'],
    ['role' => 'user', 'content' => $prompt]
]);

if (!$result['ok']) {
    json_response(['error' => $result['error'], 'details' => $result['details'] ?? null], $result['status']);
}

$resumen = $result['content'];
if ($mongoLoaded) {
    guardarResumenCache($paperId, $title, $resumen);
}

json_response([
    'mensaje' => 'Resumen completo generado correctamente.',
    'resumen' => $resumen,
    'paperId' => $paperId,
    'archivoResumen' => 'descargar_resumen.php?paperId=' . urlencode($paperId),
    'fuente' => $mongoLoaded ? 'OpenAI + MongoDB' : 'OpenAI'
]);
