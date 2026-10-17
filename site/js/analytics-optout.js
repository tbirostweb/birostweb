/* Opt-out de la mesure d'audience (Umami) : pose le drapeau reconnu par le script Umami.
   Aucune donnée personnelle : uniquement la préférence de refus, stockée dans votre navigateur. */
(function () {
  var KEY = 'umami.disabled';
  var btn = document.getElementById('optout-btn');
  var msg = document.getElementById('optout-state');
  if (!btn || !msg) return;
  function get() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
  function set(v) { try { if (v) localStorage.setItem(KEY, '1'); else localStorage.removeItem(KEY); } catch (e) {} }
  function render() {
    var off = get();
    msg.textContent = off ? 'La mesure d’audience est désactivée sur ce navigateur.' : 'La mesure d’audience est active sur ce navigateur.';
    btn.textContent = off ? 'Réactiver la mesure d’audience' : 'Refuser la mesure d’audience';
    btn.setAttribute('aria-pressed', off ? 'true' : 'false');
  }
  btn.addEventListener('click', function () { set(!get()); render(); });
  render();
})();
