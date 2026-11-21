#!/usr/bin/env python3
"""Real local WordPress, with SQLite drop-in and an explicitly test-only HTTP adapter.
Pass downloaded WordPress and SQLite Database Integration ZIPs. Nothing is sent to example.com.
"""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,zipfile,argparse
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
p=argparse.ArgumentParser();p.add_argument('--wordpress-zip',required=True);p.add_argument('--sqlite-zip',required=True);args=p.parse_args()
def unzip(src,dest):
 with zipfile.ZipFile(src) as z:
  for name in z.namelist():
   if not (dest/name).resolve().is_relative_to(dest.resolve()):raise ValueError('Unsafe ZIP entry')
  z.extractall(dest)
with tempfile.TemporaryDirectory(prefix='loyalty-wp-') as temp:
 S=pathlib.Path(temp);A=S/'app';A.mkdir();unzip(args.wordpress_zip,S);W=S/'wordpress';unzip(args.sqlite_zip,S)
 for name in ['app','public']:shutil.copytree(R/name,A/name)
 for name in ['seed.php','config.example.php']:shutil.copy(R/name,A/name)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 # Bootstrap additive schema and generated demo API keys before configuring WP.
 subprocess.run([PHP,'-r',"require 'public/index.php';"],cwd=A,check=True)
 shutil.copytree(S/'sqlite-database-integration',W/'wp-content/plugins/sqlite-database-integration')
 shutil.copy(S/'sqlite-database-integration/db.copy',W/'wp-content/db.php')
 shutil.copy(R/'tests/wordpress-fixture/wp-config.php',W/'wp-config.php')
 (W/'wp-content/mu-plugins').mkdir();shutil.copy(R/'tests/wordpress-fixture/local-tests.php',W/'wp-content/mu-plugins/local-tests.php')
 shutil.copytree(R/'integrations/woocommerce',W/'wp-content/plugins/loyalty-saas-woocommerce')
 env=dict(os.environ,WORDPRESS_TEST_ROOT=str(W),WORDPRESS_TEST_STATE=str(S))
 install="define('WP_INSTALLING',true);require getenv('WORDPRESS_TEST_ROOT').'/wp-load.php';require_once ABSPATH.'wp-admin/includes/upgrade.php';wp_install('Loyalty Händler-Test','testadmin','admin@example.test',false,'','local-test-only');"
 subprocess.run([PHP,'-r',install],env=env,check=True)
 subprocess.run([PHP,str(R/'tests/wordpress-fixture/configure.php')],env=env,check=True)
 subprocess.run([PHP,str(R/'tests/wordpress.php')],env=env,check=True)
 procs=[];logs=[]
 try:
  for root,port,serverargs in [(A,8099,['-t','public','public/index.php']),(W,8100,['-t','.'])]:
   log=open(S/('server-'+str(port)+'.log'),'w');logs.append(log);procs.append(subprocess.Popen([PHP,'-S','127.0.0.1:'+str(port)]+serverargs,cwd=root,stdout=log,stderr=log,env=env))
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');urllib.request.urlopen('http://127.0.0.1:8100/wp-login.php');break
   except OSError:time.sleep(.1)
  subprocess.run(['python',str(R/'tests/merchant-http.py'),str(A),PHP],cwd=R,env=env,check=True)
  subprocess.run(['node',str(R/'tests/wordpress-ui.cjs')],cwd=R,env=env,check=True)
 finally:
  for server in procs:server.terminate();server.wait()
  for log in logs:log.close()
