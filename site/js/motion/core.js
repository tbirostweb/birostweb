/* ============================================================
   Motion core — utilitaires d'animation (GSAP + ScrollTrigger + SplitText)
   Fichier identique sur birostweb.fr et portfolio.theo-birost.fr :
   chaque site décrit ses propres animations dans js/motion/site.js
   via Motion.run(). GSAP est auto-hébergé dans js/lib/ (CSP 'self').

   Principes :
   - tout passe par gsap.matchMedia : si « réduire les animations » est
     actif (ou le devient), tout est annulé et le contenu reste visible ;
   - uniquement transform / opacity / clip-path : aucun layout shift ;
   - défilement 100 % natif : pas de scroll-jacking, pas de pin.
   ============================================================ */
(function (w, d) {
  'use strict';
  var root = d.documentElement;
  var gsap = w.gsap, ST = w.ScrollTrigger, Split = w.SplitText;

  function unlock() { root.classList.remove('m-pending'); }

  // GSAP indisponible : on rend la main au CSS d'origine.
  if (!gsap || !ST) {
    root.classList.remove('motion'); unlock();
    w.Motion = { run: function () {} };
    return;
  }
  gsap.registerPlugin(ST);
  if (Split) gsap.registerPlugin(Split);

  var MOTION_OK = '(prefers-reduced-motion: no-preference)';
  var EASE = 'power3.out';
  var CLEAR = 'transform,opacity,visibility,transition';

  function $$(sel, ctx) {
    if (!sel) return [];
    if (typeof sel === 'string') return Array.prototype.slice.call((ctx || d).querySelectorAll(sel));
    if (sel.nodeType) return [sel];
    return Array.prototype.slice.call(sel);
  }

  // API passée au setup d'un site. `cleanups` est rejoué au revert,
  // `animated` liste ce qui a reçu une animation (voir filet de sécurité).
  function createApi(ctx, cleanups, animated) {
    function track(els) { animated.push.apply(animated, els); return els; }
    function st(o, trigger) {
      if (o.trigger === false) return undefined;
      return { trigger: o.trigger || trigger, start: o.start || 'top 85%', once: true };
    }

    // Apparition simple : fondu + translation (stagger si plusieurs cibles).
    function fadeUp(targets, o) {
      o = o || {};
      var els = track($$(targets)); if (!els.length) return null;
      gsap.set(els, { transition: 'none' });
      return gsap.fromTo(els,
        { autoAlpha: 0, y: o.y != null ? o.y : 24, x: o.x || 0, scale: o.scale != null ? o.scale : 1 },
        { autoAlpha: 1, y: 0, x: 0, scale: 1, duration: o.duration || .8, ease: o.ease || EASE,
          stagger: o.stagger || 0, delay: o.delay || 0, clearProps: CLEAR,
          scrollTrigger: st(o, els[0]) });
    }

    // Apparition par lots : ce qui entre ensemble à l'écran arrive en cascade.
    function batch(targets, o) {
      o = o || {};
      var els = track($$(targets)); if (!els.length) return;
      gsap.set(els, { autoAlpha: 0, y: o.y != null ? o.y : 24, x: o.x || 0,
        scale: o.scale != null ? o.scale : 1, transition: 'none' });
      ST.batch(els, {
        start: o.start || 'top 90%', once: true,
        onEnter: function (b) {
          gsap.to(b, { autoAlpha: 1, y: 0, x: 0, scale: 1, duration: o.duration || .7, ease: o.ease || EASE,
            stagger: o.stagger != null ? o.stagger : .08, overwrite: true, clearProps: CLEAR });
        }
      });
    }

    // Texte découpé en mots (ou mots + lettres) qui montent depuis un masque.
    // Le DOM d'origine est restauré à la fin de l'animation.
    function words(targets, o) {
      o = o || {};
      var els = track($$(targets)); if (!els.length) return null;
      if (!Split) return fadeUp(els, o);
      var tl = gsap.timeline({ scrollTrigger: st(o, els[0]), delay: o.delay || 0 });
      els.forEach(function (el, i) {
        var split = new Split(el, { type: o.chars ? 'words,chars' : 'words', mask: o.mask || 'words',
          wordsClass: 'mw', charsClass: 'mc', aria: o.aria || 'auto' });
        cleanups.push(function () { split.revert(); });
        gsap.set(el, { autoAlpha: 1 });
        tl.from(o.chars ? split.chars : split.words, {
          yPercent: o.yPercent != null ? o.yPercent : 110, rotate: o.rotate || 0, transformOrigin: '0% 100%',
          duration: o.duration || .9, ease: o.ease || 'power4.out', stagger: o.stagger != null ? o.stagger : .04,
          onComplete: function () { split.revert(); }
        }, i * (o.each || 0));
      });
      return tl;
    }

    // Petits numéros (01, 02…) : chaque chiffre glisse depuis un masque.
    function roll(targets, o) {
      o = o || {};
      return words(targets, { chars: true, mask: 'chars', aria: 'none', yPercent: 100, stagger: .06,
        duration: o.duration || .7, ease: EASE, trigger: o.trigger, start: o.start || 'top 92%', delay: o.delay });
    }

    // Compteur 0 → valeur, texte autour conservé (« 8 projets », « 1 899 € »).
    function countUp(targets, o) {
      o = o || {};
      $$(targets).forEach(function (el) {
        if (el.children.length) return;
        var final = el.textContent;
        var m = final.match(/^(\D*?)(\d[\d   ]*\d|\d)([\s\S]*)$/); if (!m) return;
        var sep = (m[2].match(/[   ]/) || [''])[0];
        var target = parseInt(m[2].replace(/\D/g, ''), 10);
        var pad = /^0\d/.test(m[2]) ? m[2].length : 0;
        var fmt = function (n) {
          var s = String(Math.round(n));
          while (s.length < pad) s = '0' + s;
          if (sep) s = s.replace(/\B(?=(\d{3})+(?!\d))/g, sep);
          return m[1] + s + m[3];
        };
        var obj = { v: 0 };
        cleanups.push(function () { el.textContent = final; });
        el.textContent = fmt(0);
        gsap.to(obj, { v: target, duration: o.duration || 1.2, ease: o.ease || 'power2.out', delay: o.delay || 0,
          onUpdate: function () { el.textContent = fmt(obj.v); },
          onComplete: function () { el.textContent = final; },
          scrollTrigger: st(o, el) });
      });
    }

    // En-tête de section : filet qui se trace, eyebrow, titre en mots, chapô.
    function sectionHead(head, o) {
      o = o || {};
      head = $$(head)[0]; if (!head) return null;
      var tl = gsap.timeline({ scrollTrigger: { trigger: head, start: o.start || 'top 82%', once: true } });
      tl.fromTo(head, { '--rule': 0 }, { '--rule': 1, duration: 1.1, ease: 'power3.inOut' }, 0);
      var eb = head.querySelector('.eyebrow');
      if (eb) {
        track([eb]);
        tl.fromTo(eb, { '--ln': 0, autoAlpha: 0, x: -8 },
          { '--ln': 1, autoAlpha: 1, x: 0, duration: .7, ease: EASE, clearProps: 'transform,opacity,visibility' }, .1);
      }
      add(tl, words(head.querySelector('.h2'), Object.assign({ trigger: false }, o.title)), .2);
      add(tl, fadeUp(head.querySelectorAll('.lead'), { trigger: false, y: 16, stagger: .1 }), .45);
      return tl;
    }

    // Ajoute une animation à une timeline si elle existe (cible absente = rien).
    function add(tl, anim, pos) { if (anim) tl.add(anim, pos); return tl; }

    // Bouton « magnétique » (souris uniquement) : suit légèrement le pointeur.
    function magnetic(targets, strength) {
      strength = strength || .3;
      $$(targets).forEach(function (el) {
        var xTo = gsap.quickTo(el, 'x', { duration: .5, ease: EASE });
        var yTo = gsap.quickTo(el, 'y', { duration: .5, ease: EASE });
        on(el, 'pointermove', function (e) {
          var r = el.getBoundingClientRect();
          xTo((e.clientX - r.left - r.width / 2) * strength);
          yTo((e.clientY - r.top - r.height / 2) * strength);
        });
        on(el, 'pointerleave', function () { xTo(0); yTo(0); });
        cleanups.push(function () { gsap.set(el, { clearProps: 'transform' }); });
      });
    }

    // Écouteur DOM retiré automatiquement au revert.
    function on(el, type, fn, opts) {
      el.addEventListener(type, fn, opts);
      cleanups.push(function () { el.removeEventListener(type, fn, opts); });
    }

    return { gsap: gsap, ScrollTrigger: ST, $$: $$, conditions: ctx.conditions,
      fadeUp: fadeUp, batch: batch, words: words, roll: roll, countUp: countUp,
      sectionHead: sectionHead, magnetic: magnetic, on: on, track: track, add: add,
      onCleanup: function (fn) { cleanups.push(fn); } };
  }

  // Motion.run(setup, { desktop: fn, pointer: fn })
  //  - setup   : animations principales, jouées une seule fois ;
  //  - desktop : effets réservés aux écrans ≥ 921px (parallaxes) ;
  //  - pointer : effets souris (magnétisme, curseur, tilt).
  // `desktop` et `pointer` s'activent/se retirent selon le contexte
  // (redimensionnement, tablette…) sans rejouer les apparitions.
  function run(setup, extra) {
    extra = extra || {};
    var mm = gsap.matchMedia();

    mm.add(MOTION_OK, function (ctx) {
      var cleanups = [], animated = [];
      var reveals = $$('.reveal');
      reveals.forEach(function (el) { el.classList.remove('reveal'); });
      root.classList.add('motion');

      var api = createApi(ctx, cleanups, animated);
      setup(api);

      // Filet de sécurité : un .reveal sans animation dédiée reçoit un fondu.
      reveals.forEach(function (el) {
        var handled = animated.some(function (a) { return a === el || el.contains(a); });
        if (!handled) api.fadeUp(el);
      });

      // Hauteurs qui changent (onglets, FAQ, formulaire) : on recalcule.
      var t, ro = w.ResizeObserver && new ResizeObserver(function () {
        clearTimeout(t); t = setTimeout(function () { ST.refresh(); }, 150);
      });
      if (ro) ro.observe(d.body);

      unlock();
      return function () {
        clearTimeout(t);
        if (ro) ro.disconnect();
        cleanups.forEach(function (fn) { fn(); });
        root.classList.remove('motion');
      };
    });

    [['desktop', MOTION_OK + ' and (min-width: 921px)'],
     ['pointer', MOTION_OK + ' and (hover: hover) and (pointer: fine)']].forEach(function (p) {
      if (!extra[p[0]]) return;
      mm.add(p[1], function (ctx) {
        var cleanups = [];
        extra[p[0]](createApi(ctx, cleanups, []));
        return function () { cleanups.forEach(function (fn) { fn(); }); };
      });
    });
  }

  w.Motion = { run: run };
})(window, document);
