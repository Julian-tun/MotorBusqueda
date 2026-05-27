<?php
require_once __DIR__ . '/mongo.php';

$paperId = trim($_GET['paperId'] ?? '');
$formato = strtolower(trim($_GET['formato'] ?? 'txt'));

if ($paperId === '') {
    http_response_code(400);
    die('Error: No llegó el paperId.');
}

$doc = obtenerResumenCache($paperId);
if (!$doc || !isset($doc['resumen']) || trim((string)$doc['resumen']) === '') {
    http_response_code(404);
    die('Error: No existe ese resumen en MongoDB.');
}

$titulo = trim((string)($doc['titulo'] ?? 'Resumen Códice IA'));
$resumen = trim((string)$doc['resumen']);
$nombreSeguro = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', mb_substr($titulo, 0, 70, 'UTF-8'));
if ($nombreSeguro === '') {
    $nombreSeguro = 'resumen_' . preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $paperId);
}

function textoPlanoDescarga($titulo, $resumen) {
    return "CÓDICE IA - RESUMEN ACADÉMICO\n\n" .
        "Título: {$titulo}\n" .
        "Fecha de descarga: " . date('Y-m-d H:i') . "\n\n" .
        $resumen . "\n";
}

if ($formato === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreSeguro . '.txt"');
    echo textoPlanoDescarga($titulo, $resumen);
    exit;
}

if ($formato === 'pdf') {
    if (!class_exists('Dompdf\\Dompdf')) {
        http_response_code(501);
        die('Error: Dompdf no está instalado. Ejecuta: composer require dompdf/dompdf');
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,Arial,sans-serif;color:#142033;line-height:1.55;margin:36px;}
        .badge{color:#2563eb;font-weight:bold;text-transform:uppercase;font-size:12px;letter-spacing:.08em;}
        h1{font-size:24px;margin:8px 0 12px;}
        .meta{font-size:12px;color:#64748b;margin-bottom:24px;border-bottom:1px solid #dbe5f1;padding-bottom:12px;}
        pre{white-space:pre-wrap;font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;line-height:1.6;}
    </style></head><body>' .
        '<div class="badge">Códice IA</div>' .
        '<h1>' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h1>' .
        '<div class="meta">Resumen académico generado con IA · ' . date('Y-m-d H:i') . '</div>' .
        '<pre>' . htmlspecialchars($resumen, ENT_QUOTES, 'UTF-8') . '</pre>' .
    '</body></html>';

    $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => true]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream($nombreSeguro . '.pdf', ['Attachment' => true]);
    exit;
}

if ($formato === 'word' || $formato === 'docx') {
    if (!class_exists('PhpOffice\\PhpWord\\PhpWord')) {
        http_response_code(501);
        die('Error: PHPWord no está instalado. Ejecuta: composer require phpoffice/phpword');
    }

    $phpWord = new PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(11);
    $section = $phpWord->addSection();
    $section->addText('Códice IA - Resumen académico', ['bold' => true, 'color' => '2563EB', 'size' => 12]);
    $section->addText($titulo, ['bold' => true, 'size' => 18]);
    $section->addText('Fecha de descarga: ' . date('Y-m-d H:i'), ['color' => '64748B', 'size' => 9]);
    $section->addTextBreak(1);

    foreach (preg_split('/\R/u', $resumen) as $linea) {
        $linea = trim($linea);
        if ($linea === '') {
            $section->addTextBreak(1);
        } else {
            $section->addText($linea);
        }
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $nombreSeguro . '.docx"');
    header('Cache-Control: max-age=0');
    $writer = PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save('php://output');
    exit;
}

http_response_code(400);
die('Formato no permitido. Usa txt, pdf o word.');
