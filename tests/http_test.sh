#!/bin/sh
# Tests HTTP de send_mail.php/altcha.php (serveur PHP intégré, aucun SMTP). Usage : sh tests/http_test.sh
PID=""
ROOT=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); trap 'kill $PID 2>/dev/null; rm -rf "$T"' EXIT
[ -d "$ROOT/vendor" ] || (cd "$ROOT" && composer install --no-dev --no-scripts -q)
cp -R "$ROOT/site" "$T/www"; cp -R "$ROOT/vendor" "$T/www/vendor"
PORT=18099; FAIL=0
run() { # $1 = env prefix
  kill $PID 2>/dev/null; sleep 0.3
  env $1 php -S 127.0.0.1:$PORT -t "$T/www" >/dev/null 2>&1 & PID=$!; sleep 0.8
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
chk() { if [ "$2" = "$3" ]; then echo "PASS $1"; else echo "FAIL $1 (attendu $3, obtenu $2)"; FAIL=1; fi; }
S=0123456789abcdef0123456789abcdef0123456789
U=http://127.0.0.1:$PORT
run "CONTACT_FORM_SECRET=$S CONTACT_STATE_DIR=$T/st CONTACT_LOG_DIR=$T/lg"
chk "altcha 200 avec secret valide" "$(code $U/altcha.php)" 200
chk "POST name[] => 400" "$(code -X POST -d 'name[]=x' $U/send_mail.php)" 400
chk "GET send_mail => 403" "$(code $U/send_mail.php)" 403
chk "corps invalide (token absent) => 400" "$(code -X POST -d 'name=a&email=a@b.fr&message=bonjourbonjour' $U/send_mail.php)" 400
run "CONTACT_FORM_SECRET= CONTACT_STATE_DIR=$T/st CONTACT_LOG_DIR=$T/lg"
chk "altcha sans secret => 503" "$(code $U/altcha.php)" 503
chk "send_mail sans secret => 503" "$(code -X POST -d 'a=b' $U/send_mail.php)" 503
run "CONTACT_FORM_SECRET=court CONTACT_STATE_DIR=$T/st CONTACT_LOG_DIR=$T/lg"
chk "secret < 32 car => 503" "$(code -X POST -d 'a=b' $U/send_mail.php)" 503
run "CONTACT_FORM_SECRET=$S CONTACT_STATE_DIR=/dev/null/x CONTACT_LOG_DIR=$T/lg"
chk "stockage absent => 503 (zéro envoi)" "$(code -X POST -d 'a=b' $U/send_mail.php)" 503
grep -q 'token=' "$T"/lg/* 2>/dev/null && { echo "FAIL query dans les logs"; FAIL=1; } || echo "PASS pas de query dans les logs"
exit $FAIL
