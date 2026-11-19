#!/usr/bin/env python3
"""Fail before deployment when transport secrets target another server or site."""
import json,os,pathlib,sys

def validate(values,targets):
 hosts=[];roots=[];users=[];ports=[]
 expected_users={t['ssh_login'] for t in targets}
 if len(expected_users)!=1:raise ValueError('ambiguous_login')
 allowed=set()
 for target in targets:
  root=target['root'];home='/home/'+target['account'];allowed.add(root.rstrip('/'))
  if not target.get('absolute_root_only',False) and root.startswith(home+'/'):allowed.add(root[len(home)+1:].rstrip('/'))
 for name,value in values.items():
  if not value:raise ValueError('missing_transport_setting')
  if name.endswith('_HOST'):
   if value!='103.69.196.155':raise ValueError('wrong_host')
   hosts.append(value)
  elif name.endswith('_PORT'):
   if value!='9898':raise ValueError('wrong_port')
   ports.append(value)
  elif name.endswith('_USER'):
   if value not in expected_users:raise ValueError('wrong_user')
   users.append(value)
  elif any(x in name for x in ('REMOTE_ROOT','REMOTE_PATH','REMOTE_DIR')) or name.endswith('SITE_ROOT'):
   scoped=allowed
   for portal in ['erp1','erp3']:
    if name.endswith('_'+portal.upper()):
     target=next(t for t in targets if t.get('portal')==portal);root=target['root'];home='/home/'+target['account']+'/'
     scoped={root,root[len(home):]}
   if value.rstrip('/') not in scoped:raise ValueError('wrong_root')
   roots.append(value)
 if not hosts or not roots or not users or not ports:raise ValueError('missing_target')
 return True

def main():
 try:
  if os.environ.get('GITHUB_EVENT_NAME')!='workflow_dispatch':raise ValueError('manual_deployment_required')
  targets=json.loads(os.environ['AIC_S01_TARGETS'])
  values={k[11:]:v for k,v in os.environ.items() if k.startswith('AIC_TARGET_')}
  validate(values,targets)
  print('S01 deployment target validated; execution remains manual.')
 except (KeyError,ValueError,TypeError):
  print('::error::S01 deployment target is missing or outside this workflow scope. Update its SSH host, port, user and exact root secrets.',file=sys.stderr);return 1
 return 0
if __name__=='__main__':raise SystemExit(main())
