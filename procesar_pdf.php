<?php
require_once __DIR__ . '/ai_helper.php';

set_time_limit(180);
ini_set('max_execution_time', 180);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}

if (!isset($_FILES['pdfFile']) || $_FILES['pdfFile']['error'] !== 0) {
    json_response(['error' => 'No se recibió el PDF correctamente.'], 400);
}


function extraerTextoPdfSeguro($pdfPath) {
    if (!is_file($pdfPath)) {
        return ['ok' => false, 'text' => '', 'error' => 'El archivo PDF no existe.'];
    }

    // Primero usamos Poppler/pdftotext porque consume mucha menos memoria que PDFParser.
    $cmd = 'pdftotext -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' - 2>&1';
    $output = shell_exec($cmd);
    if (is_string($output)) {
        $text = trim($output);
        $lower = strtolower($text);
        $pareceError = str_contains($lower, 'syntax error') || str_contains($lower, 'command not found') || str_contains($lower, 'error:');
        if ($text !== '' && !$pareceError && mb_strlen($text, 'UTF-8') > 80) {
            return ['ok' => true, 'text' => $text, 'error' => null];
        }
    }

    // Respaldo controlado: PDFParser solo en PDFs pequeños para evitar agotar memoria en Render.
    $size = filesize($pdfPath) ?: 0;
    if ($size > 6 * 1024 * 1024) {
        return [
            'ok' => false,
            'text' => '',
            'error' => 'No se pudo extraer texto con pdftotext y el PDF es demasiado pesado para usar PDFParser sin agotar memoria.'
        ];
    }

    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        return ['ok' => false, 'text' => '', 'error' => 'No se encontró vendor/autoload.php. Ejecuta composer install.'];
    }

    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($pdfPath);
        $text = trim($pdf->getText());
        if ($text !== '') {
            return ['ok' => true, 'text' => $text, 'error' => null];
        }
        return ['ok' => false, 'text' => '', 'error' => 'El PDF no contiene texto legible.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'text' => '', 'error' => $e->getMessage()];
    }
}

function normalizarTextoPdf($text) {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text);
    return trim($text);
}

function recortarDesdeReferencias($text) {
    $len = mb_strlen($text, 'UTF-8');
    $inicioBusqueda = (int)($len * 0.45);
    $parteFinal = mb_substr($text, $inicioBusqueda, null, 'UTF-8');

    if (preg_match('/(?:^|\n)\s*(?:\d+\.?\s*)?(referencias|references|bibliograf[ií]a|literatura citada)\s*(?:\n|$)/iu', $parteFinal, $m, PREG_OFFSET_CAPTURE)) {
        return trim(mb_substr($text, 0, $inicioBusqueda + $m[0][1], 'UTF-8'));
    }

    return $text;
}

function limitarTexto($text, $maxChars) {
    $text = trim($text);
    if (mb_strlen($text, 'UTF-8') <= $maxChars) {
        return $text;
    }

    $cut = mb_substr($text, 0, $maxChars, 'UTF-8');
    $lastDot = max(
        mb_strrpos($cut, '.', 0, 'UTF-8') ?: 0,
        mb_strrpos($cut, "\n", 0, 'UTF-8') ?: 0
    );

    if ($lastDot > (int)($maxChars * 0.65)) {
        $cut = mb_substr($cut, 0, $lastDot + 1, 'UTF-8');
    }

    return trim($cut);
}

function encontrarSeccion($text, array $startPatterns, array $headingPatterns, $maxChars) {
    $startPos = null;
    $startLen = 0;

    foreach ($startPatterns as $pattern) {
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            if ($startPos === null || $pos < $startPos) {
                $startPos = $pos;
                $startLen = strlen($m[0][0]);
            }
        }
    }

    if ($startPos === null) {
        return '';
    }

    $contentStart = $startPos + $startLen;
    $searchFrom = $contentStart + 200;
    $endPos = null;

    foreach ($headingPatterns as $pattern) {
        $tail = substr($text, $searchFrom);
        if (preg_match($pattern, $tail, $m, PREG_OFFSET_CAPTURE)) {
            $candidate = $searchFrom + $m[0][1];
            if ($endPos === null || $candidate < $endPos) {
                $endPos = $candidate;
            }
        }
    }

    $section = $endPos !== null
        ? substr($text, $contentStart, $endPos - $contentStart)
        : substr($text, $contentStart);

    $section = normalizarTextoPdf($section);
    return limitarTexto($section, $maxChars);
}

