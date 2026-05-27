const resultsDiv = document.getElementById('results');
const searchBtn = document.getElementById('searchBtn');
const queryInput = document.getElementById('query');
const resultCount = document.getElementById('resultCount');

const SEARCH_STATE_KEY = 'codiceIA_searchState_v1';
const FULL_SUMMARIES_KEY = 'codiceIA_fullSummaries_v1';
const ABSTRACT_SUMMARIES_KEY = 'codiceIA_abstractSummaries_v1';

searchBtn.addEventListener('click', runSearch);
queryInput.addEventListener('keydown', e => { if (e.key === 'Enter') runSearch(); });
document.querySelectorAll('[data-topic]').forEach(btn => {
  btn.addEventListener('click', () => {
    queryInput.value = btn.dataset.topic;
    runSearch();
  });
});

restoreSearchState();

async function runSearch() {
  const q = queryInput.value.trim();
  if (!q) return showToast('Escribe un tema de búsqueda.');

  resultsDiv.innerHTML = renderSkeletons();
  resultCount.textContent = 'Buscando artículos relevantes...';

  try {
    const ssResp = await fetch(`buscar_articulos.php?query=${encodeURIComponent(q)}&limit=15`);
    const ssJson = await safeJson(ssResp);

    if (!ssResp.ok || ssJson.error) {
      throw new Error(ssJson.error || `Semantic Scholar respondió con HTTP ${ssResp.status}. Intenta nuevamente en unos segundos.`);
    }

    const papers = (ssJson.data || [])
      .filter(p => p.title && (hasText(p.abstract) || p.openAccessPdf?.url || p.url))
      .slice(0, 10);

    if (!papers.length) {
      resultCount.textContent = 'Sin resultados suficientes.';
      resultsDiv.innerHTML = '<div class="empty-state"><h3>No encontré artículos útiles para mostrar.</h3><p>Prueba con palabras clave en inglés o con un tema más específico.</p></div>';
      saveSearchState(q, []);
      return;
    }

    resultCount.textContent = `${papers.length} artículos listos para revisar.`;
    resultsDiv.innerHTML = '';
    papers.forEach((paper, index) => resultsDiv.appendChild(createPaperCard(paper, index)));
    saveSearchState(q, papers);
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
  const tieneAbstract = hasText(p.abstract);
  const puedeIntentarPdf = Boolean(pdfUrl || (p.url && p.url !== '#'));
  const fullBtnText = pdfUrl ? 'Resumir PDF completo' : (puedeIntentarPdf ? 'Buscar PDF y resumir' : 'PDF no disponible');
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
      <p>${tieneAbstract ? escapeHtml(p.abstract) : 'Este resultado no trae abstract disponible desde Semantic Scholar, pero puedes intentar resumir el PDF o abrir el artículo original.'}</p>
    </details>

    <div class="ai-panel">
      <div class="ai-panel-head">
        <strong>Resumen inteligente del abstract</strong>
        <button class="ghost-btn abstractBtn" type="button" ${tieneAbstract ? '' : 'disabled'}>${tieneAbstract ? 'Generar resumen' : 'Sin abstract'}</button>
      </div>
      <div class="summ muted">${tieneAbstract ? 'Presiona “Generar resumen” para analizar el abstract con IA.' : 'No hay abstract legible para resumir; prueba con el PDF completo.'}</div>
    </div>

    <div class="actions">
      <a href="${escapeAttribute(originalUrl)}" target="_blank" rel="noopener" class="btn secondary">Ver artículo original</a>
      <button class="btn fullBtn" ${puedeIntentarPdf ? '' : 'disabled'}>${escapeHtml(fullBtnText)}</button>
    </div>

    <div class="full-summary" hidden></div>
  `;

  card.querySelector('.abstractBtn')?.addEventListener('click', () => summarizeAbstract(p, card));
  card.querySelector('.fullBtn').addEventListener('click', () => summarizeFullPaper(p, card));
  restoreCardSummaries(p, card);

  return card;
}

async function summarizeAbstract(p, card) {
  const btn = card.querySelector('.abstractBtn');
  const summBox = card.querySelector('.summ');
  if (!hasText(p.abstract)) return;

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
    summBox.innerHTML = `<pre>${escapeHtml(cleanSummaryText(data.resumen))}</pre>`;
    saveAbstractSummary(p.paperId || p.title, data.resumen);
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
  target.textContent = 'Buscando el PDF real, descargando contenido y generando resumen completo...';

  try {
    const resp = await fetch('procesar_articulo.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        paperId: p.paperId,
        title: p.title,
        abstract: p.abstract || '',
        pdfUrl: p.openAccessPdf?.url || '',
        articleUrl: p.url || '',
        doi: p.externalIds?.DOI || ''
      })
    });
    const data = await safeJson(resp);
    if (!resp.ok || data.error) throw new Error(data.error || 'No se pudo resumir el PDF completo.');

    renderFullSummary(target, data);
    if (data.paperId) saveFullSummary(data.paperId, data);
  } catch (err) {
    target.className = 'full-summary error-inline';
    target.textContent = err.message;
  } finally {
    btn.disabled = false;
  }
}

function renderFullSummary(target, data) {
  target.hidden = false;
  target.className = 'full-summary';
  target.innerHTML = `
    <div class="summary-header"><strong>Resumen completo del artículo</strong><span>${escapeHtml(data.fuente || 'IA')}</span></div>
    <pre>${escapeHtml(cleanSummaryText(data.resumen))}</pre>
    ${data.paperId ? `
      <div class="download-pack">
        <a class="btn secondary" href="descargar_formato.php?paperId=${encodeURIComponent(data.paperId)}&formato=txt">Descargar TXT</a>
        <a class="btn secondary" href="descargar_formato.php?paperId=${encodeURIComponent(data.paperId)}&formato=pdf">Descargar PDF</a>
        <a class="btn secondary" href="descargar_formato.php?paperId=${encodeURIComponent(data.paperId)}&formato=word">Descargar Word</a>
        <a class="btn" href="chatbot_articulo.php?paperId=${encodeURIComponent(data.paperId)}">Chatbot IA</a>
        <button class="ghost-btn pro-lock" type="button" onclick="alert('Comparar artículos pertenece a Códice IA Premium. Abre Biblioteca para seleccionar dos o más resúmenes.')">Comparar PRO</button>
      </div>` : ''}
  `;
}

async function safeJson(resp) {
  const text = await resp.text();
  try { return JSON.parse(text); }
  catch { return { error: 'Respuesta del servidor no es JSON válido: ' + text.slice(0, 180) }; }
}

function renderSkeletons() {
  return Array.from({ length: 4 }).map(() => `
    <div class="paper-card skeleton">
      <div class="line short"></div><div class="line title"></div><div class="line"></div><div class="line"></div>
    </div>
  `).join('');
}

function cleanSummaryText(str) {
  return String(str ?? '')
    .replace(/^#{1,6}\s*/gm, '')
    .replace(/\*\*(.*?)\*\*/g, '$1')
    .replace(/__(.*?)__/g, '$1')
    .replace(/^\s*-\s+/gm, '• ')
    .replace(/^\s*\*\s+/gm, '• ')
    .replace(/`/g, '')
    .trim();
}

