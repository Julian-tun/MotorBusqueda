<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar warnings
header("Content-Type: application/json");

require 'mongo.php'; // ✅ Importar conexión MongoDB

$url = $_GET['url'] ?? null;
if (!$url) {
    echo json_encode(["error" => "No se recibió URL"]);
    exit;
}

// ✅ Generar un ID único basado en la URL (sirve como "paperId")
$paperId = md5($url);
$titulo = basename($url); // puedes mejorarlo si tienes el título real

// ✅ 1. Verificar si el resumen ya está guardado en MongoDB
$resumenCache = obtenerResumenCache($paperId);
if ($resumenCache) {
    echo json_encode([
        "mensaje" => "✅ Resumen en caché encontrado",
        "resumen" => $resumenCache['resumen'],
        "fuente" => "MongoDB"
    ]);
    exit;
}

// Descargar PDF temporal usando cURL
$temp = "temp_" . time() . ".pdf";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
$pdfContent = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || $http != 200 || !$pdfContent) {
    echo json_encode(["error" => "No se pudo descargar el PDF"]);
    exit;
}

file_put_contents($temp, $pdfContent);

// Convertir PDF a texto
$text = '';
if (file_exists('/usr/bin/pdftotext') || file_exists('C:\\xpdf\\pdftotext.exe')) {
    $txtFile = "temp_" . time() . ".txt";
    $cmd = "pdftotext " . escapeshellarg($temp) . " " . escapeshellarg($txtFile);
    exec($cmd);
    $text = @file_get_contents($txtFile);
    @unlink($txtFile);
} else {
    $text = "(PDF descargado, pero no se pudo extraer texto completo, se usará un resumen parcial)";
}
unlink($temp);

// Limitar texto si es muy grande
$text = substr($text, 0, 15000);

// Generar resumen con OpenAI
$apiKey = $_GET['apiKey'] ?? '';
if (!$apiKey) {
    echo json_encode(["error" => "Falta API Key de OpenAI"]);
    exit;
}

$model = $_GET['model'] ?? 'gpt-4o-mini';

$prompt = "Resume este artículo científico completo. 
1. Resumen breve (3–5 oraciones)
2. Metodología
3. Conclusión

Texto del artículo:
$text";

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

if ($err) {
    echo json_encode(["error" => "cURL error: $err"]);
    exit;
}

$json = json_decode($response, true);
$resumen = $json['choices'][0]['message']['content'] ?? "(No se pudo generar resumen)";

// Guardar archivo resumen local
if (!is_dir("resumenes")) mkdir("resumenes");
$resumenFile = "resumenes/resumen_" . time() . ".txt";
file_put_contents($resumenFile, $resumen);

// ✅ 2. Guardar resumen en MongoDB
guardarResumenCache($paperId, $titulo, $resumen);

echo json_encode([
    "mensaje" => "🧠 Resumen generado y guardado en MongoDB",
    "resumen" => $resumen,
    "archivoResumen" => $resumenFile,
    "fuente" => "OpenAI + guardado en NoSQL"
]);
