<?php
// procesar_pdf.php mejorado
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 1); // mostrar errores para depuración
ob_start();

// Función para devolver error en JSON y terminar
function json_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $msg]);
    ob_end_flush();
    exit;
}

// Validar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error("Método no permitido", 405);
}

// Validar PDF
if (!isset($_FILES['pdfFile']) || $_FILES['pdfFile']['error'] !== 0) {
    json_error("No se recibió el PDF correctamente");
}

// Validar API Key y modelo
$apiKey = $_POST['apiKey'] ?? '';
$model  = $_POST['model'] ?? 'gpt-4o-mini';
if (!$apiKey) json_error("Falta API Key de OpenAI");

// Guardar PDF temporal
$tempPdf = 'temp_' . time() . '.pdf';
if (!move_uploaded_file($_FILES['pdfFile']['tmp_name'], $tempPdf)) {
    json_error("No se pudo guardar el PDF temporal");
}

// Extraer texto
$text = '';
try {
    if (!file_exists('vendor/autoload.php')) {
        json_error("No se encontró Composer Autoload (vendor/autoload.php). Instala smalot/pdfparser con Composer.");
    }
    require 'vendor/autoload.php';
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($tempPdf);
    $text = $pdf->getText();
} catch (Exception $e) {
    unlink($tempPdf);
    json_error("No se pudo extraer texto del PDF: " . $e->getMessage());
}
unlink($tempPdf);

// Limitar texto
$text = substr($text, 0, 15000);
if (trim($text) === '') {
    json_error("El PDF no contiene texto legible para procesar");
}

// Preparar prompt mejorado para OpenAI
$prompt = "Eres un asistente experto en resumir artículos científicos. Analiza el PDF y genera un resumen completo:

1. Resumen: proporciónalo según la cantidad de información, siendo más extenso si hay mucho contenido y conciso si es poco.
2. Metodología: describe claramente cómo se realizó el estudio.
3. Conclusión: presenta los hallazgos principales y relevancia del estudio.

Asegúrate de cubrir todos los puntos importantes y mantener coherencia.

Texto del artículo:
$text";

$data = [
    "model" => $model,
    "messages" => [
        ["role" => "system", "content" => "Eres un asistente que resume artículos científicos."],
        ["role" => "user", "content" => $prompt]
    ]
];

// Llamada a la API de OpenAI
$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $apiKey",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($err) {
    json_error("cURL error: $err", 500);
}
if ($http !== 200) {
    json_error("OpenAI API respondió con HTTP $http: $response", $http);
}

// Decodificar JSON de OpenAI
$json = json_decode($response, true);
if (!$json) {
    json_error("Respuesta de OpenAI no es JSON válido: " . substr($response,0,500));
}

$resumen = $json['choices'][0]['message']['content'] ?? "(No se pudo generar resumen)";

// Guardar resumen
if (!is_dir("resumenes")) mkdir("resumenes");
$resumenFile = "resumenes/resumen_" . time() . ".txt";
file_put_contents($resumenFile, $resumen);

// Limpiar buffer y enviar JSON
ob_end_clean();
echo json_encode([
    "resumen" => $resumen,
    "archivoResumen" => $resumenFile
]);
exit;
