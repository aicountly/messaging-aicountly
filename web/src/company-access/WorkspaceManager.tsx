import { useCallback, useEffect, useState } from 'react';
import type { AppRoles, CompanyRegistryClient, MemberSuggestion, Workspace, WorkspaceMember, WorkspaceSettings } from './companyRegistryClient.ts';
import { CompanyRegistryError, emptyWorkspaceSettings } from './companyRegistryClient.ts';
import { WorkspaceMemberSettingsForm, workspaceSettingsSummary } from './WorkspaceCreation.tsx';
import { personName, personSecondary, shortDate } from './people.ts';
import { injectCompanyAccessStyles } from './styles.ts';

/** Runs one change and reports it; resolves (never rejects) once the change is done or has failed. */
export type WorkspaceRun = (key: string, fn: () => Promise<unknown>, done: string) => Promise<void>;

export interface WorkspaceManagerProps {
  client: CompanyRegistryClient;
  /** The workspace to manage. Omit it to manage the one the signed-in person owns. */
  workspace?: Workspace | null;
  /** Called after any change (rename, invite, remove), so the host can refresh its own views. */
  onChanged?: () => void;
  /**
   * The host's own runner, when it shows busy state, errors and notices itself (Users & Access
   * does). Omit it and the manager shows them.
   */
  run?: WorkspaceRun;
  busyKey?: string | null;
}

function message(e: unknown, fallback: string): string {
  return e instanceof CompanyRegistryError ? e.message : fallback;
}

function memberStatus(m: WorkspaceMember): string {
  if (m.membership_type === 'owner') return 'Owner';
  if (m.status === 'invited') return m.invited_at ? `Invited ${shortDate(m.invited_at)} · waiting for them to accept` : 'Invited · waiting for them to accept';
  return m.accepted_at ? `Member since ${shortDate(m.accepted_at)}` : 'Member';
}

/**
 * The owner's workspace: its name, who is in it, and who to invite. Each owner has exactly one
 * workspace; everyone they invite, or share a company with, joins that one. Members SEE the
 * owner's companies (names and their own access status only) — opening one still needs access
 * granted per application, unless the owner's sharing settings (v1.2.0, at invite or later via
 * "Settings") let them create companies here or give them access to every company.
 */
