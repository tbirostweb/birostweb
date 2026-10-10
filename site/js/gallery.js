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

  /* ---- Vidéo : lecteur Bunny injecté seulement à l'ouverture de la lightbox ----
     Les liens .gal-video-trigger / « Lire la vidéo » pointent vers #lbv-ID (CSS :target,
     fonctionne au clavier). Ici on charge l'iframe à l'ouverture et on la retire à la
     fermeture (stoppe la lecture, rien de chargé dans la grille). */
  function syncVideoPlayers() {
    var openId = location.hash.indexOf('#lbv-') === 0 ? location.hash.slice(1) : '';
    document.querySelectorAll('.lightbox--video').forEach(function (lb) {
      var box = lb.querySelector('[data-video-player]');
      if (!box) { return; }
      var frame = box.querySelector('iframe');
      if (lb.id === openId) {
        if (!frame) {
          var url = box.getAttribute('data-video-player');
          if (!url) { return; }
          frame = document.createElement('iframe');
          frame.setAttribute('src', url);
          frame.setAttribute('title', box.getAttribute('data-video-title') || 'Vidéo');
          frame.setAttribute('allow', 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;');
          frame.setAttribute('allowfullscreen', '');
          box.appendChild(frame);
        }
        var close = lb.querySelector('.lightbox__close');
        if (close) { close.focus({ preventScroll: true }); }
      } else if (frame) {
        frame.remove();
      }
    });
  }
  window.addEventListener('hashchange', syncVideoPlayers);
  syncVideoPlayers();

  /* ---- Échap ferme la lightbox ouverte (:target) ---- */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    if (location.hash && (location.hash.indexOf('#lb-') === 0 || location.hash.indexOf('#lbv-') === 0)) {
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
