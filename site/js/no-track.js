/* Exclut des mesures (GA4 + Umami) les agents automatisés et les visites marquées « no_track ».
   Chargé en synchrone dans le <head>, AVANT consent.js et le script Umami. Ne charge rien et ne touche
   pas au consentement : sans accord du visiteur, rien n'est envoyé, comme avant.
   - ?no_track=1 : mémorise l'exclusion dans ce navigateur ; ?no_track=0 : la retire (pour tester le suivi).
   - Le refus Umami d'un visiteur (clé umami.disabled posée par le bouton « Refuser ») n'est jamais effacé.
   - L'identifiant GA4 ci-dessous doit rester identique à GA4_MEASUREMENT_ID (voir ANALYTICS.md). */
(function () {
  try {
    var p = new URLSearchParams(location.search);
    if (p.get('no_track') === '1') localStorage.setItem('bw_no_track', '1');
    if (p.get('no_track') === '0') localStorage.removeItem('bw_no_track');
    var bot = navigator.webdriver === true || /HeadlessChrome|Playwright|Puppeteer|PhantomJS|Lighthouse|bot|crawler|spider/i.test(navigator.userAgent);
    if (bot || localStorage.getItem('bw_no_track') === '1') {
      window['ga-disable-G-5MVJ5K3QXZ'] = true;   // GA4 n'envoie rien
      // Umami n'envoie rien. Si ce navigateur avait DÉJÀ refusé Umami (bouton « Refuser », même clé),
      // on ne pose pas notre marqueur : ce refus-là n'est jamais effacé par ce script.
      if (localStorage.getItem('umami.disabled') !== '1') {
        localStorage.setItem('umami.disabled', '1');
        localStorage.setItem('bw_umami_by_no_track', '1');
      }
    } else if (localStorage.getItem('bw_umami_by_no_track') === '1') {
      // Fin de l'exclusion (?no_track=0) : on retire uniquement ce que ce script a posé.
      localStorage.removeItem('umami.disabled');
      localStorage.removeItem('bw_umami_by_no_track');
    }
  } catch (e) { /* stockage indisponible : mesure normale */ }
})();
