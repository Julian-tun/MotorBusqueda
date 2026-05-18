<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Subir PDF | Códice IA</title>
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
          <div>
            <strong></strong>
            <small></small>
          </div>
        </div>
        <a class="nav-link" href="index.html">Volver al buscador</a>
      </nav>

      <section class="hero-grid">
        <div class="hero-copy">
          <span class="eyebrow">Carga manual de documentos</span>
          <h1>Genera un resumen académico a partir de un PDF científico.</h1>
          <p>Utiliza esta opción cuando el artículo no tenga un PDF abierto disponible desde el buscador principal.</p>
        </div>
      </section>
    </header>

    <main>
      <section class="paper-card">
        <form id="uploadForm" enctype="multipart/form-data" class="search-panel" style="margin-top:0">
          <label>Archivo PDF</label>
          <input 
            type="file" 
            name="pdfFile" 
            accept=".pdf" 
            required 
            style="width:100%;padding:18px;border-radius:18px;background:#fff;color:#142033;border:1px solid #dfe8f3"
          >
          <button type="submit" class="btn" style="margin-top:16px">Generar resumen</button>
        </form>

        <div id="result" class="full-summary" hidden></div>
      </section>
    </main>
  </div>

  <script>
    const form = document.getElementById('uploadForm');
    const resultDiv = document.getElementById('result');

    form.addEventListener('submit', async e => {
      e.preventDefault();

      const file = form.pdfFile.files[0];
      if (!file) return alert('Selecciona un archivo PDF.');

      const formData = new FormData();
      formData.append('pdfFile', file);

      resultDiv.hidden = false;
      resultDiv.className = 'full-summary loading-box';
      resultDiv.textContent = 'Extrayendo texto y generando resumen...';

      try {
        const resp = await fetch('procesar_pdf.php', {
          method: 'POST',
          body: formData
        });

        const text = await resp.text();
        let data;

        try {
          data = JSON.parse(text);
        } catch {
          throw new Error('Respuesta del servidor no es JSON válido: ' + text.slice(0, 180));
        }

        if (!resp.ok || data.error) {
          throw new Error(data.error || 'No se pudo procesar el PDF.');
        }

        resultDiv.className = 'full-summary';
        resultDiv.innerHTML = `
          <div class="summary-header">
            <strong>Resumen generado</strong>
            <span>${data.fuente || 'IA'}</span>
          </div>
          <pre>${escapeHtml(data.resumen)}</pre>
          ${
            data.archivoResumen
              ? `<a class="btn secondary" href="${data.archivoResumen}">Descargar resumen</a>`
              : ''
          }
        `;
      } catch (err) {
        resultDiv.className = 'full-summary error-inline';
        resultDiv.textContent = err.message;
      }
    });

    function escapeHtml(str) {
      return String(str ?? '').replace(/[&<>"']/g, s => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      }[s]));
    }
  </script>
</body>
</html>