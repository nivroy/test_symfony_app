document.addEventListener('DOMContentLoaded', () => {
  const overlay = document.querySelector('[data-modal]');
  if (!overlay) return;
  const closeBtn = overlay.querySelector('[data-close-modal]');
  function close() { overlay.remove(); }
  closeBtn?.addEventListener('click', close);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
});

