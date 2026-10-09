/* Admin galerie : confirmations de suppression + réordonnancement des images.
   Externe (pas d'inline) pour rester compatible avec la CSP script-src 'self'. */
(function () {
  'use strict';

  // Confirmation avant soumission d'un formulaire marqué data-confirm.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm') || 'Confirmer ?')) {
        e.preventDefault();
      }
    });
  });

  // Renumérote les champs "ordre" dans l'ordre courant du DOM (10, 20, …).
  function renumber(grid) {
    var rows = grid.querySelectorAll('[data-row]');
    rows.forEach(function (row, i) {
      var inp = row.querySelector('[data-sort]');
      if (inp) { inp.value = String((i + 1) * 10); }
    });
  }

  var grid = document.getElementById('imggrid');
  if (grid) {
    grid.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-move]');
      if (!btn) { return; }
      e.preventDefault();
      var row = btn.closest('[data-row]');
      if (!row) { return; }
      var dir = btn.getAttribute('data-move');
      if (dir === 'up' && row.previousElementSibling) {
        grid.insertBefore(row, row.previousElementSibling);
      } else if (dir === 'down' && row.nextElementSibling) {
        grid.insertBefore(row.nextElementSibling, row);
      }
      renumber(grid);
    });
  }
})();
