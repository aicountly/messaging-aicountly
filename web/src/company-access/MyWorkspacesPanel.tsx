import { useCallback, useEffect, useState } from 'react';
import type { CompanyRegistryClient, DirectoryCompany, Workspace } from './companyRegistryClient.ts';
import { CompanyRegistryError } from './companyRegistryClient.ts';
import { CompanyAccessBadge } from './CompanyAccessBadge.tsx';
import { WorkspaceManager } from './WorkspaceManager.tsx';
import { isOpenable } from './accessStatus.ts';
import { useCompanyDirectory } from './useCompanyDirectory.ts';
import { workspaceSettingsSummary } from './WorkspaceCreation.tsx';
import { injectCompanyAccessStyles } from './styles.ts';

export interface MyWorkspacesPanelProps {
  client: CompanyRegistryClient;
  /** Called after joining, leaving or changing the workspace, so the host can reload its company list. */
  onChanged?: () => void;
  /**
   * Opens a company listed under a workspace. Called only for a company the person may open in
   * this application; omit it and the companies are listed without a link.
   */
  onOpenCompany?: (company: DirectoryCompany) => void;
}

/** The name to show — the server names an unnamed workspace after its owner; this only covers older servers. */
export function workspaceDisplayName(w: Pick<Workspace, 'workspace_id' | 'workspace_name' | 'owner_name'>): string {
  const name = (w.workspace_name ?? '').trim();
  if (name && !/^Workspace #\d+$/.test(name)) return name;
  const owner = (w.owner_name ?? '').trim();
  return owner ? `${owner}'s workspace` : name || `Workspace #${w.workspace_id}`;
}

function ownerLabel(w: Workspace): string | null {
  const owner = (w.owner_name ?? '').trim() || (w.owner_email ?? '').trim();
  return owner ? `Owned by ${owner}` : null;
}

/**
 * The workspaces the person owns or belongs to, and the invitations waiting for them. Each row
 * opens: the person's own workspace (one per owner) shows who is in it and lets them rename it and
 * invite or remove people; a workspace they belong to lists its companies with their access, and
 * lets them leave. Accepting is what lets them discover a workspace's companies; leaving stops it
 * (company shares they hold directly are not affected).
 */
export function MyWorkspacesPanel({ client, onChanged, onOpenCompany }: MyWorkspacesPanelProps) {
  injectCompanyAccessStyles();
  const [rows, setRows] = useState<Workspace[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const [open, setOpen] = useState<number | null>(null);

  const load = useCallback(() => {
    client.workspaces().then((r) => setRows(Array.isArray(r) ? r : [])).catch((e: unknown) => setError(e instanceof CompanyRegistryError ? e.message : 'Workspaces could not be loaded.'));
  }, [client]);

  useEffect(load, [load]);

  const act = async (ws: Workspace, action: 'accept' | 'decline' | 'exit') => {
    if (action === 'exit' && !window.confirm(`Leave ${workspaceDisplayName(ws)}? You will stop seeing its companies, except those shared with you directly.`)) return;
    setBusy(ws.workspace_id);
    setError(null);
    try {
      await client.respondToWorkspace(ws.workspace_id, action);
      if (action !== 'accept') setOpen(null);
      load();
      onChanged?.();
    } catch (e) {
      setError(e instanceof CompanyRegistryError ? e.message : 'That did not work. Please try again.');
    } finally {
      setBusy(null);
    }
  };

  const ownsOne = rows?.some((w) => w.is_owner) === true;

  return (
    <div className="aic-ca aic-ca-card aic-ca-stack">
      <div>
        <h2 className="aic-ca-title">My workspaces</h2>
        {ownsOne ? <p className="aic-ca-sub">You have one workspace. Everyone you invite (or share a company with) joins it.</p> : null}
        <p className="aic-ca-sub">A workspace lets a company owner's trusted team see every company they registered, so nobody registers one twice. Open a workspace to see more.</p>
      </div>
      {error ? <div className="aic-ca-alert aic-ca-tone-danger" role="alert">{error}</div> : null}
      {rows === null ? (
        <div className="aic-ca-empty">Loading…</div>
      ) : rows.length === 0 ? (
        <div className="aic-ca-empty">You do not belong to any workspace yet.</div>
      ) : (
        <ul className="aic-ca-list">
          {rows.map((w) => {
            const expanded = open === w.workspace_id;
            const bodyId = `aic-ca-ws-${w.workspace_id}`;
            const invited = !w.is_owner && w.membership_status === 'invited';
            const owner = w.is_owner ? null : ownerLabel(w);
            return (
              <li key={w.workspace_id} className="aic-ca-ws">
                <div className="aic-ca-ws-head">
                  <button
                    type="button"
                    className="aic-ca-ws-toggle"
                    aria-expanded={expanded}
                    aria-controls={bodyId}
                    onClick={() => setOpen(expanded ? null : w.workspace_id)}
                  >
                    <span className="aic-ca-ws-chevron" aria-hidden="true">›</span>
                    <span style={{ minWidth: 0 }}>
                      <span className="aic-ca-name" style={{ display: 'block' }}>{workspaceDisplayName(w)}</span>
                      <span className="aic-ca-meta">
                        {w.is_owner ? 'Your workspace' : invited ? 'Invitation' : 'Member'}
                        {owner ? ` · ${owner}` : ''}
                        {w.company_count != null ? ` · ${w.company_count} compan${w.company_count === 1 ? 'y' : 'ies'}` : ''}
                        {w.is_owner && w.member_count != null ? ` · ${w.member_count} ${w.member_count === 1 ? 'person' : 'people'}` : ''}
                      </span>
                      {!w.is_owner && w.my_settings ? (
                        <span className="aic-ca-meta" style={{ display: 'block' }}>You {workspaceSettingsSummary(w.my_settings)}</span>
                      ) : null}
                    </span>
                  </button>
                  {invited ? (
                    <span className="aic-ca-row">
                      <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary" disabled={busy === w.workspace_id} onClick={() => void act(w, 'accept')}>Accept</button>
                      <button type="button" className="aic-ca-btn aic-ca-btn-sm" disabled={busy === w.workspace_id} onClick={() => void act(w, 'decline')}>Decline</button>
                    </span>
                  ) : null}
                </div>
                {expanded ? (
                  <div className="aic-ca-ws-body" id={bodyId}>
                    {w.is_owner ? (
                      <WorkspaceManager
                        client={client}
                        workspace={w}
                        onChanged={() => {
                          load();
                          onChanged?.();
                        }}
                      />
                    ) : invited ? (
                      <div className="aic-ca-empty">
                        {owner ?? 'The owner'} invited you. Accept to see the companies in this workspace — seeing a company is not opening it; that still needs the owner's grant.
                      </div>
                    ) : (
                      <WorkspaceCompanies
                        client={client}
                        workspace={w}
                        onOpenCompany={onOpenCompany}
                        leaving={busy === w.workspace_id}
                        onLeave={() => void act(w, 'exit')}
                      />
                    )}
                  </div>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function WorkspaceCompanies({ client, workspace, onOpenCompany, leaving, onLeave }: {
  client: CompanyRegistryClient;
  workspace: Workspace;
  onOpenCompany?: (company: DirectoryCompany) => void;
  leaving: boolean;
  onLeave: () => void;
}) {
  const dir = useCompanyDirectory(client, { workspace_id: workspace.workspace_id }, 0);
  return (
    <div className="aic-ca-stack">
      <p className="aic-ca-sub">
        Companies in this workspace, with your access to each. Seeing a company is not opening it — ask its owner for access to the ones marked restricted.
      </p>
      {dir.error ? (
        <div className="aic-ca-alert aic-ca-tone-danger aic-ca-between" role="alert">
          <span>{dir.error.message}</span>
          <button type="button" className="aic-ca-btn aic-ca-btn-sm" onClick={dir.reload}>Retry</button>
        </div>
      ) : null}
      {dir.companies.length === 0 && !dir.error ? (
        <div className="aic-ca-empty">{dir.loading || dir.meta === null ? 'Loading companies…' : 'No companies are visible to you in this workspace.'}</div>
      ) : dir.companies.length > 0 ? (
        <ul className="aic-ca-list" aria-label={`Companies in ${workspaceDisplayName(workspace)}`} aria-busy={dir.loading}>
          {dir.companies.map((c) => {
            const canOpen = onOpenCompany !== undefined && isOpenable(c);
            const inner = (
              <>
                <span style={{ minWidth: 0 }}>
                  <span className="aic-ca-name" style={{ display: 'block' }}>{c.legal_name}</span>
                  <span className="aic-ca-meta">#{c.cmp_id}</span>
                </span>
                <CompanyAccessBadge status={c.access_status} />
              </>
            );
            return (
              <li key={c.cmp_id}>
                {canOpen ? (
                  <button type="button" className="aic-ca-item" title={`Open ${c.legal_name}`} onClick={() => onOpenCompany?.(c)}>{inner}</button>
                ) : (
                  <div className={`aic-ca-item${isOpenable(c) ? '' : ' aic-ca-item-restricted'}`} style={{ cursor: 'default' }}>{inner}</div>
                )}
              </li>
            );
          })}
        </ul>
      ) : null}
      <div className="aic-ca-between">
        <span className="aic-ca-meta">{dir.meta ? `${dir.meta.total} compan${dir.meta.total === 1 ? 'y' : 'ies'}` : ''}</span>
        <span className="aic-ca-row">
          {dir.hasMore ? (
            <button type="button" className="aic-ca-btn aic-ca-btn-sm" onClick={dir.loadMore} disabled={dir.loading}>Load more</button>
          ) : null}
          <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-danger" disabled={leaving} onClick={onLeave}>Leave workspace</button>
        </span>
      </div>
    </div>
  );
}
