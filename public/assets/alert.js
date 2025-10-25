document.addEventListener('DOMContentLoaded', () => {
  const overlay = document.querySelector('[data-modal]');
  if (!overlay) return;
  const closeBtn = overlay.querySelector('[data-close-modal]');
  function close() { overlay.remove(); }
  closeBtn?.addEventListener('click', close);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
});

// Programmatic service error dialog
window.showServiceError = function(message) {
  try {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.setAttribute('data-modal', '');
    overlay.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <h2 id="modal-title">Ocurrió un error</h2>
        <ul class="modal-messages"><li></li></ul>
        <div class="modal-actions"><button class="btn primary" data-close-modal>Cerrar</button></div>
      </div>`;
    overlay.querySelector('.modal-messages li').textContent = String(message || 'Error desconocido');
    document.body.appendChild(overlay);
    const close = () => overlay.remove();
    overlay.querySelector('[data-close-modal]')?.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); }, { once: true });
  } catch (_) { /* noop */ }
};
