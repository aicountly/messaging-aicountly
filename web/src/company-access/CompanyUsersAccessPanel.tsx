import { useCallback, useEffect, useMemo, useState } from 'react';
import type {
  AccessRequest,
  AuditEntry,
  CompanyPermissions,
  CompanyRegistryClient,
  DirectoryCompany,
  MemberSuggestion,
  PermissionMember,
  Workspace,
  WorkspaceMember,
} from './companyRegistryClient.ts';
import { CompanyRegistryError } from './companyRegistryClient.ts';
import { CompanyAccessBadge } from './CompanyAccessBadge.tsx';
import { injectCompanyAccessStyles } from './styles.ts';

export interface CompanyUsersAccessPanelProps {
  client: CompanyRegistryClient;
  cmpId: number;
  /** The product's name as people know it: "Aicountly Books", "Sales", … */
  appName: string;
  /**
   * Roles this product offers (its own role/profile keys). Optional: without them a grant gives
   * the product's default role, and the product keeps deciding what each role may do.
   */
  roles?: Array<{ key: string; label: string }>;
  /** Called after any change, so the host can refresh its own views. */
  onChanged?: () => void;
}

type Tab = 'people' | 'requests' | 'workspace' | 'history';

const OPS: Record<string, string> = {
  'company.app_granted': 'granted access',
  'company.app_granted_by_request': 'approved a request and granted access',
  'company.app_revoked': 'revoked access',
  'company.app_grant_updated': 'changed access',
  'company.visibility_changed': 'changed workspace visibility',
  'company.share_granted': 'shared the company',
  'company.share_revoked': 'removed company access',
  'company.ownership_transferred': 'transferred ownership',
  'access_request.created': 'asked for access',
  'access_request.approved': 'approved a request',
  'access_request.rejected': 'rejected a request',
  'access_request.cancelled': 'withdrew a request',
  'workspace.member_invited': 'invited a workspace member',
  'workspace.invitation_accepted': 'joined the workspace',
  'workspace.invitation_declined': 'declined the workspace invitation',
  'workspace.member_exited': 'left the workspace',
  'workspace.member_removed': 'removed a workspace member',
  'workspace.renamed': 'renamed the workspace',
};

function who(m: { name?: string | null; email?: string | null; uuid?: string }): string {
  return m.name || m.email || (m.uuid ? `User #${m.uuid}` : 'Someone');
}

function message(e: unknown, fallback: string): string {
  return e instanceof CompanyRegistryError ? e.message : fallback;
}

/**
 * Company Settings → Users & Access, native in every product, backed by the one central registry.
 *
 *  - The verified owner sees everyone with a relationship to the company, their access in THIS
 *    application, and grants, changes or revokes it here; reviews access requests; manages the
 *    workspace (who may discover the company); and reads the history.
 *  - Anyone else sees their own access and can ask for it. Only the owner can change sharing — an
 *    Admin cannot — and the server enforces that whatever this screen shows.
 */
