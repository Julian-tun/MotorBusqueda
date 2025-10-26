<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>📄 Subir PDF para resumen</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <header>
    <h1>📄 Subir PDF para resumir</h1>
    <p>Sube tu PDF y obtén un resumen, metodología y conclusión generados por IA (OpenAI).</p>
  </header>

  <section class="controls">
    <div class="upload-box">
      <form id="uploadForm" enctype="multipart/form-data">
        <input type="file" name="pdfFile" accept=".pdf" required>
        <input type="text" id="openaiKey" placeholder="OpenAI API Key (Bearer)" required>
        <select id="modelSelect">
          <option value="gpt-4o-mini">gpt-4o-mini (rápido y barato)</option>
          <option value="gpt-4o">gpt-4o (más potente)</option>
        </select>
        <button type="submit">Generar resumen</button>
      </form>
    </div>
  </section>

  <main id="result"></main>

  <footer>
    <p><strong>Notas:</strong></p>
    <ol>
      <li>Asegúrate de tener una API Key válida de <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI</a>.</li>
      <li>El PDF no debe ser demasiado grande para evitar límites de tokens en la IA.</li>
    </ol>
    <p><a href="index.html">← Volver al motor de búsqueda</a></p>
  </footer>

  <script>
    const form = document.getElementById('uploadForm');
    const resultDiv = document.getElementById('result');

    form.addEventListener('submit', async e => {
      e.preventDefault();

      const file = form.pdfFile.files[0];
      const apiKey = document.getElementById('openaiKey').value.trim();
      const model = document.getElementById('modelSelect').value;

      if (!file) return alert('Selecciona un archivo PDF.');
      if (!apiKey) return alert('Ingresa tu API Key de OpenAI.');

      const formData = new FormData();
      formData.append('pdfFile', file);
      formData.append('apiKey', apiKey);
      formData.append('model', model);

      resultDiv.innerHTML = '<p class="loading">⏳ Generando resumen...</p>';

      try {
        const resp = await fetch('procesar_pdf.php', { method: 'POST', body: formData });

        // Forzar JSON
        const text = await resp.text();
        let data;
        try {
          data = JSON.parse(text);
        } catch {
          resultDiv.innerHTML = `<p class="error">⚠️ Error: Respuesta del servidor no es JSON válido.<br>${text}</p>`;
          return;
        }

        if (data.error) {
          resultDiv.innerHTML = `<p class="error">⚠️ ${data.error}</p>`;
          return;
        }

        resultDiv.innerHTML = `
          <h2>✅ Resumen generado</h2>
          <div class="resumen-box">
            <pre>${data.resumen}</pre>
          </div>
          <a href="${data.archivoResumen}" download class="btn">📥 Descargar resumen</a>
        `;
      } catch (err) {
        resultDiv.innerHTML = `<p class="error">Error: ${err.message}</p>`;
      }
    });
  </script>
</body>
</html>
