# birostweb.fr

Site vitrine de **Birostweb** (Théo Birost, développeur web full-stack).
Application **PHP + Apache** conteneurisée, déployée sur **Dokploy**, avec un
formulaire de contact qui envoie par **SMTP** (comme le portfolio).

## Structure

```
birostweb/
├── Dockerfile            # image de prod (php:8.3-apache, non-root www-data, port 8080)
├── entrypoint.sh         # port dynamique Apache ($PORT, défaut 8080, via LISTEN_PORT)
├── composer.json/.lock   # dépendances PHP (PHPMailer, phpdotenv)
├── .env.example          # variables à définir dans Dokploy (NE PAS committer de vraies valeurs)
├── OPS-CHECKLIST.md      # état d'exploitation, faits vérifiés et points ouverts
├── tests/                # tests PHP/shell (CSP, formulaire, store, migration, TOTP)
├── tools/content-migrate.php  # migration / sauvegarde du contenu (copié dans /opt/birostweb)
├── .dockerignore
└── site/                 # racine web (DocumentRoot)
    ├── index.php         # page (génère le jeton anti-spam du formulaire)
    ├── send_mail.php     # endpoint du formulaire (validation + SMTP)
    ├── .htaccess         # en-têtes de sécurité + CSP + blocage fichiers sensibles
    ├── favicon.png       # icône BW
    ├── og-image.png      # image de partage réseaux sociaux (1200×630)
    ├── robots.txt / sitemap.xml
    ├── img/              # captures des projets (hydrogen, clicker)
    ├── fonts/            # polices IBM Plex auto-hébergées (RGPD, 0 requête Google)
    └── .well-known/security.txt
```

## Sécurité

- **En-têtes** (via `.htaccess`, mod_headers) : HSTS, CSP, `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP,
  `X-Powered-By` retiré.
- **CSP stricte** : `script-src` verrouillé par le **hash SHA-256** du script inline.
- **Formulaire durci** : jeton **HMAC** signé + délai minimum, **honeypot**,
  **rate-limit** par IP, validation stricte, listes blanches pour les champs à choix,
  échappement HTML de l'email. From = compte SMTP, visiteur en Reply-To.
- **Fichiers sensibles bloqués** : `.env`, `composer.*`, `vendor/`, `.htaccess`.
- **Polices auto-hébergées** → aucune fuite d'IP vers Google (RGPD).

> ⚠️ Si tu modifies le `<script>` de `site/index.php`, régénère le hash CSP :
> ```bash
> php -r '$h=file_get_contents("site/index.php");preg_match("/<script>(.*?)<\/script>/s",$h,$m);echo "sha256-".base64_encode(hash("sha256",$m[1],true))."\n";'
> ```
> puis remplace la valeur `script-src 'sha256-...'` dans `site/.htaccess`.

## Variables d'environnement (Dokploy → onglet Environment)

Voir `.env.example` (SMTP primaire Gmail, boîte OVH `contact@theo-birost.fr` en secours ; exemple OVH ci-dessous) :

| Variable | Exemple |
|---|---|
| `SMTP_HOST` | `pro2.mail.ovh.net` |
| `SMTP_USERNAME` | `contact@theo-birost.fr` |
| `SMTP_PASSWORD` | *(le mot de passe de la boîte)* |
| `SMTP_PORT` / `SMTP_SECURE` | `587` / `tls` |
| `CONTACT_FORM_SECRET` | une longue chaîne aléatoire (`openssl rand -hex 32`) |
| `CONTENT_BACKEND` | `local` (contenu sur le volume du VPS) ; `bunny` = ancien stockage |
| `DATA_DIR` | `/var/lib/birostweb/data` (déjà défini dans l'image) |
| `ADMIN_USER` / `ADMIN_PASSWORD_HASH` | identifiant et hash `password_hash` du back-office |
| `ADMIN_TOTP_SECRET` | *(optionnel)* secret base32 : active la 2FA de `/admin` |

Sans ces variables, la page s'affiche mais l'envoi du formulaire échoue.

## Tester en local

```bash
docker build -t birostweb .
docker run --rm -p 8080:8080 -e CONTACT_FORM_SECRET=dev birostweb
# → http://localhost:8080  (l'envoi SMTP nécessite les variables ci-dessus)
```

## Déploiement sur Dokploy

1. Pousser ce dossier sur Git.
2. Dokploy → l'app **birostweb** : Build Type = **Dockerfile**.
3. **Container Port = 8080** (déjà en place).
4. Onglet **Environment** : renseigner les variables SMTP + `CONTACT_FORM_SECRET`.
5. Onglet **Domains** : `birostweb.fr` **et** `www.birostweb.fr` (port 8080, HTTPS Let's Encrypt).
6. **Deploy**.

## Contenu du site (offres, promos, bandeau)

Le contenu modifié dans `/admin` est un fichier JSON stocké **sur le VPS**, hors de la racine web :
`/var/lib/birostweb/data/content.json` (dossier `0700`, propriétaire `www-data`, jamais servi par Apache).
Chaque enregistrement garde la version précédente dans `data/backups/` (30 dernières).

**Volume obligatoire (Dokploy → Advanced → Volumes) :** type *Volume*, nom `birostweb-data`,
chemin `/var/lib/birostweb`. Sans volume, le contenu est perdu à chaque redéploiement : l'admin
l'affiche en rouge. Un volume nommé reçoit automatiquement les droits `www-data` de l'image ; pour un
montage de dossier (*bind*), faire `chown -R 33:33` sur le dossier de l'hôte.

### Migration depuis Bunny Storage (une fois)

1. Déployer cette version **sans rien changer** (`CONTENT_BACKEND=bunny`, clés Bunny en place) après avoir
   ajouté le volume. Le site continue de lire Bunny.
2. Copier le contenu : `docker exec <conteneur> php /opt/birostweb/content-migrate.php pull`
   puis `... content-migrate.php status` → « Comparaison : IDENTIQUES ».
3. Passer `CONTENT_BACKEND=local` (garder les clés Bunny Storage) et redéployer. Vérifier le site et
   `/admin` (modifier puis remettre une promo), puis `status` : le local est servi.
4. Recréer le conteneur (redéployer) et vérifier que la modification est toujours là.
5. Après quelques jours sans problème : retirer les variables `BUNNY_*` et redéployer.

Sécurité : si le fichier local manque, il est réimporté depuis Bunny tant que les clés existent ;
si Bunny est injoignable, le site affiche le contenu par défaut **sans l'enregistrer** et l'admin
refuse d'enregistrer (le vrai contenu n'est jamais écrasé).

**Retour arrière :** remettre `CONTENT_BACKEND=bunny` et redéployer. Pour garder les modifications
faites depuis la migration, lancer d'abord `content-migrate.php push --force` (sauvegarde la version
Bunny puis la remplace par le contenu local).

**Sauvegarde hors VPS :** `docker cp <conteneur>:/var/lib/birostweb/data ./sauvegarde-birostweb`
(à faire régulièrement, en plus de la sauvegarde OVH du VPS).

DNS OVH : `A`/`AAAA` de `@` et `www` → VPS `152.228.130.105` / `2001:41d0:404:200::4425`.
