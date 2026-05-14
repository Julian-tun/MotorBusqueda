const resultsDiv = document.getElementById('results');
const searchBtn = document.getElementById('searchBtn');
const queryInput = document.getElementById('query');
const resultCount = document.getElementById('resultCount');

searchBtn.addEventListener('click', runSearch);
queryInput.addEventListener('keydown', e => { if (e.key === 'Enter') runSearch(); });
document.querySelectorAll('[data-topic]').forEach(btn => {
  btn.addEventListener('click', () => {
    queryInput.value = btn.dataset.topic;
    runSearch();
  });
});

async function runSearch() {
  const q = queryInput.value.trim();
  if (!q) return showToast('Escribe un tema de búsqueda.');

  resultsDiv.innerHTML = renderSkeletons();
  resultCount.textContent = 'Buscando artículos relevantes...';

  try {
    const fields = 'paperId,title,authors,year,abstract,url,openAccessPdf,citationCount,venue';
    const ssUrl = `https://api.semanticscholar.org/graph/v1/paper/search?query=${encodeURIComponent(q)}&limit=10&fields=${fields}`;
    const ssResp = await fetch(ssUrl);

    if (!ssResp.ok) {
      throw new Error(`Semantic Scholar respondió con HTTP ${ssResp.status}. Intenta nuevamente en unos segundos.`);
    }

    const ssJson = await ssResp.json();
    const papers = (ssJson.data || [])
      .filter(p => p.title && p.abstract && p.abstract.trim())
      .slice(0, 6);

    if (!papers.length) {
      resultCount.textContent = 'Sin resultados con abstract disponible.';
      resultsDiv.innerHTML = '<div class="empty-state"><h3>No encontré artículos con abstract.</h3><p>Prueba con palabras clave en inglés o con un tema más específico.</p></div>';
      return;
    }

    resultCount.textContent = `${papers.length} artículos listos para revisar.`;
    resultsDiv.innerHTML = '';
    papers.forEach((paper, index) => resultsDiv.appendChild(createPaperCard(paper, index)));
  } catch (err) {
    resultCount.textContent = 'Ocurrió un problema.';
    resultsDiv.innerHTML = `<div class="error-card"><strong>Error</strong><p>${escapeHtml(err.message)}</p></div>`;
  }
}

function createPaperCard(p, index) {
  const card = document.createElement('article');
  card.className = 'paper-card';

  const authors = (p.authors || []).slice(0, 4).map(a => a.name).join(', ') || 'Autores no disponibles';
  const pdfUrl = p.openAccessPdf?.url || '';
  const originalUrl = p.url || pdfUrl || '#';
  const citationText = Number.isFinite(p.citationCount) ? `${p.citationCount} citas` : 'Citas no disponibles';

  card.innerHTML = `
    <div class="paper-topline">
      <span class="paper-index">${String(index + 1).padStart(2, '0')}</span>
      <span>${escapeHtml(p.year || 's.f.')}</span>
      <span>${escapeHtml(p.venue || 'Fuente académica')}</span>
      <span>${escapeHtml(citationText)}</span>
    </div>

    <h3>${escapeHtml(p.title)}</h3>
    <p class="authors">${escapeHtml(authors)}</p>

    <details class="abstract-box" open>
      <summary>Abstract del artículo</summary>
      <p>${escapeHtml(p.abstract)}</p>
    </details>

    <div class="ai-panel">
      <div class="ai-panel-head">
        <strong>Resumen inteligente del abstract</strong>
        <button class="ghost-btn abstractBtn" type="button">Generar resumen</button>
      </div>
      <div class="summ muted">Presiona “Generar resumen” para analizar el abstract con IA.</div>
    </div>

    <div class="actions">
      <a href="${escapeAttribute(originalUrl)}" target="_blank" rel="noopener" class="btn secondary">Ver artículo original</a>
      <button class="btn fullBtn" ${pdfUrl ? '' : 'disabled'}>${pdfUrl ? 'Resumir PDF completo' : 'PDF no disponible'}</button>
    </div>

    <div class="full-summary" hidden></div>
  `;

  card.querySelector('.abstractBtn').addEventListener('click', () => summarizeAbstract(p, card));
  card.querySelector('.fullBtn').addEventListener('click', () => summarizeFullPaper(p, card));

  return card;
}

async function summarizeAbstract(p, card) {
  const btn = card.querySelector('.abstractBtn');
  const summBox = card.querySelector('.summ');
  btn.disabled = true;
  summBox.className = 'summ loading-box';
  summBox.textContent = 'Analizando abstract con IA...';

  try {
    const resp = await fetch('proxy.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ abstract: p.abstract })
    });
    const data = await safeJson(resp);
    if (!resp.ok || data.error) throw new Error(data.error || 'No se pudo generar el resumen.');
    summBox.className = 'summ';
    summBox.innerHTML = `<pre>${escapeHtml(data.resumen)}</pre>`;
  } catch (err) {
    summBox.className = 'summ error-inline';
    summBox.textContent = err.message;
  } finally {
    btn.disabled = false;
  }
}

async function summarizeFullPaper(p, card) {
  const btn = card.querySelector('.fullBtn');
  const target = card.querySelector('.full-summary');
  btn.disabled = true;
  target.hidden = false;
  target.className = 'full-summary loading-box';
  target.textContent = 'Descargando PDF, extrayendo texto y generando resumen completo...';

  try {
    const resp = await fetch('procesar_articulo.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        paperId: p.paperId,
        title: p.title,
        abstract: p.abstract,
        pdfUrl: p.openAccessPdf?.url || ''
      })
    });
    const data = await safeJson(resp);
    if (!resp.ok || data.error) throw new Error(data.error || 'No se pudo resumir el PDF completo.');

    target.className = 'full-summary';
    target.innerHTML = `
      <div class="summary-header"><strong>Resumen completo del artículo</strong><span>${escapeHtml(data.fuente || 'IA')}</span></div>
      <pre>${escapeHtml(data.resumen)}</pre>
      ${data.archivoResumen ? `<a class="btn secondary" href="${escapeAttribute(data.archivoResumen)}">Descargar resumen</a>` : ''}
    `;
  } catch (err) {
    target.className = 'full-summary error-inline';
    target.textContent = err.message;
  } finally {
    btn.disabled = false;
  }
}

async function safeJson(resp) {
  const text = await resp.text();
  try { return JSON.parse(text); }
  catch { return { error: 'Respuesta del servidor no es JSON válido: ' + text.slice(0, 180) }; }
}

function renderSkeletons() {
  return Array.from({ length: 3 }).map(() => `
    <div class="paper-card skeleton">
      <div class="line short"></div><div class="line title"></div><div class="line"></div><div class="line"></div>
    </div>
  `).join('');
}

function showToast(message) { alert(message); }
function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
}
function escapeAttribute(str) { return escapeHtml(str).replace(/`/g, '&#96;'); }
