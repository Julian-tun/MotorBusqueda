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

      // Botón para subir PDF
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
  summBox.innerHTML = '<em>🤖 Analizando abstract... esto puede tardar unos segundos.</em>';

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

  // Intentar hasta 3 veces si falla con 429 o error de red
  for (let intento = 1; intento <= 3; intento++) {
    try {
      const aiResp = await fetch('./proxy.php', {
        method: 'POST',
        body: formData,
        cache: 'no-cache'
      });

      let text = await aiResp.text();
      let aiJson;
      try {
        aiJson = JSON.parse(text);
      } catch {
        throw new Error("Respuesta no válida del servidor.");
      }

      if (!aiResp.ok) {
        if (aiResp.status === 429 && intento < 3) {
          summBox.innerHTML = `<span class="error">⚠️ Servidor de IA ocupado (429). Reintentando en ${intento * 2} s...</span>`;
          await new Promise(r => setTimeout(r, intento * 2000));
          continue;
        } else {
          throw new Error(`Error IA: HTTP ${aiResp.status}`);
        }
      }

      if (aiJson.error) {
        throw new Error(aiJson.error);
      }

      const summary = aiJson.choices?.[0]?.message?.content || 'No se pudo generar resumen.';
      container.lastSummary = summary;
      summBox.innerHTML = `<pre>${escapeHtml(summary)}</pre>`;
      return; // ✅ Éxito, salir de la función

    } catch (err) {
      console.warn(`Intento ${intento} falló:`, err.message);
      if (intento < 3) {
        summBox.innerHTML = `<span class="error">⏳ Ocurrió un error (${escapeHtml(err.message)}). Reintentando en ${intento * 2} s...</span>`;
        await new Promise(r => setTimeout(r, intento * 2000));
      } else {
        summBox.innerHTML = `<span class="error">❌ La IA está saturada o sin conexión. Intenta de nuevo más tarde.</span>`;
      }
    }
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/[&<>"']/g, s => (
    {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[s]
  ));
}
