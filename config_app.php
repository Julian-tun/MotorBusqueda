<?php
/**
 * Configuracion general de MotorBusqueda AI.
 * IMPORTANTE: no subas claves reales a GitHub.
 * Define OPENAI_API_KEY como variable de entorno o crea config_private.php localmente.
 */

$privateConfigPath = __DIR__ . '/config_private.php';
$privateConfig = file_exists($privateConfigPath) ? require $privateConfigPath : [];

return [
    'openai_api_key' => getenv('OPENAI_API_KEY') ?: ($privateConfig['openai_api_key'] ?? ''),
    'openai_model' => getenv('OPENAI_MODEL') ?: ($privateConfig['openai_model'] ?? 'gpt-4o-mini'),
    'openai_url' => 'https://api.openai.com/v1/chat/completions',
    'max_abstract_chars' => 5000,
    'max_pdf_chars' => 18000,
    'semantic_scholar_url' => 'https://api.semanticscholar.org/graph/v1/paper/search'
];