function construirContextoImportante($text, $abstract, array $config) {
    $text = normalizarTextoPdf($text);
    $text = recortarDesdeReferencias($text);

    $maxTotal = (int)($config['pdf_context_chars'] ?? 22000);
    $maxPorSeccion = (int)($config['pdf_section_chars'] ?? 3500);

    $headingPatterns = [
        '/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(resumen|abstract)\s*(?:\n|:)/iu',
        '/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(introducci[oó]n|introduction)\s*(?:\n|:)/iu',
        '/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(marco te[oó]rico|related work|antecedentes)\s*(?:\n|:)/iu',
        '/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(metodolog[ií]a|materiales y m[eé]todos|m[eé]todo|methods?|methodology)\s*(?:\n|:)/iu',
        '/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(resultados|results|hallazgos|findings)\s*(?:\n|:)/iu',
        '/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(discusi[oó]n|discussion)\s*(?:\n|:)/iu',
        '/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(conclusiones?|conclusions?|conclusi[oó]n)\s*(?:\n|:)/iu',
        '/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(referencias|references|bibliograf[ií]a)\s*(?:\n|:)/iu'
    ];

    $sections = [];

    $definitions = [
        'Resumen/Abstract del PDF' => ['/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(resumen|abstract)\s*(?:\n|:)/iu'],
        'Introducción' => ['/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(introducci[oó]n|introduction)\s*(?:\n|:)/iu'],
        'Marco teórico o antecedentes' => ['/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(marco te[oó]rico|related work|antecedentes)\s*(?:\n|:)/iu'],
        'Metodología' => ['/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(metodolog[ií]a|materiales y m[eé]todos|m[eé]todo|methods?|methodology)\s*(?:\n|:)/iu'],
        'Resultados' => ['/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(resultados|results|hallazgos|findings)\s*(?:\n|:)/iu'],
        'Discusión' => ['/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(discusi[oó]n|discussion)\s*(?:\n|:)/iu'],
        'Conclusiones' => ['/(?:^|\n)\s*(?:\d+(?:\.\d+)*\.?\s*)?(conclusiones?|conclusions?|conclusi[oó]n)\s*(?:\n|:)/iu'],
    ];

    foreach ($definitions as $label => $patterns) {
        $sectionText = encontrarSeccion($text, $patterns, $headingPatterns, $maxPorSeccion);
        if ($sectionText !== '') {
            $sections[] = "### {$label}\n" . $sectionText;
        }
    }

    $context = '';

    if (trim($abstract) !== '') {
        $context .= "### Abstract recuperado\n" . limitarTexto($abstract, 2500) . "\n\n";
    }

    if (!empty($sections)) {
        $context .= implode("\n\n", $sections);
    }

    if (mb_strlen($context, 'UTF-8') < 7000) {
        $len = mb_strlen($text, 'UTF-8');
        $chunk = (int)min(6500, max(2500, $maxTotal / 3));

        $inicio = limitarTexto(mb_substr($text, 0, $chunk, 'UTF-8'), $chunk);
        $medioStart = max(0, (int)(($len - $chunk) / 2));
        $medio = limitarTexto(mb_substr($text, $medioStart, $chunk, 'UTF-8'), $chunk);
        $finalStart = max(0, $len - $chunk);
        $final = limitarTexto(mb_substr($text, $finalStart, $chunk, 'UTF-8'), $chunk);

        $context .= "\n\n### Muestra complementaria del inicio del PDF\n{$inicio}";
        if ($medio !== $inicio) {
            $context .= "\n\n### Muestra complementaria de la parte media del PDF\n{$medio}";
        }
        if ($final !== $inicio && $final !== $medio) {
            $context .= "\n\n### Muestra complementaria de la parte final del PDF\n{$final}";
        }
    }

    return limitarTexto($context, $maxTotal);
}

$model = app_config()['openai_model'] ?? 'gpt-4o-mini';
$tempPdf = $_FILES['pdfFile']['tmp_name'];
$title = pathinfo($_FILES['pdfFile']['name'] ?? 'PDF subido', PATHINFO_FILENAME);

$extraccion = extraerTextoPdfSeguro($tempPdf);
if (!$extraccion['ok']) {
    json_response(['error' => 'No se pudo extraer texto del PDF: ' . $extraccion['error']], 422);
}
$text = $extraccion['text'];

$config = app_config();
$text = normalizarTextoPdf($text);

if (trim($text) === '') {
    json_response(['error' => 'El PDF no contiene texto legible para procesar.'], 422);
}

