/* Animations birostweb.fr — sobres et premium : elles accompagnent la
   lecture et les CTA sans voler l'attention. Utilitaires : js/motion/core.js */
Motion.run(function (m) {
  var gsap = m.gsap;

  /* ---------- Hero : entrée au chargement ---------- */
  var hero = document.querySelector('.hero');
  if (hero) {
    var tl = gsap.timeline({ delay: .1 });
    m.add(tl, m.fadeUp('.hero .chip', { trigger: false, y: 10, duration: .6 }), 0);
    m.add(tl, m.words('.hero h1', { trigger: false, stagger: .035, duration: 1 }), .1);
    m.add(tl, m.fadeUp('.hero__sub', { trigger: false, y: 16 }), .45);
    m.add(tl, m.fadeUp('.hero__cta > *', { trigger: false, y: 14, stagger: .08, duration: .7 }), .6);
    m.add(tl, m.fadeUp('.hero__note', { trigger: false, y: 10, duration: .6 }), .8);

    var card = hero.querySelector('.hero__card');
    if (card) {
      m.track([card]);
      gsap.set(card, { autoAlpha: 1 });
      tl.fromTo(card.querySelector('.portrait'), { clipPath: 'inset(100% 0% 0% 0%)' },
        { clipPath: 'inset(0% 0% 0% 0%)', duration: 1.2, ease: 'power4.inOut', clearProps: 'clipPath' }, .15);
      tl.from(card.querySelector('.portrait__mono'), { autoAlpha: 0, scale: .9, duration: 1, ease: 'power3.out', clearProps: 'transform,opacity,visibility' }, .7);
      tl.from(card.querySelector('.portrait__badge'), { autoAlpha: 0, y: 14, duration: .7, ease: 'power3.out', clearProps: 'transform,opacity,visibility' }, .95);
    }
  }

  /* ---------- Bandeau bénéfices ---------- */
  m.batch('.bcell', { y: 16, stagger: .08, start: 'top 95%' });
  m.roll('.bcell .n', { trigger: document.querySelector('.bene'), start: 'top 95%' });

  /* ---------- En-têtes de section (filet + titre + chapô) ---------- */
  m.$$('.sec-head').forEach(function (h) { m.sectionHead(h, { title: { stagger: .03 } }); });

  /* ---------- Réalisations ---------- */
  m.$$('.project').forEach(function (p) {
    var ptl = gsap.timeline({ scrollTrigger: { trigger: p, start: 'top 82%', once: true } });
    m.add(ptl, m.fadeUp(p, { trigger: false, y: 36, duration: 1 }), 0);
    var img = p.querySelector('.shot__img');
    if (img) ptl.from(img, { scale: 1.06, duration: 1.4, ease: 'power3.out', clearProps: 'transform' }, 0);
    m.add(ptl, m.fadeUp(p.querySelectorAll(':scope > div:last-child > *'), { trigger: false, y: 12, stagger: .05, duration: .6 }), .25);
  });
  m.fadeUp('.subhead', { x: -12, y: 0, duration: .7 });
  m.batch('.mini', { y: 22, stagger: .08 });

  /* ---------- Offres & tarifs (onglets) ---------- */
  var tabs = document.querySelector('.tabs');
  if (tabs) {
    var visiblePanel = function () {
      return m.$$('.tabs__panel', tabs).filter(function (p) { return p.offsetParent !== null; })[0];
    };
    var playPanel = function (panel, first) {
      if (!panel) return;
      var items = m.$$(':scope > .offers > *, :scope > .hosting > *, :scope > .plans > *, :scope > .custom, :scope > .maint__head, :scope > .maint__note', panel);
      m.track(items);
      gsap.set(items, { transition: 'none' });
      var ptl = gsap.timeline();
      ptl.fromTo(items, { autoAlpha: 0, y: first ? 30 : 16 },
        { autoAlpha: 1, y: 0, duration: first ? .9 : .55, ease: 'power3.out', stagger: first ? .1 : .06, clearProps: 'transform,opacity,visibility,transition' });
      var badges = panel.querySelectorAll('.offer__badge, .plan__badge');
      if (badges.length) ptl.from(badges, { autoAlpha: 0, y: 6, duration: .5, ease: 'back.out(2)', clearProps: 'transform,opacity,visibility' }, first ? .45 : .25);
      m.countUp(panel.querySelectorAll('.offer__price .amt'), { trigger: false, duration: first ? 1.1 : .8, delay: first ? .3 : .1 });
    };

    // Premier affichage : au scroll.
    var nav = tabs.querySelector('.tabs__nav');
    if (nav) m.fadeUp(nav, { y: 12, duration: .6, start: 'top 88%' });
    var firstPanel = visiblePanel();
    if (firstPanel) {
      gsap.set(firstPanel, { autoAlpha: 0 });
      m.ScrollTrigger.create({ trigger: tabs, start: 'top 80%', once: true, onEnter: function () {
        gsap.set(firstPanel, { autoAlpha: 1 });
        playPanel(firstPanel, true);
      } });
    }

    // Changement d'onglet (clic sur un onglet ou bouton « Choisir… »).
    var current = firstPanel;
    var check = function () {
      setTimeout(function () {
        var p = visiblePanel();
        if (p && p !== current) { current = p; playPanel(p, false); }
      }, 0);
    };
    m.on(tabs, 'change', check);
    m.on(tabs, 'click', check);
  }

  /* ---------- Approche ---------- */
  m.batch('.step', { y: 26, stagger: .12 });
  m.roll('.step .n');
  m.batch('.stack .tag', { y: 10, stagger: .03, duration: .5 });

  /* ---------- FAQ ---------- */
  m.batch('.faq__item', { y: 14, stagger: .06, duration: .6 });

  /* ---------- Contact ---------- */
  var cta = document.querySelector('.cta');
  if (cta) {
    var col = cta.querySelector('.cta__grid > div');
    var ctl = gsap.timeline({ scrollTrigger: { trigger: cta, start: 'top 75%', once: true } });
    if (col) {
      m.add(ctl, m.fadeUp(col.querySelector('.eyebrow'), { trigger: false, x: -8, y: 0, duration: .6 }), 0);
      m.add(ctl, m.words(col.querySelector('.h2'), { trigger: false, stagger: .04 }), .1);
      m.add(ctl, m.fadeUp(col.querySelectorAll(':scope > .lead, :scope > .btn'), { trigger: false, y: 14, stagger: .1 }), .35);
    }
    m.add(ctl, m.fadeUp(cta.querySelector('.form'), { trigger: false, y: 30, duration: 1 }), .25);
    m.batch('.mrow', { x: -10, y: 0, stagger: .06, duration: .6 });
  }
});
