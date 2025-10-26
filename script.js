const resultsDiv = document.getElementById('results');
const searchBtn = document.getElementById('searchBtn');
const queryInput = document.getElementById('query');

searchBtn.addEventListener('click', () => runSearch());
queryInput.addEventListener('keydown', e => { if (e.key === 'Enter') runSearch(); });

async function runSearch() {
  const q = queryInput.value.trim();
  if (!q) return alert('Escribe una consulta.');
  resultsDiv.innerHTML = '<p class="loading">🔎 Buscando artículos...</p>';

  try {
    const ssUrl = `https://api.semanticscholar.org/graph/v1/paper/search?query=${encodeURIComponent(q)}&limit=10&fields=title,authors,year,abstract,url,openAccessPdf`;
    const ssResp = await fetch(ssUrl);
    if (!ssResp.ok) throw new Error('Error Semantic Scholar: ' + ssResp.status);
    const ssJson = await ssResp.json();

    let papers = (ssJson.data || []).filter(p => p.abstract && p.abstract.trim()).slice(0, 3);
    if (!papers.length) {
      resultsDiv.innerHTML = '<p>No se encontraron artículos con abstract disponible.</p>';
      return;
    }

    resultsDiv.innerHTML = '';
    papers.forEach((p, idx) => {
      const pdfUrl = p.openAccessPdf?.url || p.url; 
      const container = document.createElement('div');
      container.className = 'result';

      container.innerHTML = `
        <h2>${escapeHtml(p.title || 'Sin título')}</h2>
        <div class="meta">${(p.authors || []).map(a => a.name).join(', ')} — ${p.year || 's.f.'}</div>
        <div class="abstract"><em>Abstract:</em> ${escapeHtml(p.abstract)}</div>
        <div class="resumen-abstract">
          <h3>Resumen del abstract</h3>
          <div class="summ"><em>IA generando resumen, metodología y conclusión...</em></div>
        </div>
        <div class="actions">
          <a href="${pdfUrl}" target="_blank" class="btn linkBtn">🔗 Ver artículo original</a>
          <button class="btn fullBtn">📄 Resumir todo el artículo</button>
        </div>
      `;

      resultsDiv.appendChild(container);

      // Resumen automático del abstract
      generateSummary(p.abstract, container.querySelector('.summ'), container);

      // Nuevo comportamiento: abrir página de subir PDF
      const fullBtn = container.querySelector('.fullBtn');
      fullBtn.addEventListener('click', () => {
        window.open('subir_pdf.php?title=' + encodeURIComponent(p.title) + '&url=' + encodeURIComponent(pdfUrl), '_blank');
      });
    });

  } catch (err) {
    resultsDiv.innerHTML = `<p class="error">Error: ${escapeHtml(err.message)}</p>`;
  }
}

async function generateSummary(abstractText, summBox, container) {
  summBox.innerHTML = '<em>IA generando resumen, metodología y conclusión...</em>';
  try {
    const apiKey = document.getElementById('openaiKey').value.trim();
    if (!apiKey) {
      summBox.innerHTML = '<span class="error">⚠️ Falta API Key de OpenAI.</span>';
      return;
    }
    const model = document.getElementById('modelSelect').value;
    const userPrompt = `Lee el siguiente abstract de un artículo científico y proporciona de manera concisa:
1. Resumen (3–5 oraciones)
2. Metodología utilizada
3. Conclusión principal

Abstract:
${abstractText}`;

    const formData = new FormData();
    formData.append('apiKey', apiKey);
    formData.append('body', JSON.stringify({
      model: model,
      messages: [
        { role: 'system', content: 'Eres un asistente que resume artículos científicos.' },
        { role: 'user', content: userPrompt }
      ]
    }));

    const aiResp = await fetch('proxy.php', { method: 'POST', body: formData });
    const text = await aiResp.text();

    let aiJson;
    try { aiJson = JSON.parse(text); } catch {
      summBox.innerHTML = `<span class="error">Error: Respuesta no válida.<br>${escapeHtml(text)}</span>`;
      return;
    }

    if (!aiResp.ok) {
      summBox.innerHTML = `<span class="error">Error IA: ${escapeHtml(aiJson.error || 'Desconocido')}</span>`;
      return;
    }

    const summary = aiJson.choices?.[0]?.message?.content || '';
    container.lastSummary = summary;
    summBox.innerHTML = `<pre>${escapeHtml(summary)}</pre>`;
  } catch (err) {
    summBox.innerHTML = `<span class="error">Error: ${escapeHtml(err.message)}</span>`;
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/[&<>"']/g, s => (
    {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[s]
  ));
}
