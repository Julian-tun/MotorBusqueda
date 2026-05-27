<?php
require_once __DIR__ . '/mongo.php';

$paperIds = $_POST['paperIds'] ?? $_GET['paperIds'] ?? [];
if (is_string($paperIds)) {
    $paperIds = array_filter(array_map('trim', explode(',', $paperIds)));
}
$docs = obtenerResumenesPorIds(is_array($paperIds) ? $paperIds : []);

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function recorte($txt, $max = 240) {
    $txt = trim((string)$txt);
    return mb_strlen($txt, 'UTF-8') > $max ? mb_substr($txt, 0, $max, 'UTF-8') . '...' : $txt;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Comparador | Códice IA</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="page-shell">
    <header class="hero compact-hero">
      <nav class="topbar">
        <div class="brand">
          <div class="logo-slot" aria-label="Logo de Códice IA">
            <img src="img/Códice IA.png" alt="Logo Códice IA" class="brand-logo">
          </div>
          <div><strong></strong><small></small></div>
        </div>
        <div class="nav-actions">
          <button class="nav-link back-link" type="button" data-back-link>← Regresar</button>
          <a class="nav-link" href="biblioteca.php">Biblioteca</a>
          <a class="nav-link" href="index.html">Buscador</a>
        </div>
      </nav>
    </header>

    <main>
      <section class="paper-card">
        <div class="summary-header">
          <div>
            <span class="eyebrow">Comparador IA</span>
            <h2>Comparación académica de artículos</h2>
          </div>
          <span class="pro-badge">IA PRO</span>
        </div>

        <?php if (count($docs) < 2): ?>
          <div class="error-card"><strong>Selección insuficiente</strong><p>Regresa a la biblioteca y selecciona al menos 2 artículos.</p></div>
          <div class="actions"><a class="btn" href="biblioteca.php">Volver a Biblioteca</a></div>
        <?php else: ?>
          <p class="authors">Artículos seleccionados: <?= count($docs) ?>. Se compararán objetivos, metodología, resultados, conclusiones, similitudes y diferencias.</p>
          <div class="compare-grid">
            <?php foreach ($docs as $doc): ?>
              <article class="abstract-box compare-item">
                <strong><?= h($doc['titulo'] ?? 'Artículo sin título') ?></strong>
                <p><?= h(recorte($doc['resumen'] ?? '')) ?></p>
              </article>
            <?php endforeach; ?>
          </div>
          <div class="actions">
            <button id="generateCompare" class="btn" type="button">Generar comparación inteligente</button>
            <button class="ghost-btn pro-lock" type="button" data-feature="Exportar comparación en reporte académico">Exportar reporte PRO</button>
          </div>
          <div id="compareResult" class="full-summary" hidden></div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <script>
    const paperIds = <?= json_encode(array_map(fn($d) => (string)($d['paperId'] ?? ''), $docs), JSON_UNESCAPED_UNICODE) ?>;
    const resultBox = document.getElementById('compareResult');

    document.querySelector('.pro-lock')?.addEventListener('click', e => {
      alert(`${e.currentTarget.dataset.feature} pertenece a Códice IA Premium. En una versión comercial se activaría con suscripción.`);
    });

    document.getElementById('generateCompare')?.addEventListener('click', async e => {
      e.currentTarget.disabled = true;
      resultBox.hidden = false;
      resultBox.className = 'full-summary loading-box';
      resultBox.textContent = 'Comparando artículos con IA...';

      try {
        const resp = await fetch('comparar_responder.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ paperIds })
        });
        const data = await safeJson(resp);
        if (!resp.ok || data.error) throw new Error(data.error || 'No se pudo comparar.');
        resultBox.className = 'full-summary';
        resultBox.innerHTML = renderAcademicText(data.comparacion);
      } catch (err) {
        resultBox.className = 'full-summary error-inline';
        resultBox.textContent = err.message;
      } finally {
        e.currentTarget.disabled = false;
      }
    });

    async function safeJson(resp) {
      const text = await resp.text();
      try { return JSON.parse(text); }
      catch { return { error: 'Respuesta del servidor no es JSON válido: ' + text.slice(0, 180) }; }
    }

    function renderAcademicText(text) {
      const lines = String(text ?? '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
      const html = [];
      let i = 0;

      while (i < lines.length) {
        const line = lines[i].trim();
        if (!line) { i++; continue; }

        if (line.includes('|') && i + 1 < lines.length && /^\s*\|?\s*:?-{3,}/.test(lines[i + 1])) {
          const rows = [];
          rows.push(splitTableRow(line));
          i += 2;
          while (i < lines.length && lines[i].includes('|')) {
            rows.push(splitTableRow(lines[i].trim()));
            i++;
          }
          html.push(buildTable(rows));
          continue;
        }

        if (/^#{1,4}\s+/.test(line)) {
          html.push(`<h3>${formatInline(line.replace(/^#{1,4}\s+/, ''))}</h3>`);
        } else if (/^(•|-|\*)\s+/.test(line)) {
          html.push(`<div class="nice-bullet">${formatInline(line.replace(/^(•|-|\*)\s+/, ''))}</div>`);
        } else if (/^\d+[.)]\s+/.test(line)) {
          html.push(`<h3>${formatInline(line.replace(/^\d+[.)]\s+/, ''))}</h3>`);
        } else {
          html.push(`<p>${formatInline(line)}</p>`);
        }
        i++;
      }
      return `<div class="academic-content">${html.join('')}</div>`;
    }

    function splitTableRow(row) {
      return row.replace(/^\|/, '').replace(/\|$/, '').split('|').map(c => c.trim());
    }

    function buildTable(rows) {
      if (!rows.length) return '';
      const head = rows[0].map(c => `<th>${formatInline(c)}</th>`).join('');
      const body = rows.slice(1).map(r => `<tr>${r.map(c => `<td>${formatInline(c)}</td>`).join('')}</tr>`).join('');
      return `<div class="table-wrap"><table class="academic-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
    }

    function formatInline(str) {
      return escapeHtml(str)
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/__(.*?)__/g, '<strong>$1</strong>');
    }

    function escapeHtml(str) {
      return String(str ?? '').replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
    }
  </script>

  <script>
    document.querySelector('[data-back-link]')?.addEventListener('click', () => {
      if (document.referrer && new URL(document.referrer).origin === location.origin) {
        history.back();
      } else {
        location.href = 'biblioteca.php';
      }
    });
  </script>
</body>
</html>
