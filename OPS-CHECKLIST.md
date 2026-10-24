# Checklist d'exploitation — birostweb (points hors code, à faire côté Dokploy / VPS / DNS)

## État au 18/10/2026

### Faits vérifiés (par contrôle direct ; source : vérifications Codex/Dokploy/VPS et tests en production)
- **Runtime :** PHP 8.3.35, processus web `www-data`, conteneur Healthy ; code en lecture seule pour le serveur web ; image à jour avec le dépôt (`main`).
- **Production (GET/HEAD) :** en-têtes HSTS/CSP/X-Frame-Options/nosniff/Referrer-Policy/Permissions-Policy présents ; redirections http→https, www→apex, `/admin`→`/admin/` ; `.env`, `composer.*`, `vendor/`, `inc/`, `_bootstrap.php`, `entrypoint.sh` en 403 ; `.git`, `data/`, `tests/`, `purge-contact.php` en 404 ; cookie `bwadmin` Secure/HttpOnly/SameSite=Strict ; aucun cookie sur `/` et `/galerie` ; TLS 1.0/1.1 refusés ; certificat Let's Encrypt valide jusqu'au 08/01/2027.
- **Purge `purge-contact` :** tâche quotidienne à 04 h UTC activée ; test manuel réussi (code de sortie 0).
- **SSH :** connexion par clé validée ; mots de passe et authentification interactive désactivés.
- **Portfolio :** port MySQL public fermé.
- **Umami 3.4 :** `DISABLE_TELEMETRY=1` et `PRIVATE_MODE=1` actifs ; purge mensuelle des données analytiques de plus de 24 mois installée (sauvegarde préalable, suppression du dump temporaire après validation) ; PostgreSQL non redémarré.
- **Messagerie :** envoi via OVH (`pro2.mail.ovh.net`), aucun serveur de secours dans le code (SMTP2 absent de Dokploy) ; `CONTACT_LOG_RETENTION_DAYS=30`.
- **Document public :** les mentions légales et la politique de confidentialité décrivent Umami (auto-hébergé, mode privé, télémétrie off, purge à 24 mois) sans affirmer d'exemption de consentement, d'anonymat complet ni d'absence de transfert tiers.

