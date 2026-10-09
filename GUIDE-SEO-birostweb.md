# Guide SEO birostweb.fr — ce que tu fais toi-même

Guide pas à pas pour les actions SEO qui ne sont pas dans le code et que tu dois faire depuis tes comptes.
Ordre conseillé : du plus important (en haut) au moins urgent. Coche au fur et à mesure.

Cible géographique retenue : **Troyes** (et l'Aube), même si tu habites Bréviandes — c'est plus gros et plus recherché.

---

## 1. Google Search Console — LE plus important (15 min)

C'est le tableau de bord qui dit à Google « voilà mon site » et qui te montre sur quelles recherches tu apparais.

1. Va sur https://search.google.com/search-console et connecte-toi avec ton compte Google.
2. Clique sur « Ajouter une propriété » → choisis **Préfixe d'URL** → tape `https://birostweb.fr` → Continuer.
3. Méthode de validation la plus simple ici : **Balise HTML** ou **Fichier HTML**.
   - Si tu choisis « Fichier HTML » : Google te donne un fichier `googleXXXX.html` à déposer à la racine du site. Envoie-le-moi, je l'ajoute au repo et tu redéploies.
   - Si tu choisis « Balise HTML » : Google te donne une balise `<meta name="google-site-verification" ...>`. Copie-la-moi, je la mets dans le `<head>` et tu redéploies.
   - (Alternative DNS, chez OVH : ajouter un enregistrement TXT. Un peu plus technique, dis-moi si tu préfères.)
4. Une fois validé : menu **Sitemaps** → ajoute `sitemap.xml` → Envoyer.
5. Reviens voir « Performances » et « Indexation » après quelques jours : tu verras tes requêtes, tes positions et tes clics.

---

## 2. Vérifier et muscler ta fiche Google (tu l'as déjà) (20 min)

Ta fiche existe : il s'agit de l'optimiser. Va sur https://business.google.com.

- **Nom** : uniquement ta marque « Birostweb » (ou « Théo Birost »). ⚠️ Ne mets PAS de mots-clés dedans (pas « Birostweb création site web pas cher ») — c'est contraire aux règles Google et ça peut suspendre la fiche.
- **Catégorie principale** : « Concepteur de sites Web » (ou « Développeur de logiciels »). Ajoute des catégories secondaires pertinentes.
- **Zone desservie** : comme tu travailles à distance, configure-la en « zone desservie » et ajoute **Troyes, l'Aube, le Grand Est** (et France si tu veux large).
- **Site web** : mets bien `https://birostweb.fr`.
- **Téléphone** : ton 06 59 75 39 08.
- **Description** : 1 paragraphe avec tes mots-clés naturels (développeur web freelance, sites sur-mesure, boutiques en ligne, applications web).
- **Photos** : ajoute ton portrait, ton logo, et des captures de tes réalisations. Les fiches avec photos sont beaucoup plus cliquées.
- **Services** : liste tes prestations (site vitrine, e-commerce, application web, maintenance) avec un court descriptif chacune.

---

## 3. Bing Webmaster Tools — bonus facile (5 min)

Bing alimente aussi des réponses d'IA (Copilot, et en partie ChatGPT).

1. Va sur https://www.bing.com/webmasters.
2. Connecte-toi et choisis **« Importer depuis Google Search Console »** → ça récupère tout automatiquement. Rien d'autre à faire.

---

## 4. Choisir 3 à 5 mots-clés cibles (30 min)

Ces mots-clés guideront les futures pages de contenu (voir avec moi).

Méthode gratuite, tirée de la vidéo :
- Tape un début de phrase dans Google et regarde les **suggestions automatiques** (ex. « développeur web freel… », « créer un site inter… »).
- Utilise l'astérisque : tape `comment * un site web` → Google propose des variantes (créer, référencer, sécuriser…).
- Outils gratuits : **Google Keyword Planner** (dans Google Ads) et **Haloscan**.

Exemples de pistes pour toi (à valider) :
- `développeur web freelance Troyes`
- `création site internet Troyes` / `création site vitrine Aube`
- `développeur Vue.js freelance`
- `refonte site web entreprise`
- `créer une boutique en ligne sur-mesure`

➡️ Note tes 3-5 mots-clés retenus et envoie-les-moi : je bâtis les pages autour.

---

## 5. Récolter des avis clients (en continu)

Les avis = confiance + étoiles dans Google + meilleur classement local. (Ta section « témoignages » est déjà prête sur le site, on l'activera avec de vrais avis.)

1. Dans ta fiche Google Business, récupère ton **lien d'avis** (« Demander des avis » → copier le lien).
2. Après chaque projet livré, envoie ce lien au client avec un petit mot.
3. Vise au moins 5 avis pour commencer. Réponds à chacun (ça compte aussi).

---

## 6. Obtenir des backlinks (liens vers ton site) (en continu)

Plus des sites sérieux pointent vers toi, plus Google te fait confiance.

- **Le plus efficace pour toi** : en bas de chaque site client que tu livres, mets un petit « Site réalisé par Birostweb » qui pointe vers birostweb.fr (demande l'accord du client). Chaque site = un backlink de qualité.
- **Profils freelance** : crée/complète Malt, LinkedIn, Codeur.com, Comet… avec le champ « site web » rempli.
- **Annuaires** : inscris-toi sur quelques annuaires d'entreprises locales (Aube / Troyes) et de freelances.
- **Cohérence** : partout, écris ton nom, ta ville (Troyes) et ton tél. **de façon identique** (on appelle ça le NAP). Ça renforce le SEO local.

---

## 7. Routine mensuelle (15 min/mois)

- Ouvre Google Search Console : regarde tes requêtes qui montent, tes positions.
- Ajoute 1 avis client si possible.
- Ajoute 1 backlink (un nouveau profil, un site client livré).
- Si on a lancé un blog/des pages : publie ou mets à jour 1 contenu.

---

## Ce que je gère de mon côté (pour mémoire)

Déjà fait dans le code : FAQ pour Google, redirection www, données structurées (avec ton tél + Troyes), meta description, image de partage, sitemap, compression.
En attente de toi : le code de validation Search Console (étape 1.3), tes mots-clés (étape 4), et ta validation pour les pages de contenu / la galerie projets.

---
*Guide généré le 2026-10-09. Ce fichier est pour toi — il ne fait pas partie du site et n'est pas mis en ligne.*
