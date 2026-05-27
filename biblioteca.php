<?php
require_once __DIR__ . '/mongo.php';

$q = trim($_GET['q'] ?? '');
$resumenes = listarResumenesCache(100, $q);
$mongoError = function_exists('getUltimoErrorMongo') ? getUltimoErrorMongo() : null;

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fechaDoc($doc) {
    if (isset($doc['fecha_generacion'])) return (string)$doc['fecha_generacion'];
    if (isset($doc['updated_at']) && method_exists($doc['updated_at'], 'toDateTime')) return $doc['updated_at']->toDateTime()->format('Y-m-d H:i');
    return 'Sin fecha';
}

function recorte($txt, $max = 260) {
    $txt = trim((string)$txt);
    if (mb_strlen($txt, 'UTF-8') <= $max) return $txt;
    return mb_substr($txt, 0, $max, 'UTF-8') . '...';
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Biblioteca | Códice IA</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="page-shell">
    <header class="hero">
      <nav class="topbar">
        <div class="brand">
          <div class="logo-slot" aria-label="Logo de Códice IA">
            <img src="img/Códice IA.png" alt="Logo Códice IA" class="brand-logo">
          </div>
          <div><strong></strong><small></small></div>
        </div>
        <div class="nav-actions">
          <button class="nav-link back-link" type="button" data-back-link>← Regresar</button>
          <a class="nav-link" href="index.html">Buscador</a>
          <a class="nav-link" href="subir_pdf.php">Subir PDF</a>
        </div>
      </nav>

      <section class="hero-grid">
        <div class="hero-copy">
          <span class="eyebrow">Biblioteca inteligente</span>
          <h1>Consulta, exporta y compara los artículos resumidos con Códice IA.</h1>
          <p>Los resúmenes se leen desde MongoDB Atlas. Desde aquí puedes descargar en distintos formatos, abrir el chatbot académico o comparar artículos seleccionados.</p>
        </div>
        <aside class="hero-card">
          <div class="metric"><span><?= count($resumenes) ?></span><p>Resúmenes encontrados en la biblioteca.</p></div>
          <div class="metric"><span>PRO</span><p>Chatbot por secciones y comparación inteligente.</p></div>
        </aside>
      </section>
    </header>

    <main>
      <section class="paper-card library-toolbar">
        <form method="get" class="library-search">
          <label for="q">Buscar en biblioteca</label>
          <div class="search-row">
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="Buscar por título...">
            <button type="submit">Buscar</button>
          </div>
        </form>
      </section>

      <?php if ($mongoError && empty($resumenes)): ?>
        <section class="error-card"><strong>Error MongoDB</strong><p><?= h($mongoError) ?></p></section>
      <?php endif; ?>

      <?php if (empty($resumenes)): ?>
        <section class="empty-state"><h3>No hay resúmenes guardados todavía.</h3><p>Genera un resumen desde el buscador o sube un PDF para que aparezca aquí.</p></section>
      <?php else: ?>
        <form method="post" action="comparar_articulos.php" id="compareForm" class="library-list">
          <div class="compare-sticky">
            <span>Selecciona 2 o más artículos para comparar.</span>
            <button class="btn" type="submit">Comparar artículos</button>
          </div>

          <?php foreach ($resumenes as $doc):
            $paperId = (string)($doc['paperId'] ?? '');
            $titulo = (string)($doc['titulo'] ?? 'Artículo sin título');
            $resumen = (string)($doc['resumen'] ?? '');
          ?>
            <article class="paper-card library-card">
              <div class="library-select">
                <label class="check-card"><input type="checkbox" name="paperIds[]" value="<?= h($paperId) ?>"> Comparar</label>
                <span><?= h(fechaDoc($doc)) ?></span>
              </div>
              <h3><?= h($titulo) ?></h3>
              <p class="authors">ID: <?= h($paperId) ?></p>
              <div class="abstract-box"><p><?= h(recorte($resumen)) ?></p></div>
              <details class="abstract-box library-details">
                <summary>Ver resumen completo</summary>
                <pre><?= h($resumen) ?></pre>
              </details>
              <div class="actions">
                <a class="btn secondary" href="descargar_formato.php?paperId=<?= urlencode($paperId) ?>&formato=txt">TXT</a>
                <a class="btn secondary" href="descargar_formato.php?paperId=<?= urlencode($paperId) ?>&formato=pdf">PDF</a>
                <a class="btn secondary" href="descargar_formato.php?paperId=<?= urlencode($paperId) ?>&formato=word">Word</a>
                <a class="btn" href="chatbot_articulo.php?paperId=<?= urlencode($paperId) ?>">Chatbot IA</a>
                <button class="ghost-btn pro-lock" type="button" data-feature="Reporte académico automático">Reporte PRO</button>
                <button class="ghost-btn danger-btn deleteBtn" type="button" data-paper-id="<?= h($paperId) ?>">Eliminar</button>
              </div>
            </article>
          <?php endforeach; ?>
        </form>
      <?php endif; ?>
    </main>

    <footer class="site-footer"><p><strong>Códice IA</strong> — Biblioteca académica con IA.</p><p>Desarrollado por Nexora Studios</p></footer>
  </div>

  <script>
    document.querySelectorAll('.pro-lock').forEach(btn => {
      btn.addEventListener('click', () => alert(`${btn.dataset.feature} pertenece a Códice IA Premium. En una versión comercial se activaría con suscripción.`));
    });

    document.getElementById('compareForm')?.addEventListener('submit', e => {
      const checked = document.querySelectorAll('input[name="paperIds[]"]:checked').length;
      if (checked < 2) {
        e.preventDefault();
        alert('Selecciona al menos 2 artículos para comparar.');
      }
    });

    document.querySelectorAll('.deleteBtn').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('¿Eliminar este resumen de la biblioteca?')) return;
        const form = new FormData();
        form.append('paperId', btn.dataset.paperId);
        const resp = await fetch('eliminar_resumen.php', { method: 'POST', body: form });
        const data = await resp.json().catch(() => ({ error: 'Respuesta no válida.' }));
        if (!resp.ok || data.error) return alert(data.error || 'No se pudo eliminar.');
        btn.closest('.library-card').remove();
      });
    });
  </script>

  <script>
    document.querySelector('[data-back-link]')?.addEventListener('click', () => {
      if (document.referrer && new URL(document.referrer).origin === location.origin) {
        history.back();
      } else {
        location.href = 'index.html';
      }
    });
  </script>
</body>
</html>