export function CompanyUsersAccessPanel({ client, cmpId, appName, roles, onChanged }: CompanyUsersAccessPanelProps) {
  injectCompanyAccessStyles();
  const [tab, setTab] = useState<Tab>('people');
  const [perm, setPerm] = useState<CompanyPermissions | null>(null);
  const [summary, setSummary] = useState<DirectoryCompany | null>(null);
  const [requests, setRequests] = useState<AccessRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyKey, setBusyKey] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [p, s, r] = await Promise.all([
        client.permissions(cmpId),
        client.access(cmpId),
        client.companyRequests(cmpId).catch(() => [] as AccessRequest[]),
      ]);
      setPerm(p);
      setSummary(s);
      setRequests(r);
    } catch (e) {
      setError(message(e, 'Users & access could not be loaded.'));
    } finally {
      setLoading(false);
    }
  }, [client, cmpId]);

  useEffect(() => {
    void load();
  }, [load]);

  const run = useCallback(
    async (key: string, fn: () => Promise<unknown>, done: string) => {
      setBusyKey(key);
      setError(null);
      setNotice(null);
      try {
        await fn();
        setNotice(done);
        await load();
        onChanged?.();
      } catch (e) {
        setError(message(e, 'That did not work. Please try again.'));
      } finally {
        setBusyKey(null);
      }
    },
    [load, onChanged],
  );

  const pending = useMemo(() => requests.filter((r) => r.status === 'pending'), [requests]);
  const isOwner = perm?.can_manage_access === true;

  if (loading && !perm) {
    return <div className="aic-ca aic-ca-card aic-ca-empty">Loading users &amp; access…</div>;
  }
  if (!perm) {
    return (
      <div className="aic-ca aic-ca-card aic-ca-stack">
        <div className="aic-ca-alert aic-ca-tone-danger" role="alert">{error ?? 'Users & access could not be loaded.'}</div>
        <div><button type="button" className="aic-ca-btn" onClick={() => void load()}>Retry</button></div>
      </div>
    );
  }

  return (
    <div className="aic-ca aic-ca-card aic-ca-stack">
      <div className="aic-ca-between">
        <div>
          <h2 className="aic-ca-title">Users &amp; Access</h2>
          <p className="aic-ca-sub">
            {perm.company.name} · access in {appName}. Workspace members can see this company; only people granted access can open it.
          </p>
        </div>
        {isOwner && summary ? (
          <label className="aic-ca-row aic-ca-meta" title="Workspace members see the company name and their own access status — never its data.">
            <input
              type="checkbox"
              checked={summary.workspace_visibility !== 'hidden'}
              disabled={busyKey === 'visibility'}
              onChange={(e) =>
                void run('visibility', () => client.setVisibility(cmpId, e.target.checked ? 'discoverable' : 'hidden'),
                  e.target.checked ? 'Workspace members can now see this company.' : 'This company is now hidden from workspace members who are not shared on it.')
              }
            />
            Visible to my workspace
          </label>
        ) : null}
      </div>

      <div className="aic-ca-tabs" role="tablist">
        <button type="button" role="tab" className="aic-ca-tab" aria-selected={tab === 'people'} onClick={() => setTab('people')}>
          {isOwner ? 'People & access' : 'My access'}
        </button>
        <button type="button" role="tab" className="aic-ca-tab" aria-selected={tab === 'requests'} onClick={() => setTab('requests')}>
          Requests{pending.length > 0 ? ` (${pending.length})` : ''}
        </button>
        {isOwner ? (
          <>
            <button type="button" role="tab" className="aic-ca-tab" aria-selected={tab === 'workspace'} onClick={() => setTab('workspace')}>Workspace</button>
            <button type="button" role="tab" className="aic-ca-tab" aria-selected={tab === 'history'} onClick={() => setTab('history')}>History</button>
          </>
        ) : null}
      </div>

      {error ? <div className="aic-ca-alert aic-ca-tone-danger" role="alert">{error}</div> : null}
      {notice ? <div className="aic-ca-alert aic-ca-tone-success" role="status">{notice}</div> : null}

      {tab === 'people' ? (
        isOwner ? (
          <OwnerPeople perm={perm} appName={appName} roles={roles} busyKey={busyKey} run={run} client={client} cmpId={cmpId} />
        ) : (
          <MyAccess summary={summary} appName={appName} roles={roles} busyKey={busyKey} run={run} client={client} cmpId={cmpId} />
        )
      ) : null}
      {tab === 'requests' ? (
        <Requests requests={requests} isOwner={isOwner} roles={roles} busyKey={busyKey} run={run} client={client} />
      ) : null}
      {tab === 'workspace' && isOwner ? <WorkspaceTab client={client} run={run} busyKey={busyKey} /> : null}
      {tab === 'history' && isOwner ? <History client={client} cmpId={cmpId} people={perm.members} /> : null}
    </div>
  );
}

type Run = (key: string, fn: () => Promise<unknown>, done: string) => Promise<void>;