$contextoImportante = construirContextoImportante($text, '', $config);

if (trim($contextoImportante) === '') {
    json_response(['error' => 'No se pudo construir contexto suficiente del PDF para resumir.'], 422);
}

$paperId = 'pdf_upload_sections_v1_' . md5($title . '|' . $contextoImportante);

$mongoLoaded = false;
if (file_exists(__DIR__ . '/mongo.php')) {
    require_once __DIR__ . '/mongo.php';
    $mongoLoaded = true;

    if (function_exists('guardarTextoArticulo')) {
        guardarTextoArticulo($paperId, $title, $text, $contextoImportante, 'subida_pdf');
    }

    $cached = obtenerResumenCache($paperId);
    if ($cached && isset($cached['resumen']) && trim($cached['resumen']) !== '') {
        json_response([
            'mensaje' => 'Resumen encontrado en caché.',
            'resumen' => $cached['resumen'],
            'paperId' => $paperId,
            'archivoResumen' => 'descargar_resumen.php?paperId=' . urlencode($paperId),
            'fuente' => 'MongoDB'
        ]);
    }
}

$prompt = "Analiza el siguiente artículo científico y genera una respuesta profesional, clara y limpia en español.\n\n" .
    "REGLAS IMPORTANTES:\n" .
    "1. Usa únicamente la información proporcionada.\n" .
    "2. No inventes resultados, cifras, autores ni conclusiones.\n" .
    "3. El texto fue seleccionado automáticamente desde las secciones más importantes del PDF para evitar demoras, priorizando abstract, introducción, metodología, resultados, discusión y conclusiones.\n" .
    "4. Si algún dato no aparece, escribe: No especificado en el texto proporcionado.\n" .
    "5. No uses formato Markdown. No uses numerales #, asteriscos **, guiones simples - ni tablas Markdown.\n" .
    "6. Usa títulos limpios con numeración, y para listas usa viñetas con el símbolo •.\n\n" .
    "FORMATO EXACTO DE SALIDA:\n" .
    "1. Ficha rápida del artículo\n" .
    "• Título: ...\n" .
    "• Autores: ...\n" .
    "• Fecha de publicación: ...\n" .
    "• Tipo de documento: ...\n" .
    "• Área temática: ...\n\n" .
    "2. Resumen completo e integrado\n" .
    "Escribe uno o dos párrafos claros.\n\n" .
    "3. Objetivo del estudio\n" .
    "Explica el objetivo principal.\n\n" .
    "4. Metodología\n" .
    "Explica el método usado.\n\n" .
    "5. Resultados o hallazgos principales\n" .
    "Usa viñetas con •.\n\n" .
    "6. Conclusión\n" .
    "Explica la conclusión principal.\n\n" .
    "7. Aportes importantes\n" .
    "Usa viñetas con •.\n\n" .
    "8. Limitaciones detectadas, si aparecen\n" .
    "Indica las limitaciones o escribe que no se especifican.\n\n" .
    "9. Palabras clave sugeridas\n" .
    "Usa viñetas con •.\n\n" .
    "Título: {$title}\n\n" .
    "Contenido seleccionado del PDF:\n{$contextoImportante}";

$result = call_openai_chat([
    ['role' => 'system', 'content' => 'Eres un asistente académico experto en lectura crítica de artículos científicos. Resume con precisión, no inventes información y entrega texto limpio sin Markdown.'],
    ['role' => 'user', 'content' => $prompt]
], $model);

if (!$result['ok']) {
    json_response(['error' => $result['error'], 'details' => $result['details'] ?? null], $result['status']);
}

$resumen = $result['content'];
$mongoGuardado = false;
$mongoError = null;

if ($mongoLoaded) {
    $mongoGuardado = guardarResumenCache($paperId, $title, $resumen);
    if (!$mongoGuardado && function_exists('getUltimoErrorMongo')) {
        $mongoError = getUltimoErrorMongo();
    }
}

json_response([
    'mensaje' => $mongoGuardado
        ? 'Resumen generado y guardado correctamente.'
        : 'Resumen generado, pero NO se pudo guardar en MongoDB.',
    'resumen' => $resumen,
    'paperId' => $paperId,
    'archivoResumen' => $mongoGuardado ? 'descargar_resumen.php?paperId=' . urlencode($paperId) : null,
    'fuente' => $mongoGuardado ? 'OpenAI + MongoDB' : 'OpenAI',
    'mongoGuardado' => $mongoGuardado,
    'mongoError' => $mongoError
]);