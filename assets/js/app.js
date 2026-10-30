document.querySelectorAll('[data-confirm]').forEach(function (el) {
  el.addEventListener('click', function (ev) {
    if (!confirm(el.getAttribute('data-confirm'))) { ev.preventDefault(); }
  });
});
document.querySelectorAll('form[data-confirm-submit]').forEach(function (f) {
  f.addEventListener('submit', function (ev) {
    if (!confirm(f.getAttribute('data-confirm-submit'))) { ev.preventDefault(); }
  });
});
