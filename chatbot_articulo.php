<?php
require_once __DIR__ . '/mongo.php';

$paperId = trim($_GET['paperId'] ?? '');
$doc = $paperId !== '' ? obtenerResumenCache($paperId) : null;
$textoDoc = $paperId !== '' ? obtenerTextoArticulo($paperId) : null;

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function recorte($txt, $max = 240) {
    $txt = trim((string)$txt);
    return mb_strlen($txt, 'UTF-8') > $max ? mb_substr($txt, 0, $max, 'UTF-8') . '...' : $txt;
}

$frases = [
    'metodologia' => 'Detállame la metodología del artículo',
    'resultados' => 'Explícame con más profundidad los resultados o hallazgos',
    'conclusion' => 'Profundiza en la conclusión del estudio',
    'objetivo' => 'Dime y explícame el objetivo principal del artículo',
    'limitaciones' => 'Identifica y explica las limitaciones del estudio',
    'aportes' => 'Resalta los aportes importantes del artículo',
    'simple' => 'Explícame este artículo de forma sencilla',
    'palabras_clave' => 'Dame palabras clave importantes y explica por qué son relevantes'
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Chatbot IA | Códice IA</title>
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
      <?php if (!$doc): ?>
        <section class="error-card"><strong>No se encontró el resumen.</strong><p>Regresa a la biblioteca y abre un artículo guardado.</p></section>
      <?php else: ?>
        <section class="chat-layout">
          <aside class="paper-card chat-context">
            <span class="eyebrow">Artículo activo</span>
            <h3><?= h($doc['titulo'] ?? 'Artículo sin título') ?></h3>
            <p class="authors">ID: <?= h($paperId) ?></p>
            <div class="abstract-box"><p><?= h(recorte($doc['resumen'] ?? '')) ?></p></div>
            <div class="context-status <?= $textoDoc ? 'ok' : 'warn' ?>">
              <?= $textoDoc ? 'Texto completo/legible disponible para respuestas detalladas.' : 'No hay texto completo guardado; se usará el resumen.' ?>
            </div>
            <div class="actions">
              <a class="btn secondary" href="descargar_formato.php?paperId=<?= urlencode($paperId) ?>&formato=txt">TXT</a>
              <a class="btn secondary" href="descargar_formato.php?paperId=<?= urlencode($paperId) ?>&formato=pdf">PDF</a>
              <a class="btn secondary" href="descargar_formato.php?paperId=<?= urlencode($paperId) ?>&formato=word">Word</a>
            </div>
          </aside>

          <section class="paper-card chat-card">
            <div class="summary-header">
              <div>
                <span class="eyebrow">Chatbot académico</span>
                <h2>Profundiza por secciones</h2>
              </div>
              <span class="pro-badge">IA PRO</span>
            </div>

            <div id="chatMessages" class="chat-messages">
              <div class="chat-message bot">
                <strong>Códice IA</strong>
                <p>Elige una frase y analizaré el artículo usando el texto legible guardado. No inventaré datos si la sección no aparece.</p>
              </div>
            </div>

            <div class="prompt-grid">
              <?php foreach ($frases as $tipo => $frase): ?>
                <button class="ghost-btn promptBtn" type="button" data-tipo="<?= h($tipo) ?>" data-frase="<?= h($frase) ?>"><?= h($frase) ?></button>
              <?php endforeach; ?>
            </div>
          </section>
        </section>
      <?php endif; ?>
    </main>
  </div>

  <script>
    const paperId = <?= json_encode($paperId, JSON_UNESCAPED_UNICODE) ?>;
    const chatMessages = document.getElementById('chatMessages');

    document.querySelectorAll('.promptBtn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const frase = btn.dataset.frase;
        addMessage('user', 'Tú', frase);
        btn.disabled = true;
        const loading = addMessage('bot loading', 'Códice IA', 'Analizando la sección solicitada...');

        try {
          const resp = await fetch('chatbot_responder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ paperId, tipo: btn.dataset.tipo, pregunta: frase })
          });
          const data = await safeJson(resp);
          if (!resp.ok || data.error) throw new Error(data.error || 'No se pudo responder.');
          loading.remove();
          addMessage('bot', 'Códice IA', data.respuesta);
        } catch (err) {
          loading.remove();
          addMessage('bot error-inline', 'Error', err.message);
        } finally {
          btn.disabled = false;
        }
      });
    });

    function addMessage(type, title, text) {
      const div = document.createElement('div');
      div.className = 'chat-message ' + type;
      const isBot = String(type).includes('bot');
      div.innerHTML = `<strong>${escapeHtml(title)}</strong><div class="academic-content">${isBot ? renderAcademicText(text) : escapeHtml(text)}</div>`;
      chatMessages.appendChild(div);
      chatMessages.scrollTop = chatMessages.scrollHeight;
      return div;
    }

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
          html.push(`<h4>${formatInline(line.replace(/^#{1,4}\s+/, ''))}</h4>`);
        } else if (/^(•|-|\*)\s+/.test(line)) {
          html.push(`<div class="nice-bullet">${formatInline(line.replace(/^(•|-|\*)\s+/, ''))}</div>`);
        } else if (/^\d+[.)]\s+/.test(line)) {
          html.push(`<h4>${formatInline(line.replace(/^\d+[.)]\s+/, ''))}</h4>`);
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
