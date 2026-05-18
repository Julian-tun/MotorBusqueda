<?php
require_once __DIR__ . '/ai_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Método no permitido. Usa GET.'], 405);
}

$query = trim($_GET['query'] ?? $_GET['q'] ?? '');
$limit = (int)($_GET['limit'] ?? 10);
$limit = max(1, min($limit, 20));

if ($query === '') {
    json_response(['error' => 'Escribe un tema de búsqueda.'], 400);
}

$config = app_config();
$apiKey = trim($config['semantic_scholar_api_key'] ?? '');

if ($apiKey === '') {
    json_response([
        'error' => 'La API Key de Semantic Scholar no está configurada. Agrega semantic_scholar_api_key en config_private.php o SEMANTIC_SCHOLAR_API_KEY como variable de entorno.'
    ], 500);
}

$fields = 'paperId,title,authors,year,abstract,url,openAccessPdf,citationCount,venue,externalIds';
$url = ($config['semantic_scholar_url'] ?? 'https://api.semanticscholar.org/graph/v1/paper/search')
    . '?query=' . urlencode($query)
    . '&limit=' . $limit
    . '&fields=' . urlencode($fields);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $apiKey,
        'Accept: application/json'
    ],
    CURLOPT_USERAGENT => 'MotorBusquedaAI/1.0'
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    json_response([
        'error' => 'No se pudo conectar con Semantic Scholar.',
        'details' => $curlError
    ], 502);
}

$json = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300) {
    $detail = is_array($json) ? ($json['message'] ?? $json['error'] ?? $response) : $response;
    json_response([
        'error' => 'Semantic Scholar respondió con HTTP ' . $httpCode . '.',
        'details' => $detail
    ], $httpCode === 429 ? 429 : 502);
}

if (!is_array($json)) {
    json_response([
        'error' => 'Semantic Scholar no devolvió JSON válido.',
        'details' => substr((string)$response, 0, 300)
    ], 502);
}

$papers = [];
foreach (($json['data'] ?? []) as $paper) {
    if (empty($paper['title'])) {
        continue;
    }

    $papers[] = [
        'paperId' => $paper['paperId'] ?? '',
        'title' => $paper['title'] ?? 'Sin título',
        'authors' => $paper['authors'] ?? [],
        'year' => $paper['year'] ?? '',
        'abstract' => $paper['abstract'] ?? '',
        'url' => $paper['url'] ?? '',
        'openAccessPdf' => $paper['openAccessPdf'] ?? null,
        'citationCount' => $paper['citationCount'] ?? null,
        'venue' => $paper['venue'] ?? '',
        'externalIds' => $paper['externalIds'] ?? [],
        'source' => 'Semantic Scholar'
    ];
}

json_response([
    'data' => $papers,
    'total' => count($papers),
    'source' => 'Semantic Scholar'
]);
