import { useCallback, useEffect, useMemo, useState } from 'react';
import type {
  AccessRequest,
  AuditEntry,
  CompanyPermissions,
  CompanyRegistryClient,
  DirectoryCompany,
  PermissionMember,
  PersonLookupKind,
  PersonLookupResult,
  PersonMatch,
} from './companyRegistryClient.ts';
import { CompanyRegistryError } from './companyRegistryClient.ts';
import { CompanyAccessBadge } from './CompanyAccessBadge.tsx';
import { WorkspaceManager } from './WorkspaceManager.tsx';
import { personName as who, shortDate, shortDateTime } from './people.ts';
import { PersonIdentity } from './PersonIdentity.tsx';
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
  'workspace.member_moved': 'moved someone between the Workspace team and Company users',
  'workspace.member_auto_enrolled': 'became a Company user by accepting a share',
  'workspace.member_group_migrated': 'was sorted into the Workspace team or Company users',
};

function message(e: unknown, fallback: string): string {
  return e instanceof CompanyRegistryError ? e.message : fallback;
}

/**
 * Company Settings → Users & Access, native in every product, backed by the one central registry.
 *
 *  - The verified owner sees everyone with a relationship to the company, their access in THIS
 *    application (through a share of this company, or through the Workspace team — v1.3.0), and
 *    grants, changes or revokes it here; reviews access requests; manages the workspace (its team
 *    and Company users); and reads the history.
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
    async (key: string, fn: () => Promise<unknown>, done: string): Promise<boolean> => {
      setBusyKey(key);
      setError(null);
      setNotice(null);
      try {
        await fn();
        setNotice(done);
        await load();
        onChanged?.();
        return true;
      } catch (e) {
        setError(message(e, 'That did not work. Please try again.'));
        return false;
      } finally {
        setBusyKey(null);
      }
    },
    [load, onChanged],
  );

  const pending = useMemo(() => requests.filter((r) => r.status === 'pending'), [requests]);
  const isOwner = perm?.can_manage_access === true;
  // Read defensively: a registry that answers without `company` or `members` (an older or partial
  // deployment) must leave this panel usable, not take the host's whole settings route down.
  const companyName = perm?.company?.name || 'this company';
  const members = perm?.members ?? [];

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
            {companyName} · access in {appName}. Your Workspace team opens this company with their team permissions; anyone else needs access shared or granted here.
          </p>
        </div>
        {isOwner && summary ? (
          <label className="aic-ca-row aic-ca-meta" title="Off: your Workspace team no longer opens this company. People it is shared with directly keep their access.">
            <input
              type="checkbox"
              checked={summary.workspace_visibility !== 'hidden'}
              disabled={busyKey === 'visibility'}
              onChange={(e) =>
                void run('visibility', () => client.setVisibility(cmpId, e.target.checked ? 'discoverable' : 'hidden'),
                  e.target.checked ? 'Your Workspace team opens this company again.' : 'Your Workspace team no longer opens this company, except people it is shared with directly.')
              }
            />
            Open to my Workspace team
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
          <OwnerPeople members={members} companyName={companyName} appName={appName} roles={roles} busyKey={busyKey} run={run} client={client} cmpId={cmpId} viewerUuid={perm?.viewer_uuid ?? null} />
        ) : (
          <MyAccess summary={summary} appName={appName} roles={roles} busyKey={busyKey} run={run} client={client} cmpId={cmpId} />
        )
      ) : null}
      {tab === 'requests' ? (
        <Requests requests={requests} isOwner={isOwner} roles={roles} busyKey={busyKey} run={run} client={client} />
      ) : null}
      {tab === 'workspace' && isOwner ? <WorkspaceManager client={client} run={run} busyKey={busyKey} /> : null}
      {tab === 'history' && isOwner ? <History client={client} cmpId={cmpId} people={members} /> : null}
    </div>
  );
}

/** Resolves true when the change went through (the panel has reloaded), false when it showed an error. */
type Run = (key: string, fn: () => Promise<unknown>, done: string) => Promise<boolean>;

