/* Consentement + Google Analytics 4 (chargé UNIQUEMENT après acceptation).
 *
 * - L'identifiant de mesure vient de /ga-config.php (variable d'environnement GA4_MEASUREMENT_ID).
 *   Absent => ni bandeau, ni Google Analytics.
 * - Choix stocké dans le navigateur (localStorage, clé bw_consent_v1) et redemandé après 6 mois.
 * - Aucune donnée personnelle envoyée : pas de contenu de formulaire, pas d'e-mail, URL sans query ni ancre.
 * - Événements (voir ANALYTICS.md) : cta_devis_click, select_offer, click_calcom, click_social,
 *   click_outbound, file_download, generate_lead.
 */
(function () {
  'use strict';
  var KEY = 'bw_consent_v1', MAX_AGE_MS = 180 * 24 * 3600 * 1000;
  var gaId = null, loaded = false, active = false, banner = null;

  function readChoice() {
    try {
      var c = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (c && (c.a === 0 || c.a === 1) && typeof c.t === 'number' && Date.now() - c.t < MAX_AGE_MS) return c.a;
    } catch (e) {}
    return null;
  }
  function saveChoice(a) { try { localStorage.setItem(KEY, JSON.stringify({ a: a, t: Date.now() })); } catch (e) {} }

  /* ---------- Google Analytics ---------- */
  function cleanUrl() { return location.origin + location.pathname; }

  function send(name, params) {
    if (!active || typeof window.gtag !== 'function') return;
    window.gtag('event', name, params || {});
  }

  function loadGA() {
    if (loaded || !gaId) { active = !!gaId; return; }
    loaded = active = true;
    window['ga-disable-' + gaId] = false;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('consent', 'default', {
      analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'
    });
    window.gtag('js', new Date());
    window.gtag('config', gaId, {
      allow_google_signals: false,
      allow_ad_personalization_signals: false,
      cookie_expires: 33696000,            /* 390 jours (< 13 mois) */
      cookie_flags: 'SameSite=Lax;Secure',
      page_location: cleanUrl()            /* sans query ni ancre */
    });
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(gaId);
    document.head.appendChild(s);
    bindEvents();
  }

  function revokeGA() {
    active = false;
    if (gaId) window['ga-disable-' + gaId] = true;
    var host = location.hostname, parts = host.split('.'), root = parts.slice(-2).join('.');
    document.cookie.split(';').forEach(function (c) {
      var n = c.split('=')[0].trim();
      if (n === '_ga' || n.indexOf('_ga_') === 0 || n === '_gid') {
        [host, '.' + host, '.' + root].forEach(function (d) {
          document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=' + d;
        });
        document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
      }
    });
  }

  /* ---------- Événements ---------- */
  var bound = false;
  function ctaLocation(a) {
    if (a.closest('.sticky-cta')) return 'sticky_mobile';
    if (a.closest('nav, header, .nav')) return 'header';
    if (a.closest('.hero, #top')) return 'hero';
    return 'section';
  }
  function linkInfo(a) {
    var href = a.getAttribute('href') || '', u;
    try { u = new URL(href, location.href); } catch (e) { return null; }
    return u;
  }
  var SOCIAL = { 'github.com': 'github', 'www.linkedin.com': 'linkedin', 'linkedin.com': 'linkedin', 'www.instagram.com': 'instagram', 'instagram.com': 'instagram' };
  var FILE_RE = /\.(pdf|zip|docx?|xlsx?|pptx?|csv|rar|7z)$/i;

  function bindEvents() {
    if (bound) return;
    bound = true;
    document.addEventListener('click', function (e) {
      if (!active) return;
      var a = e.target.closest ? e.target.closest('a') : null;
      if (!a) return;

      /* Choix d'une offre : boutons du configurateur (data-step="offre") */
      if (a.hasAttribute('data-step')) {
        send('select_offer', { offer_step: a.getAttribute('data-step') || '', offer_name: (a.getAttribute('data-label') || '').slice(0, 100) });
        return;
      }
      var u = linkInfo(a);
      if (!u) return;
      var txt = (a.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();

      /* CTA vers le formulaire : « Devis gratuit », « Demander un devis… » */
      if (u.origin === location.origin && u.hash === '#contact' && txt.indexOf('devis') !== -1) {
        send('cta_devis_click', { cta_location: ctaLocation(a), cta_text: txt.slice(0, 60) });
        return;
      }
      if (u.origin !== location.origin) {
        var clean = u.origin + u.pathname;
        if (u.hostname === 'cal.com') { send('click_calcom', { link_url: clean }); return; }
        if (SOCIAL[u.hostname]) { send('click_social', { network: SOCIAL[u.hostname], link_url: clean }); return; }
        send('click_outbound', { link_domain: u.hostname, link_url: clean });
        return;
      }
      if (FILE_RE.test(u.pathname)) {
        send('file_download', { file_name: u.pathname.split('/').pop(), file_extension: u.pathname.split('.').pop().toLowerCase() });
      }
    }, true);

    /* Envoi du formulaire de contact : succès affiché par index.php dans #cstatus (aucune donnée saisie transmise) */
    var st = document.getElementById('cstatus');
    if (st && window.MutationObserver) {
      new MutationObserver(function () {
        if (/\bok\b/.test(st.className)) send('generate_lead', { form_id: 'contact' });
      }).observe(st, { attributes: true, attributeFilter: ['class'] });
    }
  }

  /* ---------- Bandeau ---------- */
  function ensureCss() {
    if (document.getElementById('bw-consent-css')) return;
    var l = document.createElement('link');
    l.id = 'bw-consent-css'; l.rel = 'stylesheet'; l.href = '/css/consent.css';
    document.head.appendChild(l);
  }
  function buildBanner() {
    ensureCss();
    var d = document.createElement('div');
    d.className = 'bw-consent';
    d.setAttribute('role', 'region');
    d.setAttribute('aria-label', 'Préférences de mesure d’audience');
    d.innerHTML =
      '<p class="bw-consent__t">Mesure d’audience</p>' +
      '<p class="bw-consent__p">Avec votre accord, j’utilise Google Analytics pour comprendre comment le site est utilisé (pages vues, clics sur les boutons de contact). Aucun contenu de formulaire n’est transmis. Refuser ne change rien à votre navigation, et vous pouvez modifier votre choix à tout moment. <a href="/mentions-legales.html#cookies">En savoir plus</a></p>' +
      '<div class="bw-consent__b">' +
      '<button type="button" class="bw-consent__btn" data-bw="refuse">Refuser</button>' +
      '<button type="button" class="bw-consent__btn" data-bw="accept">Accepter</button></div>';
    d.addEventListener('click', function (e) {
      var b = e.target.closest('[data-bw]');
      if (!b) return;
      var accept = b.getAttribute('data-bw') === 'accept';
      saveChoice(accept ? 1 : 0);
      if (accept) loadGA(); else revokeGA();
      closeBanner();
    });
    return d;
  }
  function openBanner() {
    if (!gaId) return;
    if (!banner) { banner = buildBanner(); document.body.appendChild(banner); }
    banner.hidden = false;
    var first = banner.querySelector('button');
    if (first) first.focus({ preventScroll: true });
  }
  function closeBanner() { if (banner) banner.hidden = true; }

  function initOpeners() {
    document.querySelectorAll('[data-consent-open]').forEach(function (el) {
      el.hidden = false;
      el.addEventListener('click', function (e) { e.preventDefault(); openBanner(); });
    });
  }

  /* ---------- Démarrage ---------- */
  function start() {
    fetch('/ga-config.php', { credentials: 'omit' })
      .then(function (r) { return r.ok ? r.json() : { id: null }; })
      .then(function (cfg) {
        gaId = cfg && typeof cfg.id === 'string' ? cfg.id : null;
        if (!gaId) return;
        initOpeners();
        var c = readChoice();
        if (c === 1) loadGA();
        else if (c === null) openBanner();
      })
      .catch(function () {});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
