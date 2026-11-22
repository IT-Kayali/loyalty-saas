#!/usr/bin/env python3
"""Öffentlicher Beitritt ersetzt keinen Nachweis einer bestehenden Wallet-Identität."""
import http.cookiejar
import os
import pathlib
import re
import shutil
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

R = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
n = 0


def check(value, label):
    global n
    assert value, label
    n += 1
    print('PASS', label, flush=True)


with tempfile.TemporaryDirectory(prefix='loyalty-member-join-identity-') as temp:
    app = pathlib.Path(temp)
    for name in ['app', 'public']:
        shutil.copytree(R / name, app / name)
    for name in ['seed.php', 'config.example.php']:
        shutil.copy(R / name, app / name)
    subprocess.run([PHP, 'seed.php'], cwd=app, check=True, capture_output=True)

    def sql(code):
        return subprocess.check_output([PHP, '-r', "require 'public/index.php';" + code], cwd=app, text=True).strip()

    sql("db()->exec(\"UPDATE members SET name='PRIVATE-EXISTING-MEMBER' WHERE email='aylin@example.com'\");")
    join_code = sql("echo db()->query('SELECT join_code FROM businesses WHERE id=1')->fetchColumn();")
    join_path = '/join/' + join_code

    def snapshot():
        return sql("echo hash('sha256',json_encode([db()->query('SELECT * FROM members ORDER BY id')->fetchAll(),db()->query('SELECT * FROM cards ORDER BY id')->fetchAll()]));")

    def client():
        return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(c, path, data=None):
        if data is not None:
            data = urllib.parse.urlencode(data).encode()
        try:
            with c.open(urllib.request.Request('http://127.0.0.1:8099' + path, data=data), timeout=15) as response:
                return response.status, response.read().decode(), urllib.parse.urlsplit(response.url).path
        except urllib.error.HTTPError as response:
            return response.code, response.read().decode(), urllib.parse.urlsplit(response.url).path

    def join(c, email, name='NEW-SELF-REGISTERED'):
        _, body, _ = request(c, join_path)
        token = re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)
        return request(c, join_path, {'csrf': token, 'name': name, 'email': email, 'password': 'local-join-test-only'})

    with (app / 'server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-S', '127.0.0.1:8099', '-t', 'public', 'public/index.php'], cwd=app, stdout=log, stderr=log)
        try:
            for _ in range(50):
                try:
                    request(client(), '/login')
                    break
                except OSError:
                    time.sleep(.1)
            before = snapshot()
            for email in ['aylin@example.com', '  AYLIN@example.com  ']:
                guest = client()
                status, body, path = join(guest, email)
                check(status == 200 and path == join_path, 'Bestehende E-Mail bleibt beim Beitritt ohne Wallet-Anmeldung')
                check('Beitritt nicht möglich.' in body, 'Verständlicher Hinweis statt stiller Verknüpfung')
                check('PRIVATE-EXISTING-MEMBER' not in body, 'Bestehender künstlicher Mitgliedsname wird nicht offengelegt')
                check(snapshot() == before, 'Bestehende Mitgliedschaften und Karten unverändert, keine zusätzliche Login-Zeile')
                _, wallet_body, wallet_path = request(guest, '/wallet')
                check(wallet_path == '/member/login', 'Abgelehnter Beitritt erzeugt keine Mitgliedssitzung')
                check('PRIVATE-EXISTING-MEMBER' not in wallet_body, 'Auch nachfolgender Wallet-Aufruf legt den bestehenden Namen nicht offen')
            fresh = client()
            status, body, path = join(fresh, 'fresh-join@example.invalid', 'FRESH-JOIN-MEMBER')
            check(status == 200 and path == '/wallet', 'Neue E-Mail kann unverändert beitreten und die eigene Wallet öffnen')
            check('FRESH-JOIN-MEMBER' in body and 'PRIVATE-EXISTING-MEMBER' not in body, 'Neue Wallet enthält nur den neuen statt des bestehenden Test-Mitgliedsnamens')
            check(sql("echo db()->query(\"SELECT count(*) FROM members WHERE email='fresh-join@example.invalid'\")->fetchColumn();") == '1', 'Genau eine neue Mitgliedschaft angelegt')
            guest = client()
            status, body, path = join(guest, '', 'NO-EMAIL-MEMBER')
            check(status == 200 and path.startswith('/c/'), 'Beitritt ohne E-Mail führt weiterhin nur zur neuen einzelnen Kundenkarte')
            check('PRIVATE-EXISTING-MEMBER' not in body, 'Einzelne neue Kundenkarte enthält keinen bestehenden Test-Mitgliedsnamen')
            check(request(guest, '/wallet')[2] == '/member/login', 'E-Mail-loser Beitritt erzeugt weiterhin keine Wallet-Sitzung')
            subprocess.run(['node', str(R / 'tests/member-join-identity-ui.cjs')], cwd=R, env=dict(os.environ, JOIN_TEST_PATH=join_path), check=True)
        finally:
            server.terminate()
            server.wait(timeout=10)
    print(n, 'Beitritts-Identitätsprüfungen bestanden')