function OwnerPeople({ members, companyName, appName, roles, busyKey, run, client, cmpId, viewerUuid }: {
  members: PermissionMember[]; companyName: string; appName: string; roles?: Array<{ key: string; label: string }>; busyKey: string | null; run: Run;
  client: CompanyRegistryClient; cmpId: number;
  /** Who is asking, so their own row is marked. Null from a registry older than 1.5.0. */
  viewerUuid: string | null;
}) {
  const [role, setRole] = useState<Record<string, string>>({});
  const [expiry, setExpiry] = useState<Record<string, string>>({});
  const [adding, setAdding] = useState(false);
  const appKey = client.appKey;
  const roleLabel = (key: string | null | undefined) => roles?.find((r) => r.key === key)?.label ?? key ?? 'Default';
  // 1.6.0: the table lists the people who HAVE a standing in this application (owner, granted,
  // expired, revoked, requested, …). Everyone who is only "Access restricted" here — the whole
  // Workspace team and every Company user who was never given this app — is offered by "Add user"
  // instead of filling the table with rows of Grant buttons.
  const listed = members.filter((m) => statusOf(m) !== 'ACCESS_RESTRICTED');
  const addable = members.filter((m) => statusOf(m) === 'ACCESS_RESTRICTED');

  return (
    <div className="aic-ca-stack">
      <div className="aic-ca-between">
        <span className="aic-ca-meta">
          {listed.length === 1 ? '1 person has' : `${listed.length} people have`} a standing in {appName} on {companyName}.
        </span>
        <button
          type="button"
          className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary"
          aria-expanded={adding}
          onClick={() => setAdding((v) => !v)}
        >
          {adding ? 'Close' : 'Add user'}
        </button>
      </div>
      {adding ? (
        <AddUser
          people={addable}
          companyName={companyName}
          appName={appName}
          roles={roles}
          busyKey={busyKey}
          client={client}
          cmpId={cmpId}
          onGrant={(m, roleKey, until) => run('add', () => client.grant(cmpId, {
            uuid: m.uuid,
            role_key: roleKey || null,
            expires_at: until ? new Date(`${until}T23:59:59`).toISOString() : null,
          }), `${who(m)} can now open this company in ${appName}.`).then((ok) => { if (ok) setAdding(false); return ok; })}
          onInvite={(body, label) => run('add', () => client.invitePerson(cmpId, body),
            `Invitation sent to ${label}. They can open this company in ${appName} once they accept it.`).then((ok) => { if (ok) setAdding(false); return ok; })}
        />
      ) : null}
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
          {listed.map((m: PermissionMember) => {
            const entitlements = m.entitlements ?? [];
            const mine = entitlements.find((e) => e.app_key === appKey);
            const until = shortDate(mine?.expires_at);
            const status = statusOf(m);
            const granted = status === 'ACCESS_GRANTED';
            // v1.3.0: access in this app through the Workspace team, not a grant here — nothing here to revoke.
            const viaTeam = granted && m.app_access_via === 'workspace';
            const teamToo = !viaTeam && (m.workspace_role_key ?? null) !== null;
            const others = m.app_scope === 'all'
              ? ['All applications']
              : entitlements.filter((e) => e.app_key !== appKey && e.state === 'active').map((e) => e.app_name);
            const group = m.is_owner ? 'Owner' : m.legacy_co_owner ? 'Owner-level (legacy)'
              : m.workspace_group === 'team' ? (m.relationship === 'workspace' ? 'Workspace team · no share of this company' : 'Workspace team')
              : m.workspace_group === 'company_users' ? 'Company user'
              : m.relationship === 'workspace' ? 'Workspace member' : 'Company member';
            const k = m.uuid;
            return (
              <tr key={m.uuid}>
                <td>
                  {/* Name, e-mail, mobile and id — all four, labelled, for everyone.
                      This cell used to print whichever one happened to be there and
                      then append the address ONLY when a name was there too, so the
                      row with no name was the row that also hid the address. */}
                  <PersonIdentity person={m} suffix={viewerUuid !== null && viewerUuid === m.uuid ? ' (you)' : ''} />
                  <div className="aic-ca-meta">{group}</div>
                </td>
                <td>
                  <CompanyAccessBadge status={status} />
                  {viaTeam ? <div className="aic-ca-meta">Through the Workspace team{m.workspace_role_key ? ` · ${roleLabel(m.workspace_role_key)}` : ''}</div> : null}
                  {!viaTeam && mine?.role_key ? <div className="aic-ca-meta">Role: {roleLabel(mine.role_key)}</div> : null}
                  {until && !viaTeam ? <div className="aic-ca-meta">Until {until}</div> : null}
                </td>
                <td>{others.length ? others.map((o) => <span className="aic-ca-chip" key={o}>{o}</span>) : <span className="aic-ca-meta">—</span>}</td>
                <td>
                  {m.is_owner || m.legacy_co_owner ? null : viaTeam ? (
                    <span className="aic-ca-meta">Change it in the Workspace team&apos;s settings</span>
                  ) : granted ? (
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
                          const ask = teamToo
                            ? `Revoke the access to ${companyName} in ${appName} granted to ${who(m)} here? They are on your Workspace team, so they keep ${appName} on ${companyName} through the team (${roleLabel(m.workspace_role_key)}) — change that in the team's settings. Their other applications are not affected.`
                            : `Revoke ${who(m)}'s access to ${companyName} in ${appName}? Their other applications are not affected.`;
                          if (window.confirm(ask)) {
                            void run(k, () => client.revoke(cmpId, `${m.uuid}:${appKey}`),
                              teamToo ? `The grant here was revoked; ${who(m)} still opens this company in ${appName} through the Workspace team.` : `${who(m)} can no longer open this company in ${appName}.`);
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
          {listed.length === 0 ? (
            <tr>
              <td colSpan={4} className="aic-ca-meta">Nobody has access in {appName} yet. Use Add user to grant it.</td>
            </tr>
          ) : null}
        </tbody>
      </table>
      <p className="aic-ca-meta">
        Listed here: everyone with access in {appName} (or whose access expired, was revoked or is requested). Add user finds
        anyone by e-mail, mobile or account ID — someone in your workspace or company is granted access at once, anyone else
        gets an invitation to accept (or to sign up first) — and lists your Workspace team and Company users who cannot open
        it here yet. Access in each application is separate: granting {appName} gives nothing in any other application.
      </p>
    </div>
  );
}

/** A member's status in THIS application; a member the registry says nothing about is restricted. */
function statusOf(m: PermissionMember): string {
  return m.app_access_status ?? (m.is_owner ? 'OWNER' : 'ACCESS_RESTRICTED');
}

const KIND_LABEL: Record<PersonLookupKind, string> = { email: 'E-mail', mobile: 'Mobile', account_id: 'Account ID' };
const KIND_FIELD: Record<PersonLookupKind, { label: string; placeholder: string; type: string; inputMode: 'email' | 'tel' | 'numeric' }> = {
  email: { label: 'E-mail address', placeholder: 'name@example.com', type: 'email', inputMode: 'email' },
  mobile: { label: 'Mobile number', placeholder: '10-digit mobile, e.g. 98765 43210', type: 'tel', inputMode: 'tel' },
  account_id: { label: 'Account ID', placeholder: 'Their AICOUNTLY account ID', type: 'text', inputMode: 'numeric' },
};

function relationLabel(m: PersonMatch): string {
  if (m.relation === 'owner') return 'Company owner';
  if (m.relation === 'company') return m.workspace_group === 'team' ? 'Workspace team · shared this company' : 'Company user';
  if (m.relation === 'workspace') return m.workspace_group === 'company_users' ? 'Company user in your workspace' : 'Workspace team';
  return 'Not in this company or your workspace yet';
}

/**
 * "Add user" (1.6.0, 1.7.0). Two ways to the same Grant:
 *
 *  - Find someone by e-mail, mobile or account ID (`lookupPerson`). The answer shows who they are and what
 *    can be done here: Grant now (they are in the company or the workspace), Send an invitation (registered
 *    elsewhere — they accept it), or why nothing is needed. Several accounts on one e-mail or mobile are listed
 *    to choose from. Nobody registered: invite them to sign up (a mobile needs the e-mail the invitation goes to).
 *  - Or choose from the people in the workspace or shared the company who cannot open it in this application
 *    yet ("Access restricted").
 *
 * A registry older than 1.7.0 answers the look-up 404: the panel says so and the list still works.
 */
function AddUser({ people, companyName, appName, roles, busyKey, client, cmpId, onGrant, onInvite }: {
  people: PermissionMember[]; companyName: string; appName: string; roles?: Array<{ key: string; label: string }>;
  busyKey: string | null; client: CompanyRegistryClient; cmpId: number;
  onGrant: (person: { uuid: string; name?: string | null; email?: string | null }, roleKey: string, until: string) => Promise<boolean>;
  onInvite: (body: { uuid?: string; email?: string; mobile?: string; role_key?: string | null }, label: string) => Promise<boolean>;
}) {
  const [query, setQuery] = useState('');
  const [chosen, setChosen] = useState<string>('');
  const [roleKey, setRoleKey] = useState('');
  const [until, setUntil] = useState('');
  // Find by e-mail / mobile / account ID (1.7.0).
  const [kind, setKind] = useState<PersonLookupKind>('email');
  const [value, setValue] = useState('');
  const [looking, setLooking] = useState(false);
  const [lookupError, setLookupError] = useState<string | null>(null);
  const [result, setResult] = useState<PersonLookupResult | null>(null);
  const [picked, setPicked] = useState<string>('');
  const [inviteEmail, setInviteEmail] = useState('');
  const busy = busyKey === 'add';

  const q = query.trim().toLowerCase();
  const shown = q === ''
    ? people
    : people.filter((m) => [m.name, m.email, m.mobile, m.uuid].some((v) => typeof v === 'string' && v.toLowerCase().includes(q)));
  const person = people.find((m) => m.uuid === chosen) ?? null;
  const groupOf = (m: PermissionMember) => (m.workspace_group === 'team' ? 'Workspace team' : m.workspace_group === 'company_users' ? 'Company user' : m.relationship === 'workspace' ? 'Workspace member' : 'Company member');

  const find = async () => {
    const v = value.trim();
    if (v === '' || looking) return;
    setLooking(true);
    setLookupError(null);
    setResult(null);
    setPicked('');
    setInviteEmail('');
    try {
      const r = await client.lookupPerson(cmpId, { kind, value: v });
      setResult(r);
      if (r.matches.length === 1) setPicked(r.matches[0].uuid);
    } catch (e) {
      if (e instanceof CompanyRegistryError && e.status === 404 && e.code !== 'COMPANY_NOT_DISCOVERABLE') {
        setLookupError('Finding people by e-mail, mobile or account ID needs a newer AICOUNTLY Manage. Choose from your workspace below meanwhile.');
      } else {
        setLookupError(message(e, 'That person could not be looked up. Please try again.'));
      }
    } finally {
      setLooking(false);
    }
  };

  const match = result?.matches.find((m) => m.uuid === picked) ?? null;
  const roleSelect = (label: string) => roles && roles.length > 0 ? (
    <select className="aic-ca-select" style={{ width: 'auto' }} aria-label={label} value={roleKey} onChange={(e) => setRoleKey(e.target.value)}>
      <option value="">Default role</option>
      {roles.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
    </select>
  ) : null;
  const untilInput = (
    <input
      className="aic-ca-input"
      style={{ width: 'auto' }}
      type="date"
      aria-label="Access until (optional)"
      title="Access until (optional)"
      value={until}
      onChange={(e) => setUntil(e.target.value)}
    />
  );
  const field = KIND_FIELD[kind];
  const inviteEmailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(inviteEmail.trim());

  return (
    <div className="aic-ca-card aic-ca-stack" role="group" aria-label={`Add a user to ${appName}`}>
      <div className="aic-ca-name">Add a user to {companyName} in {appName}</div>

      <form
        className="aic-ca-stack"
        role="search"
        aria-label="Find a person"
        onSubmit={(e) => { e.preventDefault(); void find(); }}
      >
        <span className="aic-ca-meta">Find them by e-mail, mobile or account ID</span>
        <div className="aic-ca-row">
          <select
            className="aic-ca-select"
            style={{ width: 'auto' }}
            aria-label="Find by"
            value={kind}
            onChange={(e) => { setKind(e.target.value as PersonLookupKind); setResult(null); setLookupError(null); }}
          >
            {(Object.keys(KIND_LABEL) as PersonLookupKind[]).map((k) => <option key={k} value={k}>{KIND_LABEL[k]}</option>)}
          </select>
          <input
            className="aic-ca-input"
            style={{ flex: 1, minWidth: 180 }}
            type={field.type}
            inputMode={field.inputMode}
            aria-label={field.label}
            placeholder={field.placeholder}
            value={value}
            onChange={(e) => setValue(e.target.value)}
          />
          <button type="submit" className="aic-ca-btn aic-ca-btn-sm" disabled={value.trim() === '' || looking}>
            {looking ? 'Finding…' : 'Find'}
          </button>
        </div>
      </form>

      {lookupError ? <div className="aic-ca-alert aic-ca-tone-danger" role="alert">{lookupError}</div> : null}

      {result ? (
        <div className="aic-ca-stack" role="region" aria-label="Look-up result">
          {result.status === 'multiple' ? (
            <div className="aic-ca-alert aic-ca-tone-info" role="status">
              {result.message ?? `${result.matches.length} accounts use this ${KIND_LABEL[result.kind].toLowerCase()}. Choose the right one.`}
            </div>
          ) : null}
          {result.matches.length > 0 ? (
            <ul className="aic-ca-list" aria-label="Accounts found">
              {result.matches.map((m) => (
                <li key={m.uuid} className="aic-ca-item" style={{ cursor: result.matches.length > 1 ? 'pointer' : 'default', alignItems: 'flex-start' }}>
                  <label className="aic-ca-row" style={{ cursor: 'inherit', width: '100%', alignItems: 'flex-start' }}>
                    {result.matches.length > 1 ? (
                      <input
                        type="radio"
                        name="aic-ca-add-found"
                        value={m.uuid}
                        aria-label={`Account ${m.uuid}${m.name ? ` (${m.name})` : ''}`}
                        checked={picked === m.uuid}
                        onChange={() => setPicked(m.uuid)}
                      />
                    ) : null}
                    <span className="aic-ca-stack" style={{ gap: 4, minWidth: 0 }}>
                      <PersonIdentity person={m} />
                      <span className="aic-ca-meta">{relationLabel(m)}</span>
                      {m.app_access_status ? <span><CompanyAccessBadge status={m.app_access_status} /></span> : null}
                      {m.note ? <span className="aic-ca-meta">{m.note}</span> : null}
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          ) : null}

          {match && match.action === 'grant' ? (
            <div className="aic-ca-row">
              {roleSelect('Role to grant')}
              {untilInput}
              <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary" disabled={busy}
                onClick={() => void onGrant(match, roleKey, until)}>
                {`Grant ${who(match)}`}
              </button>
            </div>
          ) : null}
          {match && match.action === 'invite' ? (
            <div className="aic-ca-stack" style={{ gap: 6 }}>
              <div className="aic-ca-row">
                {roleSelect('Role to invite with')}
                <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary" disabled={busy}
                  onClick={() => void onInvite({ uuid: match.uuid, role_key: roleKey || null }, who(match))}>
                  {`Send invitation to ${who(match)}`}
                </button>
              </div>
              <span className="aic-ca-meta">They get access once they accept. An end date can be set after that.</span>
            </div>
          ) : null}
          {result.matches.length > 1 && !match ? <span className="aic-ca-meta">Choose an account to continue.</span> : null}

          {result.status === 'not_registered' ? (
            <div className="aic-ca-stack" style={{ gap: 6 }}>
              <div className="aic-ca-alert aic-ca-tone-info" role="status">
                {result.message ?? 'No AICOUNTLY account uses this.'} Invite them: they sign up, then accept access to {companyName}.
              </div>
              <div className="aic-ca-row">
                {result.invite.needs_email ? (
                  <input
                    className="aic-ca-input"
                    style={{ flex: 1, minWidth: 180 }}
                    type="email"
                    inputMode="email"
                    aria-label="E-mail for the invitation"
                    placeholder="The invitation is sent by e-mail"
                    value={inviteEmail}
                    onChange={(e) => setInviteEmail(e.target.value)}
                  />
                ) : null}
                {roleSelect('Role to invite with')}
                <button
                  type="button"
                  className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary"
                  disabled={busy || (result.invite.needs_email && !inviteEmailValid)}
                  onClick={() => {
                    const email = result.kind === 'email' ? result.value : inviteEmail.trim();
                    void onInvite({
                      email,
                      mobile: result.kind === 'mobile' ? result.value : undefined,
                      role_key: roleKey || null,
                    }, email);
                  }}
                >
                  Send invitation
                </button>
              </div>
            </div>
          ) : null}
          {result.status === 'not_found' ? (
            <div className="aic-ca-alert aic-ca-tone-info" role="status">
              {result.message ?? 'No one with this account ID is in this company or your workspace. Look them up by e-mail or mobile instead.'}
            </div>
          ) : null}
        </div>
      ) : null}

      {people.length > 0 ? (
        <div className="aic-ca-stack">
          <span className="aic-ca-meta">Or choose from your workspace and company users who cannot open it in {appName} yet</span>
          <input
            className="aic-ca-input"
            type="search"
            aria-label="Search people"
            placeholder="Search by name, e-mail or mobile"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          {shown.length === 0 ? (
            <div className="aic-ca-empty">Nobody matches.</div>
          ) : (
            <ul className="aic-ca-list" style={{ maxHeight: 260, overflowY: 'auto' }}>
              {shown.map((m) => (
                <li key={m.uuid} className="aic-ca-item" style={{ cursor: 'pointer' }}>
                  <label className="aic-ca-row" style={{ cursor: 'pointer', width: '100%' }}>
                    <input type="radio" name="aic-ca-add-user" value={m.uuid} checked={chosen === m.uuid} onChange={() => setChosen(m.uuid)} />
                    <span style={{ minWidth: 0 }}>
                      <span className="aic-ca-name">{who(m)}</span>
                      <span className="aic-ca-meta">{' · '}{groupOf(m)}{m.email && m.email !== who(m) ? ` · ${m.email}` : ''}</span>
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          )}
          <div className="aic-ca-row">
            {roleSelect('Role to grant')}
            {untilInput}
            <button
              type="button"
              className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary"
              disabled={person === null || busy}
              onClick={() => { if (person) void onGrant(person, roleKey, until); }}
            >
              {person ? `Grant ${who(person)}` : 'Grant'}
            </button>
          </div>
        </div>
      ) : null}
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
  const until = shortDate(summary.entitlement?.expires_at);
  return (
    <div className="aic-ca-stack">
      <div className="aic-ca-between">
        <span>Your access in {appName}</span>
        <CompanyAccessBadge status={summary.access_status} />
      </div>
      {summary.entitlement?.role_key ? <div className="aic-ca-meta">Role: {roles?.find((r) => r.key === summary.entitlement?.role_key)?.label ?? summary.entitlement.role_key}</div> : null}
      {until ? <div className="aic-ca-meta">Until {until}</div> : null}
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
                {shortDateTime(r.created_at) || '—'}
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
          <span className="aic-ca-meta">{shortDateTime(r.occurred_at) || '—'}</span>
        </li>
      ))}
    </ul>
  );
}
