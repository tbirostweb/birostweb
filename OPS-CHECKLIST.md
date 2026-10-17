# Checklist d'exploitation — birostweb (points hors code, à faire côté Dokploy / VPS / DNS)

## Variables d'environnement à ajouter (Dokploy → Environment)
- `ADMIN_TOTP_SECRET` : secret **base32** (A-Z, 2-7). Génération correcte : `php -r '$a="ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";$b=random_bytes(20);$bits="";foreach(str_split($b) as $c)$bits.=str_pad(decbin(ord($c)),8,"0",STR_PAD_LEFT);echo implode("",array_map(fn($x)=>$a[bindec($x)],str_split($bits,5))),PHP_EOL;'` (32 caractères), à saisir dans une appli TOTP (SHA-1, 6 chiffres, 30 s). **À ajouter seulement APRÈS le déploiement de la nouvelle image** (l'image actuelle ignore cette variable). Sans la variable, la connexion reste inchangée.
- `BUNNY_STREAM_HOST` (hôte des vignettes Bunny Stream) si ce n'est pas déjà fait.
- `CONTACT_LOG_DIR` + volume persistant si l'on veut garder les journaux après redéploiement (le conteneur tourne maintenant en `www-data` : le volume doit lui être inscriptible, uid 33).
- Vérifier `CONTENT_BACKEND=bunny` (le back-office affiche désormais un avertissement sinon).

## Tâches planifiées
- Cron quotidien dans le conteneur : `php /var/www/html/purge-contact.php` (purge compteurs/journaux).

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
