<?php
require_once __DIR__ . '/ai_helper.php';
require_once __DIR__ . '/mongo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_response(['error' => 'JSON inválido.'], 400);
}

$paperId = trim($input['paperId'] ?? '');
$tipo = trim($input['tipo'] ?? 'general');
$pregunta = trim($input['pregunta'] ?? 'Explícame el artículo con más detalle');

if ($paperId === '') {
    json_response(['error' => 'No llegó el paperId.'], 400);
}

$resumenDoc = obtenerResumenCache($paperId);
if (!$resumenDoc || empty($resumenDoc['resumen'])) {
    json_response(['error' => 'No se encontró el resumen del artículo.'], 404);
}

$textoDoc = obtenerTextoArticulo($paperId);
$titulo = (string)($resumenDoc['titulo'] ?? 'Artículo sin título');
$resumen = (string)$resumenDoc['resumen'];
$textoBase = '';

if ($textoDoc) {
    $textoBase = (string)($textoDoc['textoCompleto'] ?? '');
    if (trim($textoBase) === '') {
        $textoBase = (string)($textoDoc['textoContexto'] ?? '');
    }
}

if (trim($textoBase) === '') {
    $textoBase = $resumen;
}

function normalizarChat($text) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    return trim($text);
}

function limitarChat($text, $maxChars) {
    $text = normalizarChat($text);
    if (mb_strlen($text, 'UTF-8') <= $maxChars) return $text;
    return trim(mb_substr($text, 0, $maxChars, 'UTF-8'));
}

function palabrasClaveTipo($tipo) {
    $map = [
        'metodologia' => ['metodología','método','methods','methodology','materiales','procedimiento','muestra','instrumento','enfoque','análisis','participantes','datos'],
        'resultados' => ['resultados','results','hallazgos','findings','efecto','impacto','mejora','evidencia','datos','tabla','figura'],
        'conclusion' => ['conclusión','conclusiones','conclusion','conclusions','discusión','discussion','finalmente','se concluye'],
        'objetivo' => ['objetivo','objetivos','aim','objective','propósito','purpose','busca','analizar','determinar'],
        'limitaciones' => ['limitación','limitaciones','limitations','limitantes','restricción','futuras investigaciones','future work'],
        'aportes' => ['aporte','aportes','contribución','contribuciones','contribution','implicaciones','importancia','relevancia'],
        'simple' => ['resumen','abstract','introducción','conclusión','resultados'],
        'palabras_clave' => ['palabras clave','keywords','términos','conceptos','resumen','abstract','introducción']
    ];
    return $map[$tipo] ?? ['resumen','abstract','introducción','metodología','resultados','conclusión'];
}

function extraerFragmentos($texto, $tipo, $maxTotal = 16000) {
    $texto = normalizarChat($texto);
    if ($texto === '') return '';

    $keywords = palabrasClaveTipo($tipo);
    $lower = mb_strtolower($texto, 'UTF-8');
    $chunks = [];

    foreach ($keywords as $kw) {
        $kwLower = mb_strtolower($kw, 'UTF-8');
        $offset = 0;
        while (($pos = mb_strpos($lower, $kwLower, $offset, 'UTF-8')) !== false && count($chunks) < 8) {
            $start = max(0, $pos - 1600);
            $piece = mb_substr($texto, $start, 3600, 'UTF-8');
            $chunks[] = $piece;
            $offset = $pos + mb_strlen($kwLower, 'UTF-8') + 1200;
        }
    }

    if (empty($chunks)) {
        $len = mb_strlen($texto, 'UTF-8');
        $chunks[] = mb_substr($texto, 0, 5200, 'UTF-8');
        if ($len > 9000) $chunks[] = mb_substr($texto, max(0, (int)(($len - 5200) / 2)), 5200, 'UTF-8');
        if ($len > 14000) $chunks[] = mb_substr($texto, max(0, $len - 5200), 5200, 'UTF-8');
    }

    $unicos = [];
    foreach ($chunks as $c) {
        $c = normalizarChat($c);
        $hash = md5(mb_substr($c, 0, 500, 'UTF-8'));
        if (!isset($unicos[$hash])) $unicos[$hash] = $c;
    }

    return limitarChat(implode("\n\n--- FRAGMENTO ---\n\n", array_values($unicos)), $maxTotal);
}

$fragmentos = extraerFragmentos($textoBase, $tipo);
if (trim($fragmentos) === '') {
    $fragmentos = limitarChat($resumen, 8000);
}

$prompt = "Título del artículo: {$titulo}\n\n" .
    "Pregunta/frase del usuario: {$pregunta}\n\n" .
    "Tipo de análisis solicitado: {$tipo}\n\n" .
    "Resumen ya generado:\n{$resumen}\n\n" .
    "Fragmentos relevantes del texto legible del artículo:\n{$fragmentos}\n\n" .
    "Instrucciones:\n" .
    "1. Responde en español claro y académico.\n" .
    "2. Céntrate únicamente en lo que pidió el usuario.\n" .
    "3. Usa la información del texto y del resumen. No inventes datos, autores, cifras ni resultados.\n" .
    "4. Si el artículo no contiene suficiente información sobre esa sección, dilo claramente.\n" .
    "5. Puedes usar Markdown básico solo para negritas con **texto**, subtítulos con ### y viñetas con •. No uses bloques de código.\n" .
    "6. Da una explicación más detallada que el resumen original.";

$result = call_openai_chat([
    ['role' => 'system', 'content' => 'Eres un chatbot académico de Códice IA. Tu trabajo es profundizar secciones de artículos científicos usando solo el texto proporcionado.'],
    ['role' => 'user', 'content' => $prompt]
], null, 0.2);

if (!$result['ok']) {
    json_response(['error' => $result['error'], 'details' => $result['details'] ?? null], $result['status']);
}

json_response([
    'respuesta' => trim($result['content']),
    'usaTextoCompleto' => $textoDoc ? true : false
]);
