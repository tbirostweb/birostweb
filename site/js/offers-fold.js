/* Mobile : cartes d'offres, réalisations et parcours repliés (titre visible), détail au clic sur « Voir le détail ».
   Chargé en synchrone dans le <head> : la classe js-fold n'est posée que si ce script s'exécute,
   sans JS les cartes restent entièrement visibles. */
(function (root) {
  root.classList.add('js-fold');
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('.fold-toggle') : null;
    if (!t) return;
    var card = t.closest('.offer, .plan, .project, .mini');
    if (!card) return;
    var open = card.classList.toggle('is-open');
    t.setAttribute('aria-expanded', open ? 'true' : 'false');
    t.querySelector('span').textContent = open ? 'Masquer le détail' : 'Voir le détail';
  });
})(document.documentElement);