function restoreSearchState() {
  const state = readJson(SEARCH_STATE_KEY, null);
  if (!state || !state.query || !Array.isArray(state.papers) || !state.papers.length) return;
  queryInput.value = state.query;
  resultCount.textContent = `${state.papers.length} artículos restaurados de tu última búsqueda.`;
  resultsDiv.innerHTML = '';
  state.papers.forEach((paper, index) => resultsDiv.appendChild(createPaperCard(paper, index)));
}

function saveSearchState(query, papers) {
  try {
    localStorage.setItem(SEARCH_STATE_KEY, JSON.stringify({ query, papers, savedAt: Date.now() }));
  } catch (e) {}
}

function restoreCardSummaries(p, card) {
  const abstractMap = readJson(ABSTRACT_SUMMARIES_KEY, {});
  const fullMap = readJson(FULL_SUMMARIES_KEY, {});
  const abstractKey = p.paperId || p.title;
  if (abstractMap[abstractKey] && card.querySelector('.summ')) {
    const summBox = card.querySelector('.summ');
    summBox.className = 'summ';
    summBox.innerHTML = `<pre>${escapeHtml(cleanSummaryText(abstractMap[abstractKey]))}</pre>`;
  }

  const possibleIds = [p.paperId, ...Object.keys(fullMap)].filter(Boolean);
  const matchId = possibleIds.find(id => fullMap[id] && (id === p.paperId || fullMap[id].titulo === p.title || fullMap[id].title === p.title));
  if (matchId && fullMap[matchId]) {
    renderFullSummary(card.querySelector('.full-summary'), fullMap[matchId]);
  }
}

function saveAbstractSummary(key, resumen) {
  if (!key) return;
  const map = readJson(ABSTRACT_SUMMARIES_KEY, {});
  map[key] = resumen;
  safeSetJson(ABSTRACT_SUMMARIES_KEY, map);
}

function saveFullSummary(paperId, data) {
  const map = readJson(FULL_SUMMARIES_KEY, {});
  map[paperId] = { ...data, savedAt: Date.now() };
  safeSetJson(FULL_SUMMARIES_KEY, map);
}

function readJson(key, fallback) {
  try { return JSON.parse(localStorage.getItem(key)) ?? fallback; }
  catch { return fallback; }
}

function safeSetJson(key, value) {
  try { localStorage.setItem(key, JSON.stringify(value)); }
  catch (e) {}
}

function hasText(value) { return String(value ?? '').trim().length > 0; }
function showToast(message) { alert(message); }
function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
}
function escapeAttribute(str) { return escapeHtml(str).replace(/`/g, '&#96;'); }
