#!/usr/bin/env python3
"""ssh-keyscan compatibility wrapper pinned to the reviewed S01 host identity."""
import base64,hashlib,subprocess,sys
HOST='103.69.196.155'
PORT='9898'
FINGERPRINTS={'ssh-ed25519':'rk9r2a/HWWwq7sdjuM/aKJ+psJ7PAM4/8mwXCAqHkCs','ecdsa-sha2-nistp256':'cytcPJf4wXpI9QslcP0cgJxSuyiC7ERi9pYCopp2UfI','ssh-rsa':'kPMLd1yNsDr0HWv74DXtN72+yAR5BQJ/hUg2Lvl4YgY'}
def arguments(args):
 out=[];hosts=[];port=None;i=0
 while i<len(args):
  arg=args[i]
  if arg in ('-p','-T','-t'):
   if i+1>=len(args):raise ValueError('missing_scan_option')
   val=args[i+1];out.extend([arg,val]);i+=2
   if arg=='-p':
    if port is not None or val!=PORT:raise ValueError('unexpected_s01_port')
    port=val
  elif arg in ('-4','-6','-H','-v'):out.append(arg);i+=1
  elif arg.startswith('-'):raise ValueError('unsupported_scan_option')
  else:hosts.append(arg);out.append(arg);i+=1
 if hosts!=[HOST]:raise ValueError('unexpected_s01_host')
 if port is None:out=['-p',PORT]+out
 return out

def validate(data):
 rows=[]
 for line in data.splitlines():
  if not line.strip() or line.startswith('#'):continue
  fields=line.split()
  if len(fields)!=3 or fields[1] not in FINGERPRINTS:raise ValueError('unexpected_host_key')
  digest=base64.b64encode(hashlib.sha256(base64.b64decode(fields[2],validate=True)).digest()).decode().rstrip('=')
  if digest!=FINGERPRINTS[fields[1]]:raise ValueError('s01_host_key_mismatch')
  rows.append(line)
 if not rows:raise ValueError('s01_host_key_missing')
 return '\n'.join(rows)+'\n'

def main():
 try:
  args=arguments(sys.argv[1:]);p=subprocess.run(['/usr/bin/ssh-keyscan',*args],capture_output=True,text=True,timeout=45)
  if p.returncode:raise ValueError('s01_host_scan_failed')
  sys.stdout.write(validate(p.stdout))
 except (ValueError,subprocess.TimeoutExpired,OSError):
  print('::error::S01 host verification failed; deployment must stop.',file=sys.stderr);return 1
 return 0
if __name__=='__main__':raise SystemExit(main())
