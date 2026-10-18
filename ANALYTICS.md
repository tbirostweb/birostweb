# Google Analytics 4 — birostweb.fr

GA4 ne se charge **qu'après acceptation** du bandeau de consentement. Umami (auto-hébergé) reste en place, indépendamment.

## Où configurer l'identifiant
- Dokploy → application **birostweb** → *Environment* : `GA4_MEASUREMENT_ID=G-5MVJ5K3QXZ`, puis redéployer.
- C'est la seule source : `site/ga-config.php` expose l'identifiant (public) à `site/js/consent.js`. Variable absente ou invalide = ni bandeau, ni Google Analytics.
- Aucun secret n'est impliqué (l'identifiant de mesure est visible dans le code de toute page qui charge gtag.js).

## Comportement
- Premier passage : bandeau « Refuser / Accepter » (mêmes taille et style). Choix mémorisé 6 mois dans le navigateur (`localStorage`, clé `bw_consent_v1`), puis redemandé.
- Refus : aucune requête vers Google. Un refus après une acceptation supprime les cookies `_ga*` et désactive l'envoi.
- Modifier le choix : lien « Cookies » dans le pied de page, ou bouton dans les mentions légales (`/mentions-legales.html#cookies`).
- Configuration envoyée : signaux Google et personnalisation publicitaire désactivés, cookies 390 jours maximum (`SameSite=Lax; Secure`), URL envoyée sans paramètres ni ancre. Aucun nom, e-mail, message ni IP personnalisée.
- Le site est multi-pages (pas d'application monopage) : chaque page chargée envoie un `page_view`. Les ancres internes (`#contact`, etc.) ne déclenchent pas de vue.

## Événements
| Événement | Déclencheur | Paramètres |
|---|---|---|
| `page_view` | chargement d'une page | (automatique GA4) |
| `cta_devis_click` | lien vers `#contact` contenant « devis » (« Devis gratuit », « Demander un devis… ») | `cta_location` (`header`, `hero`, `section`, `sticky_mobile`), `cta_text` |
| `select_offer` | bouton « Choisir cette offre » (et boutons du configurateur `data-step`) | `offer_step`, `offer_name` |
| `click_calcom` | lien vers cal.com | `link_url` (sans query) |
| `click_social` | lien GitHub / LinkedIn / Instagram | `network`, `link_url` |
| `click_outbound` | autre lien externe (projets présentés…) | `link_domain`, `link_url` |
| `file_download` | lien vers pdf/zip/doc/xls/ppt/csv… | `file_name`, `file_extension` |
| `generate_lead` | envoi réussi du formulaire de contact (statut de succès affiché) | `form_id` = `contact` |

Dans GA4, marquer `generate_lead` (et au besoin `click_calcom`, `cta_devis_click`) comme **événements clés** (Admin → Événements).

## Tester
1. Ouvrir le site en navigation privée : le bandeau s'affiche, aucune requête vers `googletagmanager.com` / `google-analytics.com` (onglet Réseau).
2. Cliquer **Refuser** : toujours aucune requête, pas de cookie `_ga`.
3. Vider `localStorage` (ou lien « Cookies »), cliquer **Accepter** : `gtag/js?id=G-…` est chargé, cookies `_ga` posés.
4. GA4 → *Admin → DebugView* (ou *Rapports → Temps réel*) : cliquer sur les CTA, Cal.com, réseaux sociaux ; envoyer un message test via le formulaire ; vérifier les événements ci-dessus.
5. Console navigateur : `dataLayer` doit contenir les appels `event` (sans données saisies).

## Réglages à faire dans le compte GA4 (hors code)
- *Admin → Collecte et modification des données → Conservation des données* : **14 mois** (ou 2 mois). Les mentions légales annoncent « 14 mois au plus ».
- *Signaux Google* : désactivés (déjà désactivés côté balise ; vérifier aussi dans Admin).
- *Flux de données → Configurer les paramètres de balise → Définir le trafic interne* : règle « trafic interne » avec ton adresse IP publique, puis *Admin → Paramètres de données → Filtres de données* : activer le filtre « Trafic interne » (d'abord en « Test », puis « Actif »). Les adresses IP ne sont pas lues par le site : le filtre est évalué par Google.
- Alternative simple pour toi : refuser le bandeau sur tes navigateurs.
- Ne pas activer le mode « Consentement » publicitaire ni la liaison Google Ads sans revoir le bandeau et les mentions légales.

## Limites
- Les clics et impressions de la **Search Console** sont mesurés par Google dans les résultats de recherche ; ils ne peuvent pas être filtrés par IP.
- Les visiteurs qui refusent ne sont pas comptés dans GA4 (écart attendu avec Umami).
- Google Analytics implique des transferts possibles hors UE et reste soumis à ton propre jugement juridique ; relis les mentions légales avant la mise en production.
