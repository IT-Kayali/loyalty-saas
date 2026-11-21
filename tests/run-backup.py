#!/usr/bin/env python3
"""Backup/restore and hostile archive checks, entirely in a temporary install."""
import tempfile,pathlib,shutil,subprocess,os,zipfile
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='loyalty-backup-') as d:
 a=pathlib.Path(d)/'app';a.mkdir()
 for n in ['app','public','tools']:shutil.copytree(root/n,a/n)
 for n in ['seed.php','config.example.php']:shutil.copy(root/n,a/n)
 subprocess.run([php,'seed.php'],cwd=a,check=True,capture_output=True)
 b=str(pathlib.Path(d)/'backup.zip');subprocess.run([php,'tools/backup.php','backup',b],cwd=a,check=True)
 assert os.stat(b).st_mode & 0o777==0o600
 subprocess.run([php,'-r',"require 'app/bootstrap.php';db()->exec(\"UPDATE users SET name='Changed'\");"],cwd=a,check=True)
 subprocess.run([php,'tools/backup.php','restore',b,'--yes'],cwd=a,check=True)
 name=subprocess.check_output([php,'-r',"require 'app/bootstrap.php';echo db()->query('SELECT name FROM users LIMIT 1')->fetchColumn();"],cwd=a,text=True);assert name=='Ghaith'
 bad=str(pathlib.Path(d)/'bad.zip');shutil.copy(b,bad)
 with zipfile.ZipFile(bad,'a') as z:z.writestr('../escape.php','invalid')
 x=subprocess.run([php,'tools/backup.php','restore',bad,'--yes'],cwd=a,capture_output=True,text=True)
 assert x.returncode!=0 and 'Unsafe backup member' in x.stderr
 print('PASS backup/restore round trip, private file permissions, traversal rejection')