function OwnerPeople({ perm, appName, roles, busyKey, run, client, cmpId }: {
  perm: CompanyPermissions; appName: string; roles?: Array<{ key: string; label: string }>; busyKey: string | null; run: Run;
  client: CompanyRegistryClient; cmpId: number;
}) {
  const [role, setRole] = useState<Record<string, string>>({});
  const [expiry, setExpiry] = useState<Record<string, string>>({});
  const appKey = client.appKey;
  const roleLabel = (key: string | null | undefined) => roles?.find((r) => r.key === key)?.label ?? key ?? 'Default';

  return (
    <div className="aic-ca-stack">
      <table className="aic-ca-table">
        <thead>
          <tr>
            <th>Person</th>
            <th>Access in {appName}</th>
            <th>Other applications</th>
            <th aria-label="Actions" />
          </tr>
        </thead>
        <tbody>
          {perm.members.map((m: PermissionMember) => {
            const mine = m.entitlements.find((e) => e.app_key === appKey);
            const status = m.app_access_status ?? (m.is_owner ? 'OWNER' : 'ACCESS_RESTRICTED');
            const granted = status === 'ACCESS_GRANTED';
            const others = m.app_scope === 'all'
              ? ['All applications']
              : m.entitlements.filter((e) => e.app_key !== appKey && e.state === 'active').map((e) => e.app_name);
            const k = m.uuid;
            return (
              <tr key={m.uuid}>
                <td>
                  <div className="aic-ca-name">{who(m)}</div>
                  <div className="aic-ca-meta">
                    {m.is_owner ? 'Owner' : m.legacy_co_owner ? 'Owner-level (legacy)' : m.relationship === 'workspace' ? 'Workspace member' : 'Company member'}
                    {m.email && m.name ? ` · ${m.email}` : ''}
                  </div>
                </td>
                <td>
                  <CompanyAccessBadge status={status} />
                  {mine?.role_key ? <div className="aic-ca-meta">Role: {roleLabel(mine.role_key)}</div> : null}
                  {mine?.expires_at ? <div className="aic-ca-meta">Until {new Date(mine.expires_at).toLocaleDateString()}</div> : null}
                </td>
                <td>{others.length ? others.map((o) => <span className="aic-ca-chip" key={o}>{o}</span>) : <span className="aic-ca-meta">—</span>}</td>
                <td>
                  {m.is_owner || m.legacy_co_owner ? null : granted ? (
                    <div className="aic-ca-row">
                      {roles && roles.length > 0 ? (
                        <select
                          className="aic-ca-select"
                          style={{ width: 'auto' }}
                          aria-label={`Role for ${who(m)}`}
                          value={mine?.role_key ?? ''}
                          disabled={busyKey === k}
                          onChange={(e) => void run(k, () => client.updateGrant(cmpId, `${m.uuid}:${appKey}`, { role_key: e.target.value || null }), `${who(m)}'s role was changed.`)}
                        >
                          <option value="">Default</option>
                          {roles.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
                        </select>
                      ) : null}
                      <button
                        type="button"
                        className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-danger"
                        disabled={busyKey === k}
                        onClick={() => {
                          if (window.confirm(`Revoke ${who(m)}'s access to ${perm.company.name} in ${appName}? Their other applications are not affected.`)) {
                            void run(k, () => client.revoke(cmpId, `${m.uuid}:${appKey}`), `${who(m)} can no longer open this company in ${appName}.`);
                          }
                        }}
                      >
                        Revoke
                      </button>
                    </div>
                  ) : (
                    <div className="aic-ca-row">
                      {roles && roles.length > 0 ? (
                        <select className="aic-ca-select" style={{ width: 'auto' }} aria-label={`Role for ${who(m)}`} value={role[k] ?? ''} onChange={(e) => setRole({ ...role, [k]: e.target.value })}>
                          <option value="">Default role</option>
                          {roles.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
                        </select>
                      ) : null}
                      <input
                        className="aic-ca-input"
                        style={{ width: 'auto' }}
                        type="date"
                        aria-label="Access until (optional)"
                        title="Access until (optional)"
                        value={expiry[k] ?? ''}
                        onChange={(e) => setExpiry({ ...expiry, [k]: e.target.value })}
                      />
                      <button
                        type="button"
                        className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary"
                        disabled={busyKey === k}
                        onClick={() => void run(k, () => client.grant(cmpId, {
                          uuid: m.uuid,
                          role_key: role[k] || null,
                          expires_at: expiry[k] ? new Date(`${expiry[k]}T23:59:59`).toISOString() : null,
                        }), `${who(m)} can now open this company in ${appName}.`)}
                      >
                        Grant
                      </button>
                    </div>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <p className="aic-ca-meta">
        To add someone who is not listed, invite them to your workspace on the Workspace tab, then grant them access here.
        Access in each application is separate: granting {appName} gives nothing in any other application.
      </p>
    </div>
  );
}

function MyAccess({ summary, appName, roles, busyKey, run, client, cmpId }: {
  summary: DirectoryCompany | null; appName: string; roles?: Array<{ key: string; label: string }>; busyKey: string | null; run: Run;
  client: CompanyRegistryClient; cmpId: number;
}) {
  const [why, setWhy] = useState('');
  const [role, setRole] = useState('');
  if (!summary) return null;
  return (
    <div className="aic-ca-stack">
      <div className="aic-ca-between">
        <span>Your access in {appName}</span>
        <CompanyAccessBadge status={summary.access_status} />
      </div>
      {summary.entitlement?.role_key ? <div className="aic-ca-meta">Role: {roles?.find((r) => r.key === summary.entitlement?.role_key)?.label ?? summary.entitlement.role_key}</div> : null}
      {summary.entitlement?.expires_at ? <div className="aic-ca-meta">Until {new Date(summary.entitlement.expires_at).toLocaleDateString()}</div> : null}
      {summary.can_request_access ? (
        <div className="aic-ca-stack">
          {roles && roles.length > 0 ? (
            <select className="aic-ca-select" aria-label="Access you need" value={role} onChange={(e) => setRole(e.target.value)}>
              <option value="">Let the owner decide</option>
              {roles.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
            </select>
          ) : null}
          <textarea className="aic-ca-textarea" rows={2} maxLength={1000} placeholder="Why do you need access? (optional)" value={why} onChange={(e) => setWhy(e.target.value)} />
          <div>
            <button
              type="button"
              className="aic-ca-btn aic-ca-btn-primary"
              disabled={busyKey === 'request'}
              onClick={() => void run('request', () => client.requestAccess(cmpId, { justification: why.trim() || undefined, requested_role: role || null }), 'Your request was sent to the company owner.')}
            >
              Request access
            </button>
          </div>
        </div>
      ) : null}
      <p className="aic-ca-meta">Only the company owner can change who has access. Ask them if something here looks wrong.</p>
    </div>
  );
}

function Requests({ requests, isOwner, roles, busyKey, run, client }: {
  requests: AccessRequest[]; isOwner: boolean; roles?: Array<{ key: string; label: string }>; busyKey: string | null; run: Run; client: CompanyRegistryClient;
}) {
  const [role, setRole] = useState<Record<number, string>>({});
  if (requests.length === 0) return <div className="aic-ca-empty">No access requests.</div>;
  return (
    <ul className="aic-ca-list">
      {requests.map((r) => {
        const k = `req-${r.request_id}`;
        return (
          <li key={r.request_id} className="aic-ca-item" style={{ cursor: 'default', alignItems: 'flex-start' }}>
            <div style={{ minWidth: 0 }}>
              <div className="aic-ca-name">{isOwner ? who({ name: r.requester_name, email: r.requester_email, uuid: r.requester_uuid }) : 'You'} · {r.app_name}</div>
              <div className="aic-ca-meta">
                {new Date(r.created_at).toLocaleString()}
                {r.requested_role ? ` · asked for ${roles?.find((x) => x.key === r.requested_role)?.label ?? r.requested_role}` : ''}
              </div>
              {r.justification ? <div style={{ marginTop: 4 }}>“{r.justification}”</div> : null}
              {r.review_note ? <div className="aic-ca-meta">Note: {r.review_note}</div> : null}
            </div>
            <div className="aic-ca-stack" style={{ alignItems: 'flex-end', gap: 6 }}>
              <span className={`aic-ca-badge aic-ca-tone-${r.status === 'approved' ? 'success' : r.status === 'pending' ? 'info' : 'neutral'}`}>{r.status}</span>
              {r.status === 'pending' && isOwner ? (
                <div className="aic-ca-row">
                  {roles && roles.length > 0 && r.app_key === client.appKey ? (
                    <select className="aic-ca-select" style={{ width: 'auto' }} aria-label="Role to grant" value={role[r.request_id] ?? r.requested_role ?? ''} onChange={(e) => setRole({ ...role, [r.request_id]: e.target.value })}>
                      <option value="">Default role</option>
                      {roles.map((x) => <option key={x.key} value={x.key}>{x.label}</option>)}
                    </select>
                  ) : null}
                  <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary" disabled={busyKey === k}
                    onClick={() => void run(k, () => client.approveRequest(r.request_id, { role_key: (role[r.request_id] ?? r.requested_role) || null }), 'Request approved — access granted.')}>
                    Approve
                  </button>
                  <button type="button" className="aic-ca-btn aic-ca-btn-sm" disabled={busyKey === k}
                    onClick={() => void run(k, () => client.rejectRequest(r.request_id, window.prompt('Note to the requester (optional)') ?? undefined), 'Request rejected.')}>
                    Reject
                  </button>
                </div>
              ) : null}
              {r.status === 'pending' && !isOwner ? (
                <button type="button" className="aic-ca-btn aic-ca-btn-sm" disabled={busyKey === k} onClick={() => void run(k, () => client.cancelRequest(r.request_id), 'Request withdrawn.')}>
                  Withdraw
                </button>
              ) : null}
            </div>
          </li>
        );
      })}
    </ul>
  );
}

function WorkspaceTab({ client, run, busyKey }: { client: CompanyRegistryClient; run: Run; busyKey: string | null }) {
  const [ws, setWs] = useState<Workspace | null>(null);
  const [members, setMembers] = useState<WorkspaceMember[]>([]);
  const [suggestions, setSuggestions] = useState<MemberSuggestion[]>([]);
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    let live = true;
    (async () => {
      try {
        const all = await client.workspaces();
        const owned = all.find((w) => w.is_owner) ?? null;
        if (!live) return;
        setWs(owned);
        if (owned) {
          const [m, s] = await Promise.all([client.workspaceMembers(owned.workspace_id), client.memberSuggestions(owned.workspace_id)]);
          if (!live) return;
          setMembers(m);
          setSuggestions(s);
        }
      } catch (e) {
        if (live) setError(message(e, 'The workspace could not be loaded.'));
      }
    })();
    return () => {
      live = false;
    };
  }, [client, tick]);

  const act = (key: string, fn: () => Promise<unknown>, done: string) => run(key, fn, done).then(() => setTick((t) => t + 1));

  if (error) return <div className="aic-ca-alert aic-ca-tone-danger">{error}</div>;
  if (!ws) return <div className="aic-ca-empty">Loading workspace…</div>;
  return (
    <div className="aic-ca-stack">
      <p className="aic-ca-sub">
        {ws.workspace_name}: people you trust to <strong>see</strong> your companies (names and their own access status only).
        Seeing is not opening — grant access per application on the People tab. Invited people must accept first.
      </p>
      <ul className="aic-ca-list">
        {members.filter((m) => m.status === 'active' || m.status === 'invited').map((m) => (
          <li key={m.uuid} className="aic-ca-item" style={{ cursor: 'default' }}>
            <span>
              <span className="aic-ca-name">{who(m)}</span>
              <span className="aic-ca-meta"> · {m.membership_type === 'owner' ? 'Owner' : m.status === 'invited' ? 'Invited' : 'Member'}</span>
            </span>
            {m.membership_type === 'owner' ? null : (
              <span className="aic-ca-row">
                <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-danger" disabled={busyKey === `ws-${m.uuid}`}
                  onClick={() => {
                    if (!window.confirm(`Remove ${who(m)} from your workspace? They will stop seeing your companies.`)) return;
                    const alsoRevoke = window.confirm('Also remove their access to every company you shared with them? (Cancel keeps their existing company access.)');
                    void act(`ws-${m.uuid}`, () => client.removeMember(ws.workspace_id, m.uuid, alsoRevoke), `${who(m)} was removed from the workspace.`);
                  }}>
                  Remove
                </button>
              </span>
            )}
          </li>
        ))}
      </ul>
      {suggestions.length > 0 ? (
        <div className="aic-ca-stack">
          <div className="aic-ca-meta">People you already share companies with:</div>
          <ul className="aic-ca-list">
            {suggestions.map((s) => (
              <li key={s.uuid} className="aic-ca-item" style={{ cursor: 'default' }}>
                <span>
                  <span className="aic-ca-name">{who(s)}</span>
                  <span className="aic-ca-meta"> · shares {s.shared_company_count} of your compan{s.shared_company_count === 1 ? 'y' : 'ies'}</span>
                </span>
                <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary" disabled={busyKey === `inv-${s.uuid}`}
                  onClick={() => void act(`inv-${s.uuid}`, () => client.inviteMember(ws.workspace_id, { uuid: s.uuid, name: s.name, email: s.email }), `${who(s)} was invited to your workspace.`)}>
                  Invite
                </button>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
      <div className="aic-ca-row">
        <input className="aic-ca-input" style={{ flex: '1 1 220px' }} type="email" placeholder="Invite by email (registered Aicountly user)" value={email} onChange={(e) => setEmail(e.target.value)} />
        <button type="button" className="aic-ca-btn aic-ca-btn-primary" disabled={!email.trim() || busyKey === 'inv-email'}
          onClick={() => void act('inv-email', () => client.inviteMember(ws.workspace_id, { uuid: '', email: email.trim() }), `${email.trim()} was invited to your workspace.`).then(() => setEmail(''))}>
          Invite
        </button>
      </div>
    </div>
  );
}

function History({ client, cmpId, people }: { client: CompanyRegistryClient; cmpId: number; people: PermissionMember[] }) {
  const [rows, setRows] = useState<AuditEntry[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let live = true;
    client.accessAudit(cmpId).then((r) => { if (live) setRows(r); }).catch((e: unknown) => { if (live) setError(message(e, 'History could not be loaded.')); });
    return () => {
      live = false;
    };
  }, [client, cmpId]);
  const name = (uuid: string | null) => {
    if (!uuid) return '';
    const p = people.find((x) => x.uuid === uuid);
    return p ? who(p) : `User #${uuid}`;
  };
  if (error) return <div className="aic-ca-alert aic-ca-tone-danger">{error}</div>;
  if (!rows) return <div className="aic-ca-empty">Loading history…</div>;
  if (rows.length === 0) return <div className="aic-ca-empty">No access changes recorded yet.</div>;
  return (
    <ul className="aic-ca-list">
      {rows.map((r) => (
        <li key={r.audit_id} className="aic-ca-item" style={{ cursor: 'default' }}>
          <span>
            <strong>{name(r.actor_uuid)}</strong> {OPS[r.operation] ?? r.operation}
            {r.target_uuid && r.target_uuid !== r.actor_uuid ? ` for ${name(r.target_uuid)}` : ''}
            {r.app_key ? <span className="aic-ca-chip" style={{ marginLeft: 6 }}>{r.app_key}</span> : null}
            {r.reason ? <span className="aic-ca-meta"> — {r.reason}</span> : null}
          </span>
          <span className="aic-ca-meta">{new Date(r.occurred_at).toLocaleString()}</span>
        </li>
      ))}
    </ul>
  );
}
