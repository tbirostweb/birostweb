#!/bin/sh
# Test bout-en-bout local : faux serveur SMTP (python3) + php -S. Aucun envoi réel. Usage : sh tests/e2e_send_test.sh
ROOT=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); PID=""; SP=""; trap 'kill $PID $SP 2>/dev/null; [ -n "$KEEP" ] || rm -rf "$T"' EXIT
[ -d "$ROOT/vendor" ] || (cd "$ROOT" && composer install --no-dev --no-scripts -q)
cp -R "$ROOT/site" "$T/www"; cp -R "$ROOT/vendor" "$T/www/vendor"
cat > "$T/smtp.py" <<'PY'
import socketserver,sys
class H(socketserver.StreamRequestHandler):
    def w(self,s): self.wfile.write((s+"\r\n").encode())
    def handle(self):
        self.w("220 fake"); data=False
        for raw in self.rfile:
            l=raw.decode(errors="ignore").rstrip("\r\n")
            if data:
                if l==".": data=False; open(sys.argv[2],"a").write("MAIL\n"); self.w("250 ok")
                continue
            u=l.upper()
            if u.startswith("EHLO") or u.startswith("HELO"): self.w("250-fake"); self.w("250 AUTH PLAIN")
            elif u=="AUTH PLAIN": self.w("334 ")
            elif u.startswith("AUTH"): self.w("235 ok")
            elif u=="DATA": data=True; self.w("354 go")
            elif u=="QUIT": self.w("221 bye"); return
            elif l and l[0].isalnum() and len(l)>3 and not u.startswith(("MAIL","RCPT","RSET","NOOP")): self.w("235 ok")
            else: self.w("250 ok")
socketserver.TCPServer.allow_reuse_address=True
socketserver.ThreadingTCPServer(("127.0.0.1",int(sys.argv[1])),H).serve_forever()
PY
python3 "$T/smtp.py" 12525 "$T/mails" & SP=$!
S=0123456789abcdef0123456789abcdef0123456789
export CONTACT_FORM_SECRET=$S CONTACT_STATE_DIR=$T/st CONTACT_LOG_DIR=$T/lg SMTP_HOST=127.0.0.1 SMTP_USERNAME=u@x.test SMTP_PASSWORD=p SMTP_PORT=12525 SMTP_SECURE=none CONTACT_FORM_DAILY_CAP=3
php -S 127.0.0.1:18098 -t "$T/www" >/dev/null 2>&1 & PID=$!; sleep 1
U=http://127.0.0.1:18098
payload() { curl -s $U/altcha.php | php -r '$d=json_decode(stream_get_contents(STDIN),true);for($n=0;$n<=$d["maxnumber"];$n++)if(hash("sha256",$d["salt"].$n)===$d["challenge"])break;$d["number"]=$n;echo base64_encode(json_encode($d));'; }
TS=$(( $(date +%s) - 10 )); TOK=$(php -r 'echo hash_hmac("sha256",$argv[1],$argv[2]);' $TS $S)
send() { curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "ts=$TS" --data-urlencode "token=$TOK" --data-urlencode "altcha=$1" --data-urlencode "name=Test" --data-urlencode "email=visiteur@example.com" --data-urlencode "message=$2" $U/send_mail.php; }
FAIL=0; echo "T=$T"; chk() { if [ "$2" = "$3" ]; then echo "PASS $1"; else echo "FAIL $1 ($2 != $3)"; FAIL=1; fi; }
P=$(payload)
chk "message trop court => 400 sans consommer le captcha" "$(send "$P" court)" 400
chk "envoi nominal => 200" "$(send "$P" 'Message de test assez long')" 200
chk "rejeu du même captcha => 400" "$(send "$P" 'Message de test assez long')" 400
sleep 1
chk "2 emails (notification + accusé)" "$(wc -l < "$T/mails" | tr -d ' ')" 2
exit $FAIL
