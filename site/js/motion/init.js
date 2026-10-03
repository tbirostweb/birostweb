/* Motion — pré-chargement (chargé en synchrone dans le <head>, avant le rendu).
   Masque uniquement le haut de page le temps que GSAP prenne le relais,
   pour éviter un flash de contenu. Sécurité : si les scripts d'animation
   ne se chargent pas, tout redevient visible au bout de 2,5 s. */
(function (root) {
  if (!window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  root.classList.add('motion', 'm-pending');
  setTimeout(function () { root.classList.remove('m-pending'); }, 2500);
})(document.documentElement);
