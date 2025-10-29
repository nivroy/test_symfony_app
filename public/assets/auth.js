document.addEventListener('DOMContentLoaded', () => {
  const container = document.querySelector('.tabs');
  if (!container) return;

  const buttons = Array.from(container.querySelectorAll('.tab-btn'));
  const panels = {
    login: document.getElementById('panel-login'),
    register: document.getElementById('panel-register')
  };

  function activate(name) {
    container.setAttribute('data-active', name);
    buttons.forEach(btn => {
      const isActive = btn.dataset.target === name;
      btn.classList.toggle('active', isActive);
      btn.setAttribute('aria-selected', String(isActive));
      btn.tabIndex = isActive ? 0 : -1;
    });

    Object.entries(panels).forEach(([key, panel]) => {
      const isActive = key === name;
      if (!panel) return;
      panel.classList.toggle('active', isActive);
      panel.hidden = !isActive;
    });
  }

  buttons.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.target;
      if (!target) return;
      activate(target);
    });
  });

  // Initialize from hash if present
  if (location.hash === '#register') activate('register');

  // Simple client-side password confirmation check
  const regForm = document.getElementById('register-form');
  if (regForm) {
    regForm.addEventListener('submit', (e) => {
      const pass = /** @type {HTMLInputElement} */ (document.getElementById('reg-password'));
      const conf = /** @type {HTMLInputElement} */ (document.getElementById('reg-password-confirm'));
      if (pass && conf && pass.value !== conf.value) {
        e.preventDefault();
        conf.setCustomValidity('Las contraseñas no coinciden');
        conf.reportValidity();
        setTimeout(() => conf.setCustomValidity(''), 1500);
      }
    });
  }
});
