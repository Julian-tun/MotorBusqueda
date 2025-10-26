<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json");

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

$url = "https://api.openai.com/v1/chat/completions";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $apiKey",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

$response = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    http_response_code(500);
    echo json_encode(["error" => "cURL error", "details" => $err]);
    exit;
}

if ($http !== 200) {
    http_response_code($http);
    echo json_encode(["error" => "HTTP $http", "details" => $response]);
    exit;
}

echo $response;
