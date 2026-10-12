import { useCallback, useEffect, useState } from 'react';
import type { CompanyRegistryClient, Workspace } from './companyRegistryClient.ts';
import { CompanyRegistryError } from './companyRegistryClient.ts';
import { injectCompanyAccessStyles } from './styles.ts';

export interface MyWorkspacesPanelProps {
  client: CompanyRegistryClient;
  /** Called after joining or leaving, so the host can reload its company list. */
  onChanged?: () => void;
}

/**
 * The workspaces the person owns or belongs to, and the invitations waiting for them. Accepting
 * is what lets them discover a workspace's companies; leaving stops it (company shares they hold
 * directly are not affected).
 */
export function MyWorkspacesPanel({ client, onChanged }: MyWorkspacesPanelProps) {
  injectCompanyAccessStyles();
  const [rows, setRows] = useState<Workspace[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);

  const load = useCallback(() => {
    client.workspaces().then(setRows).catch((e: unknown) => setError(e instanceof CompanyRegistryError ? e.message : 'Workspaces could not be loaded.'));
  }, [client]);

  useEffect(load, [load]);

  const act = async (ws: Workspace, action: 'accept' | 'decline' | 'exit') => {
    if (action === 'exit' && !window.confirm(`Leave ${ws.workspace_name}? You will stop seeing its companies, except those shared with you directly.`)) return;
    setBusy(ws.workspace_id);
    setError(null);
    try {
      await client.respondToWorkspace(ws.workspace_id, action);
      load();
      onChanged?.();
    } catch (e) {
      setError(e instanceof CompanyRegistryError ? e.message : 'That did not work. Please try again.');
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="aic-ca aic-ca-card aic-ca-stack">
      <div>
        <h2 className="aic-ca-title">My workspaces</h2>
        <p className="aic-ca-sub">Workspaces let a company owner's trusted team see every company they registered, so nobody registers one twice.</p>
      </div>
      {error ? <div className="aic-ca-alert aic-ca-tone-danger" role="alert">{error}</div> : null}
      {rows === null ? (
        <div className="aic-ca-empty">Loading…</div>
      ) : rows.length === 0 ? (
        <div className="aic-ca-empty">You do not belong to any workspace yet.</div>
      ) : (
        <ul className="aic-ca-list">
          {rows.map((w) => (
            <li key={w.workspace_id} className="aic-ca-item" style={{ cursor: 'default' }}>
              <span>
                <span className="aic-ca-name">{w.workspace_name}</span>
                <span className="aic-ca-meta">
                  {' '}· {w.is_owner ? 'Owner' : w.membership_status === 'invited' ? 'Invitation' : 'Member'}
                  {w.company_count !== null ? ` · ${w.company_count} compan${w.company_count === 1 ? 'y' : 'ies'}` : ''}
                </span>
              </span>
              {w.is_owner ? null : w.membership_status === 'invited' ? (
                <span className="aic-ca-row">
                  <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary" disabled={busy === w.workspace_id} onClick={() => void act(w, 'accept')}>Accept</button>
                  <button type="button" className="aic-ca-btn aic-ca-btn-sm" disabled={busy === w.workspace_id} onClick={() => void act(w, 'decline')}>Decline</button>
                </span>
              ) : (
                <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-danger" disabled={busy === w.workspace_id} onClick={() => void act(w, 'exit')}>Leave</button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
