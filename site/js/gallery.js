/* Galerie publique : menu mobile + amélioration des carrousels de la lightbox.
   Externe (CSP script-src 'self'). Le filtre et l'ouverture/fermeture de la
   lightbox fonctionnent déjà sans JS (CSS :checked / :target). */
(function () {
  'use strict';

  /* ---- Menu mobile (identique à l'accueil) ---- */
  var toggle = document.getElementById('toggle');
  var menu = document.getElementById('menu');
  if (toggle && menu) {
    toggle.addEventListener('click', function () {
      var open = menu.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    menu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        menu.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  /* ---- Carrousels : flèches + clavier ---- */
  document.querySelectorAll('[data-carousel]').forEach(function (vp) {
    var track = vp.querySelector('.lightbox__track');
    var prev = vp.querySelector('[data-prev]');
    var next = vp.querySelector('[data-next]');
    if (!track) { return; }

    function step(dir) {
      var w = track.clientWidth || 1;
      track.scrollBy({ left: dir * w, top: 0, behavior: 'smooth' });
    }
    if (prev) { prev.hidden = false; prev.addEventListener('click', function () { step(-1); }); }
    if (next) { next.hidden = false; next.addEventListener('click', function () { step(1); }); }

    // Flèches clavier quand la piste a le focus.
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    });
  });

  /* ---- Échap ferme la lightbox ouverte (:target) ---- */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    if (location.hash && location.hash.indexOf('#lb-') === 0) {
      // Revient à l'ancre galerie sans empiler d'historique superflu.
      location.hash = '#gal';
    }
  });

  /* ---- À l'ouverture d'une lightbox, replace le carrousel au début ---- */
  window.addEventListener('hashchange', function () {
    if (location.hash.indexOf('#lb-') === 0) {
      var lb = document.getElementById(location.hash.slice(1));
      if (lb) {
        var track = lb.querySelector('.lightbox__track');
        if (track) { track.scrollTo({ left: 0 }); }
      }
    }
  });
})();
