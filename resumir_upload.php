<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
header("Content-Type: text/html; charset=utf-8");

if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== 0) {
    die("⚠️ Error al subir el PDF.");
}

$apiKey = $_POST['apiKey'] ?? '';
if (!$apiKey) die("⚠️ Falta API Key de OpenAI.");

$model = $_POST['model'] ?? 'gpt-4o-mini';
$temp = $_FILES['pdf']['tmp_name'];

// Extraer texto
$text = '';
if (file_exists('/usr/bin/pdftotext') || file_exists('C:\\xpdf\\pdftotext.exe')) {
    $txtFile = tempnam(sys_get_temp_dir(), 'txt');
    $cmd = "pdftotext " . escapeshellarg($temp) . " " . escapeshellarg($txtFile);
    exec($cmd);
    $text = @file_get_contents($txtFile);
    @unlink($txtFile);
} else {
    $text = "(PDF subido, pero no se pudo extraer texto completo)";
}

$text = substr($text, 0, 15000);

// Preparar prompt
$prompt = "Resume este artículo científico completo. 
1. Resumen breve (3–5 oraciones)
2. Metodología
3. Conclusión

Texto del artículo:
$text";

// Llamada a OpenAI
$data = [
    "model" => $model,
    "messages" => [
        ["role" => "system", "content" => "Eres un asistente que resume artículos científicos."],
        ["role" => "user", "content" => $prompt]
    ]
];

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
curl_close($ch);

if ($err) die("⚠️ Error cURL: $err");

$json = json_decode($response, true);
$resumen = $json['choices'][0]['message']['content'] ?? "(No se pudo generar resumen)";

// Guardar resumen
if (!is_dir("resumenes")) mkdir("resumenes");
$resumenFile = "resumenes/resumen_" . time() . ".txt";
file_put_contents($resumenFile, $resumen);

// Mostrar resultado
echo "<h2>✅ Resumen generado</h2>";
echo "<pre>" . htmlspecialchars($resumen) . "</pre>";
echo "<p><a href='$resumenFile' download>Descargar resumen</a></p>";
echo "<p><a href='javascript:history.back()'>Volver</a></p>";
