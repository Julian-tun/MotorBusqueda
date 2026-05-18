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

    'semantic_scholar_api_key' => getenv('SEMANTIC_SCHOLAR_API_KEY') ?: ($privateConfig['semantic_scholar_api_key'] ?? ''),

    'contact_email' => getenv('CONTACT_EMAIL') ?: ($privateConfig['contact_email'] ?? 'davidtunortiz2000@gmail.com'),

    'max_abstract_chars' => 5000,

    // Resumen de PDF: no se manda el PDF completo a IA en bruto.
    // Se extraen secciones importantes para cubrir lo relevante sin tardar demasiado.
    'max_pdf_chars' => 18000,
    'pdf_context_chars' => 22000,
    'pdf_section_chars' => 3500,

    'semantic_scholar_url' => 'https://api.semanticscholar.org/graph/v1/paper/search',
    'openalex_url' => 'https://api.openalex.org/works',
    'crossref_url' => 'https://api.crossref.org/works',
    'europe_pmc_url' => 'https://www.ebi.ac.uk/europepmc/webservices/rest/search'
];