/* Saleor Dashboard mounts this iframe and passes ?saleorApiUrl=... in the URL. */
(function () {
  const params       = new URLSearchParams(window.location.search);
  const saleorApiUrl = params.get('saleorApiUrl') || params.get('domain') || '';
  const form         = document.getElementById('cfg-form');
  const alertEl      = document.getElementById('alert');
  const webhookUrlEl = document.getElementById('webhook-url');

  webhookUrlEl.textContent = `${window.location.origin}/api/webhooks/renovax`;

  function showAlert(msg, kind) {
    alertEl.className   = `alert alert-${kind}`;
    alertEl.textContent = msg;
    alertEl.classList.remove('d-none');
  }

  if (!saleorApiUrl) {
    showAlert('Missing ?saleorApiUrl in URL — open this panel from the Saleor Dashboard.', 'warning');
    form.querySelector('button[type=submit]').disabled = true;
    return;
  }

  fetch(`/api/configuration?saleorApiUrl=${encodeURIComponent(saleorApiUrl)}`)
    .then(r => r.json())
    .then(data => {
      if (data.configured) {
        showAlert('This Saleor instance is already configured. Submit again to replace credentials.', 'success');
        if (data.config?.renovaxApiBase) form.renovaxApiBase.value = data.config.renovaxApiBase;
      }
    })
    .catch(() => {});

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const body = {
      saleorApiUrl,
      renovaxApiBase:       fd.get('renovaxApiBase'),
      renovaxBearerToken:   fd.get('renovaxBearerToken'),
      renovaxWebhookSecret: fd.get('renovaxWebhookSecret'),
    };
    showAlert('Verifying token with RENOVAX...', 'info');
    try {
      const res = await fetch('/api/configuration', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        showAlert(`Failed: ${data.error || 'unknown'} ${data.detail ? '— ' + data.detail : ''}`, 'danger');
      } else {
        showAlert('Saved. RENOVAX Payments is now active for this Saleor instance.', 'success');
        form.renovaxBearerToken.value   = '';
        form.renovaxWebhookSecret.value = '';
      }
    } catch (err) {
      showAlert(`Network error: ${err.message}`, 'danger');
    }
  });
})();