### Points ouverts
1. **Pare-feu OVH incomplet** : règles Edge Firewall à compléter (autoriser TCP « established », 22, 80, 443, ICMP ; refuser le reste), puis tester site, www et SSH. Vérifier qu'aucun port interne (interface Dokploy 3000, bases de données) n'est joignable de l'extérieur.
2. **Persistance du filtrage Swarm** : les règles de filtrage appliquées sur l'hôte (Docker Swarm) doivent survivre à un redémarrage du serveur et au redémarrage de Docker ; à valider par un test contrôlé.
3. **Validation du TOTP** : confirmer une connexion complète `/admin` avec identifiant + mot de passe + code, et le rejet d'un code rejoué ou faux.
4. **Rotation des secrets** : à confirmer et à tracer (date, secrets concernés : `CONTACT_FORM_SECRET`, hash admin, mot de passe SMTP, clés Bunny Storage/Stream, éventuel `ADMIN_PASSWORD` inutilisé à supprimer) ; les valeurs apparues dans des sorties techniques sont à considérer comme compromises tant que la rotation n'est pas faite.
5. **Umami** : exemption de consentement **non établie** (la page publique ne la revendique pas) ; à confirmer par l'absence de colonne IP en base (`\d session`) et par le contrôle de la purge mensuelle (première exécution). Transferts et sous-traitants (OVH, Bunny.net) à garder à jour dans le registre.
6. **E-mail** : SPF, DKIM, DMARC du domaine d'envoi à vérifier.
7. **Sauvegardes** : restauration de la sauvegarde VPS OVH non testée ; copie de `data/content.json` hors Bunny.
8. **Google Analytics 4 (code prêt, non déployé)** : réglages GA4 faits et vérifiés (conservation 14 mois, signaux Google désactivés, filtre de trafic interne actif sur l'IP actuelle — à mettre à jour si ton IP change). Reste : pousser le code, ajouter `GA4_MEASUREMENT_ID` dans Dokploy, redéployer, puis vérifier en production que la CSP laisse passer le bandeau et `gtag` après acceptation. Détails : `ANALYTICS.md`.
9. **Autres** : limites CPU/RAM Dokploy, notifications/alertes (aucune configurée), CSP `img-src` à resserrer sur l'hôte exact de la Pull Zone, registre des traitements et procédure de violation (CNIL 72 h), audit d'accessibilité.

---

## Variables d'environnement à ajouter (Dokploy → Environment)
- `ADMIN_TOTP_SECRET` : secret **base32** (A-Z, 2-7). Génération correcte : `php -r '$a="ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";$b=random_bytes(20);$bits="";foreach(str_split($b) as $c)$bits.=str_pad(decbin(ord($c)),8,"0",STR_PAD_LEFT);echo implode("",array_map(fn($x)=>$a[bindec($x)],str_split($bits,5))),PHP_EOL;'` (32 caractères), à saisir dans une appli TOTP (SHA-1, 6 chiffres, 30 s). **À ajouter seulement APRÈS le déploiement de la nouvelle image** (l'image actuelle ignore cette variable). Sans la variable, la connexion reste inchangée.
- `BUNNY_STREAM_HOST` (hôte des vignettes Bunny Stream) si ce n'est pas déjà fait.
- `CONTACT_LOG_DIR` + volume persistant si l'on veut garder les journaux après redéploiement (le conteneur tourne maintenant en `www-data` : le volume doit lui être inscriptible, uid 33).
- Vérifier `CONTENT_BACKEND=bunny` (le back-office affiche désormais un avertissement sinon).

## Tâches planifiées
- `php /var/www/html/purge-contact.php` : **en place** (Dokploy, tous les jours à 04 h UTC, testé).

## Vérifications en production (lecture seule)
- `curl -sI https://birostweb.fr/` : HSTS, CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy ; `http://` → redirection `https://` ; `www` → apex.
- `/.git/config`, `/.env`, `/vendor/autoload.php`, `/composer.json`, `/inc/content_store.php` : 403/404.
- Cookie `bwadmin` : HttpOnly, Secure, SameSite=Strict, path `/admin`.
- CSP `img-src` : remplacer `https://*.b-cdn.net` par l'hôte exact de la Pull Zone (`BUNNY_CDN_HOST`) et de Bunny Stream dans `site/.htaccess`.

## DNS / e-mail
- SPF, DKIM, DMARC du domaine d'envoi (OVH `theo-birost.fr` ; Gmail si encore utilisé) ; envoyer un message de test et lire les en-têtes.

## Sauvegarde / reprise
- Exporter régulièrement `data/content.json` depuis Bunny Storage (copie hors Bunny) ; tester une restauration dans une zone de test. Objectifs à noter : RPO, RTO.

## VPS / Dokploy
- SSH par clé uniquement, mot de passe et root désactivés ; pare-feu limité à 22/80/443 ; mises à jour OS et Docker ; pas de `docker.sock` monté dans l'app ; limites CPU/RAM et redémarrage automatique sur l'application ; monitoring (uptime, certificat, 5xx, disque) avec une alerte de test reçue.

## Conformité (à relire par l'éditeur)
- Mentions légales : confirmer les mentions « À CONFIRMER » restantes (purge effective des messages, retrait de l'ancien secours Gmail).
- Umami : vérifier dans l'instance qu'aucune IP brute n'est conservée et fixer la durée de conservation (≤ 25 mois) ; sinon prévoir un consentement.
- CGV : réservées aux professionnels (B2B). Si des particuliers peuvent commander, ajouter rétractation/médiation (fonction de rétractation en ligne obligatoire à partir du 19/06/2026).
- Registre des traitements et procédure de violation de données (notification CNIL sous 72 h si risque).
- Accessibilité : audit axe/Lighthouse avant mise en prod ; vérifier l'exemption éventuelle (micro-entreprise de services).

## Dépendances embarquées (à surveiller)
- GSAP 3.15.0, Altcha 1.5.1 (JS auto-hébergés dans `site/js`), PHPMailer 6.12.0 (`composer audit` sans alerte au dernier contrôle).
