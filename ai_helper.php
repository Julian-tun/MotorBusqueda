<?php
function json_response($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_config() {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config_app.php';
    }
    return $config;
}

function call_openai_chat($messages, $model = null, $temperature = 0.25) {
    $config = app_config();
    $apiKey = trim($config['openai_api_key'] ?? '');
    if ($apiKey === '') {
        return [
            'ok' => false,
            'status' => 500,
            'error' => 'La API Key del servidor no está configurada. Configura OPENAI_API_KEY o config_private.php.'
        ];
    }

    $payload = [
        'model' => $model ?: ($config['openai_model'] ?? 'gpt-4o-mini'),
        'temperature' => $temperature,
        'messages' => $messages
    ];

    $ch = curl_init($config['openai_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'status' => 500, 'error' => 'Error de conexión con IA: ' . $curlError];
    }

    $json = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $detail = $json['error']['message'] ?? $response;
        return ['ok' => false, 'status' => $httpCode, 'error' => 'La IA respondió con HTTP ' . $httpCode, 'details' => $detail];
    }

    if (!is_array($json)) {
        return ['ok' => false, 'status' => 502, 'error' => 'La respuesta de IA no es JSON válido.'];
    }

    $content = $json['choices'][0]['message']['content'] ?? '';
    if (trim($content) === '') {
        return ['ok' => false, 'status' => 502, 'error' => 'La IA no devolvió contenido.'];
    }

    return ['ok' => true, 'status' => 200, 'content' => $content, 'raw' => $json];
}
