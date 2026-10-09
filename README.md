# birostweb.fr

Site vitrine de **Birostweb** (Théo Birost, développeur web full-stack).
Application **PHP + Apache** conteneurisée, déployée sur **Dokploy**, avec un
formulaire de contact qui envoie par **SMTP** (comme le portfolio).

## Structure

```
birostweb/
├── Dockerfile            # image de prod (php:8.2-apache, port 8080)
├── entrypoint.sh         # port dynamique Apache ($PORT, défaut 8080)
├── composer.json/.lock   # dépendances PHP (PHPMailer, phpdotenv)
├── .env.example          # variables à définir dans Dokploy (NE PAS committer de vraies valeurs)
├── .dockerignore
└── site/                 # racine web (DocumentRoot)
    ├── index.php         # page (génère le jeton anti-spam du formulaire)
    ├── send_mail.php     # endpoint du formulaire (validation + SMTP)
    ├── .htaccess         # en-têtes de sécurité + CSP + blocage fichiers sensibles
    ├── favicon.svg       # logo BW
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

Les **mêmes** que ton portfolio (boîte OVH `contact@theo-birost.fr`). Voir `.env.example` :

| Variable | Exemple |
|---|---|
| `SMTP_HOST` | `ssl0.ovh.net` |
| `SMTP_USERNAME` | `contact@theo-birost.fr` |
| `SMTP_PASSWORD` | *(le mot de passe de la boîte)* |
| `SMTP_PORT` | `465` |
| `SMTP_SECURE` | `ssl` |
| `CONTACT_FORM_SECRET` | une longue chaîne aléatoire (`openssl rand -hex 32`) |

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

DNS OVH : `A`/`AAAA` de `@` et `www` → VPS `152.228.130.105` / `2001:41d0:404:200::4425`.
