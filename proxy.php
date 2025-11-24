<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido"]);
    exit;
}

$apiKey = $_POST['apiKey'] ?? '';
$body   = $_POST['body'] ?? '';

if (!$apiKey || !$body) {
    http_response_code(400);
    echo json_encode(["error" => "Falta apiKey o body"]);
    exit;
}

set_time_limit(120); // permite hasta 2 minutos
$url = "https://api.openai.com/v1/chat/completions";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . trim($apiKey),
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT => 90,          // hasta 90 segundos totales
    CURLOPT_CONNECTTIMEOUT => 15,   // 15 segundos para conectar
    CURLOPT_SSL_VERIFYPEER => true, // aquui protejemos contra ataques  MITM
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// --- Manejo robusto de errores ---
if ($error) {
    http_response_code(500);
    echo json_encode(["error" => "Error cURL", "details" => $error]);
    exit;
}
if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode);
    echo json_encode(["error" => "HTTP $httpCode", "details" => $response]);
    exit;
}

// Validar que la respuesta sea JSON
$jsonTest = json_decode($response, true);
if ($jsonTest === null) {
    echo json_encode(["error" => "Respuesta no JSON válida", "raw" => $response]);
    exit;
}

echo $response;