export function WorkspaceManager({ client, workspace, onChanged, run: hostRun, busyKey: hostBusy }: WorkspaceManagerProps) {
  injectCompanyAccessStyles();
  const findOwn = workspace == null;
  const [owned, setOwned] = useState<Workspace | null | undefined>(undefined);
  const [members, setMembers] = useState<WorkspaceMember[] | null>(null);
  const [suggestions, setSuggestions] = useState<MemberSuggestion[]>([]);
  const [email, setEmail] = useState('');
  const [nameDraft, setNameDraft] = useState<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);
  const [ownBusy, setOwnBusy] = useState<string | null>(null);
  const [ownError, setOwnError] = useState<string | null>(null);
  const [ownNotice, setOwnNotice] = useState<string | null>(null);
  /** v1.2.0: the role catalogue; null while loading or when the server is older (settings hidden). */
  const [appRoles, setAppRoles] = useState<AppRoles | null>(null);
  const [inviteSettingsOpen, setInviteSettingsOpen] = useState(false);
  const [inviteSettings, setInviteSettings] = useState<WorkspaceSettings>(emptyWorkspaceSettings);
  const [editing, setEditing] = useState<string | null>(null);
  const [editDraft, setEditDraft] = useState<WorkspaceSettings>(emptyWorkspaceSettings);

  const ws = findOwn ? owned : workspace;
  const wsId = ws ? ws.workspace_id : null;
  const busyKey = hostRun ? (hostBusy ?? null) : ownBusy;

  useEffect(() => {
    if (!findOwn) return undefined;
    let live = true;
    client
      .workspaces()
      .then((all) => {
        if (live) setOwned((Array.isArray(all) ? all : []).find((w) => w.is_owner) ?? null);
      })
      .catch((e: unknown) => {
        if (live) setLoadError(message(e, 'The workspace could not be loaded.'));
      });
    return () => {
      live = false;
    };
  }, [client, findOwn, tick]);

  useEffect(() => {
    let live = true;
    if (typeof client.appRoles !== 'function') return undefined;
    client
      .appRoles()
      .then((r) => {
        if (live) setAppRoles(r && Object.keys(r).length > 0 ? r : null);
      })
      .catch(() => {
        if (live) setAppRoles(null);
      });
    return () => {
      live = false;
    };
  }, [client]);

  useEffect(() => {
    if (wsId === null) return undefined;
    let live = true;
    Promise.all([client.workspaceMembers(wsId), client.memberSuggestions(wsId).catch(() => [] as MemberSuggestion[])])
      .then(([m, s]) => {
        if (!live) return;
        setMembers(Array.isArray(m) ? m : []);
        setSuggestions(s);
      })
      .catch((e: unknown) => {
        if (live) setLoadError(message(e, 'The workspace members could not be loaded.'));
      });
    return () => {
      live = false;
    };
  }, [client, wsId, tick]);

  const ownRun = useCallback<WorkspaceRun>(
    async (key, fn, done) => {
      setOwnBusy(key);
      setOwnError(null);
      setOwnNotice(null);
      try {
        await fn();
        setOwnNotice(done);
      } catch (e) {
        setOwnError(message(e, 'That did not work. Please try again.'));
      } finally {
        setOwnBusy(null);
      }
    },
    [],
  );

  const act = async (key: string, fn: () => Promise<unknown>, done: string, after?: () => void) => {
    let ok = false;
    await (hostRun ?? ownRun)(key, async () => {
      await fn();
      ok = true;
    }, done);
    if (!ok) return;
    after?.();
    setTick((t) => t + 1);
    onChanged?.();
  };

  if (loadError) return <div className="aic-ca-alert aic-ca-tone-danger" role="alert">{loadError}</div>;
  if (ws === undefined) return <div className="aic-ca-empty">Loading workspace…</div>;
  if (ws === null) return <div className="aic-ca-empty">Your workspace could not be found. Reload the page to try again.</div>;

  const visible = (members ?? []).filter((m) => m.status === 'active' || m.status === 'invited');
  const trimmedName = (nameDraft ?? '').trim();

  return (
    <div className="aic-ca-stack">
      {!hostRun && ownError ? <div className="aic-ca-alert aic-ca-tone-danger" role="alert">{ownError}</div> : null}
      {!hostRun && ownNotice ? <div className="aic-ca-alert aic-ca-tone-success" role="status">{ownNotice}</div> : null}

      {nameDraft === null ? (
        <div className="aic-ca-between">
          <span>
            <span className="aic-ca-meta">Workspace name</span>
            <span className="aic-ca-name" style={{ display: 'block' }}>{ws.workspace_name}</span>
          </span>
          <button type="button" className="aic-ca-btn aic-ca-btn-sm" onClick={() => setNameDraft(ws.workspace_name)}>Rename</button>
        </div>
      ) : (
        <form
          className="aic-ca-row"
          onSubmit={(e) => {
            e.preventDefault();
            if (!trimmedName) return;
            void act('ws-rename', () => client.renameWorkspace(ws.workspace_id, trimmedName), 'The workspace was renamed.', () => setNameDraft(null));
          }}
        >
          <input
            className="aic-ca-input"
            style={{ flex: '1 1 220px' }}
            aria-label="Workspace name"
            maxLength={120}
            value={nameDraft}
            onChange={(e) => setNameDraft(e.target.value)}
            autoFocus
          />
          <button type="submit" className="aic-ca-btn aic-ca-btn-primary" disabled={!trimmedName || busyKey === 'ws-rename'}>Save name</button>
          <button type="button" className="aic-ca-btn" onClick={() => setNameDraft(null)}>Cancel</button>
        </form>
      )}

      <p className="aic-ca-sub">
        People you trust to <strong>see</strong> your companies (names and their own access status only).
        Seeing is not opening — access is granted per company and application. Invited people must accept first.
      </p>

      {members === null ? (
        <div className="aic-ca-empty">Loading members…</div>
      ) : (
        <ul className="aic-ca-list" aria-label="Workspace members">
          {visible.map((m) => {
            const secondary = personSecondary(m);
            return (
              <li key={m.uuid} className="aic-ca-item" style={{ cursor: 'default' }}>
                <span style={{ minWidth: 0, flex: '1 1 auto' }}>
                  <span className="aic-ca-name" style={{ display: 'block' }}>{personName(m)}</span>
                  {secondary ? <span className="aic-ca-meta" style={{ display: 'block' }}>{secondary}</span> : null}
                  <span className="aic-ca-meta" style={{ display: 'block' }}>{memberStatus(m)}</span>
                  {m.membership_type !== 'owner' && m.settings ? (
                    <span className="aic-ca-meta" style={{ display: 'block' }}>{workspaceSettingsSummary(m.settings)}</span>
                  ) : null}
                  {editing === m.uuid && appRoles ? (
                    <form
                      className="aic-ca-stack aic-ca-card"
                      style={{ marginTop: 8 }}
                      onSubmit={(e) => {
                        e.preventDefault();
                        void act(`ws-set-${m.uuid}`, () => client.updateMemberSettings(ws.workspace_id, m.uuid, editDraft), `Settings for ${personName(m)} were saved.`, () => setEditing(null));
                      }}
                    >
                      <WorkspaceMemberSettingsForm value={editDraft} onChange={setEditDraft} appRoles={appRoles} disabled={busyKey === `ws-set-${m.uuid}`} />
                      <span className="aic-ca-row">
                        <button type="submit" className="aic-ca-btn aic-ca-btn-primary aic-ca-btn-sm" disabled={busyKey === `ws-set-${m.uuid}`}>Save settings</button>
                        <button type="button" className="aic-ca-btn aic-ca-btn-sm" onClick={() => setEditing(null)}>Cancel</button>
                      </span>
                    </form>
                  ) : null}
                </span>
                {m.membership_type === 'owner' || !appRoles || editing === m.uuid ? null : (
                  <button
                    type="button"
                    className="aic-ca-btn aic-ca-btn-sm"
                    aria-label={`Sharing settings for ${personName(m)}`}
                    onClick={() => {
                      setEditDraft({ ...emptyWorkspaceSettings(), ...(m.settings ?? {}) });
                      setEditing(m.uuid);
                    }}
                  >
                    Settings
                  </button>
                )}
                {m.membership_type === 'owner' ? null : (
                  <button
                    type="button"
                    className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-danger"
                    disabled={busyKey === `ws-${m.uuid}`}
                    aria-label={`Remove ${personName(m)}`}
                    onClick={() => {
                      if (!window.confirm(`Remove ${personName(m)} from your workspace? They will stop seeing your companies.`)) return;
                      const alsoRevoke = window.confirm('Also remove their access to every company you shared with them? (Cancel keeps their existing company access.)');
                      void act(`ws-${m.uuid}`, () => client.removeMember(ws.workspace_id, m.uuid, alsoRevoke), `${personName(m)} was removed from the workspace.`);
                    }}
                  >
                    Remove
                  </button>
                )}
              </li>
            );
          })}
          {visible.length <= 1 ? <li className="aic-ca-item aic-ca-meta" style={{ cursor: 'default' }}>Nobody else is in your workspace yet.</li> : null}
        </ul>
      )}

      {suggestions.length > 0 ? (
        <div className="aic-ca-stack">
          <div className="aic-ca-meta">People you already share companies with:</div>
          <ul className="aic-ca-list">
            {suggestions.map((s) => {
              const secondary = personSecondary(s);
              return (
                <li key={s.uuid} className="aic-ca-item" style={{ cursor: 'default' }}>
                  <span style={{ minWidth: 0 }}>
                    <span className="aic-ca-name" style={{ display: 'block' }}>{personName(s)}</span>
                    <span className="aic-ca-meta">
                      {secondary ? `${secondary} · ` : ''}shares {s.shared_company_count} of your compan{s.shared_company_count === 1 ? 'y' : 'ies'}
                    </span>
                  </span>
                  <button
                    type="button"
                    className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary"
                    disabled={busyKey === `inv-${s.uuid}`}
                    aria-label={`Invite ${personName(s)}`}
                    onClick={() => void act(`inv-${s.uuid}`, () => client.inviteMember(ws.workspace_id, { uuid: s.uuid, name: s.name, email: s.email }), `${personName(s)} was invited to your workspace.`)}
                  >
                    Invite
                  </button>
                </li>
              );
            })}
          </ul>
        </div>
      ) : null}

      <form
        className="aic-ca-row"
        onSubmit={(e) => {
          e.preventDefault();
          const to = email.trim();
          if (!to) return;
          const body = inviteSettingsOpen && appRoles ? { uuid: '', email: to, settings: inviteSettings } : { uuid: '', email: to };
          void act('inv-email', () => client.inviteMember(ws.workspace_id, body), `${to} was invited to your workspace.`, () => {
            setEmail('');
            setInviteSettings(emptyWorkspaceSettings());
            setInviteSettingsOpen(false);
          });
        }}
      >
        <input
          className="aic-ca-input"
          style={{ flex: '1 1 220px' }}
          type="email"
          aria-label="Invite by email"
          placeholder="Invite by email (registered Aicountly user)"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
        <button type="submit" className="aic-ca-btn aic-ca-btn-primary" disabled={!email.trim() || busyKey === 'inv-email'}>Invite</button>
        {appRoles ? (
          <button type="button" className="aic-ca-btn" aria-expanded={inviteSettingsOpen} onClick={() => setInviteSettingsOpen((o) => !o)}>
            {inviteSettingsOpen ? 'Hide settings' : 'Sharing settings…'}
          </button>
        ) : null}
        {inviteSettingsOpen && appRoles ? (
          <div className="aic-ca-card" style={{ flex: '1 1 100%' }}>
            <WorkspaceMemberSettingsForm value={inviteSettings} onChange={setInviteSettings} appRoles={appRoles} disabled={busyKey === 'inv-email'} />
          </div>
        ) : null}
      </form>
    </div>
  );
}
