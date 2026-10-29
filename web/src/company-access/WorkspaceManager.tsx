import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import type {
  AppRoles,
  CompanyRegistryClient,
  CompanyUser,
  DirectCompany,
  MemberSuggestion,
  Workspace,
  WorkspaceMember,
  WorkspaceSettings,
} from './companyRegistryClient.ts';
import { CompanyRegistryError, emptyWorkspaceSettings } from './companyRegistryClient.ts';
import { WorkspaceMemberSettingsForm, appLabel, workspaceSettingsSummary } from './WorkspaceCreation.tsx';
import { personName, personSecondary, shortDate } from './people.ts';
import { injectCompanyAccessStyles } from './styles.ts';

/** Runs one change and reports it; resolves (never rejects) once the change is done or has failed. */
export type WorkspaceRun = (key: string, fn: () => Promise<unknown>, done: string) => Promise<void>;

export interface WorkspaceManagerProps {
  client: CompanyRegistryClient;
  /** The workspace to manage. Omit it to manage the one the signed-in person owns. */
  workspace?: Workspace | null;
  /** Called after any change (rename, invite, move, remove), so the host can refresh its own views. */
  onChanged?: () => void;
  /**
   * The host's own runner, when it shows busy state, errors and notices itself (Users & Access
   * does). Omit it and the manager shows them.
   */
  run?: WorkspaceRun;
  busyKey?: string | null;
}

/** v1.4.0: one set of team permissions for everyone selected — on the team, or moving there from Company users. */
type BulkKind = 'bulk-set' | 'bulk-move';

/** What the owner is about to confirm (one at a time, shown under the person — or the selection — it is about). */
type Pending =
  | { kind: 'to-users' | 'remove-team' | 'withdraw' | 'remove-all'; uuid: string }
  | { kind: 'to-team'; uuid: string; draft: WorkspaceSettings }
  | { kind: 'remove-company'; uuid: string; cmpId: number }
  /** mixed: the people selected did not all hold the same settings, so the form started empty. */
  | { kind: BulkKind; draft: WorkspaceSettings; mixed: boolean };

/** v1.4.0: how a change for several people ended: how many of them were changed, and the summary to show. */
interface BulkOutcome {
  kind: BulkKind;
  done: number;
  message: string;
  /** Who was not changed, with the reason in plain words. Empty: every one of them was. */
  failed: Array<{ uuid: string; who: string; reason: string }>;
}

function message(e: unknown, fallback: string): string {
  return e instanceof CompanyRegistryError ? e.message : fallback;
}

/** The Workspace team: the owner and everyone active or invited on it (an older Manage sends no member_group: all of them). */
function teamRows(members: WorkspaceMember[] | null): WorkspaceMember[] {
  return (members ?? []).filter((m) => (m.status === 'active' || m.status === 'invited')
    && (m.membership_type === 'owner' || (m.member_group ?? 'team') === 'team'));
}

/** Settings in one comparable form (map keys and role lists sorted): the same permissions give the same string. */
function settingsKey(s: Partial<WorkspaceSettings> | null | undefined): string {
  const v = { ...emptyWorkspaceSettings(), ...(s ?? {}) };
  const sorted = (m: Record<string, unknown> | null | undefined) => Object.keys(m ?? {}).sort().map((k) => [k, (m ?? {})[k]]);
  return JSON.stringify([
    v.can_create_companies === true,
    v.max_companies_created ?? null,
    sorted(v.creator_roles).map(([app, roles]) => [app, Array.isArray(roles) ? [...roles].sort() : roles]),
    sorted(v.all_companies_access),
  ]);
}

/** Where a change for several people starts: their own settings when they all hold the same, otherwise nothing chosen. */
function sharedStart(settings: Array<WorkspaceSettings | null | undefined>): { draft: WorkspaceSettings; mixed: boolean } {
  return new Set(settings.map(settingsKey)).size === 1
    ? { draft: { ...emptyWorkspaceSettings(), ...(settings[0] ?? {}) }, mixed: false }
    : { draft: emptyWorkspaceSettings(), mixed: true };
}

/** A new selection with `uuid` in or out. */
function toggled(picked: Set<string>, uuid: string, on: boolean): Set<string> {
  const next = new Set(picked);
  if (on) next.add(uuid);
  else next.delete(uuid);
  return next;
}

/** The selection without whoever is no longer listed (the same Set when nobody left, so nothing re-renders). */
function keepListed(picked: Set<string>, listed: Set<string>): Set<string> {
  return [...picked].every((u) => listed.has(u)) ? picked : new Set([...picked].filter((u) => listed.has(u)));
}

/**
 * Why one person of a change for several was not changed, for the owner. A plan refusal (PlanGate max_users: everyone
 * who opens a company in an app counts, the Workspace team included) says which company and app have no seat left and
 * what the owner can do; anything else is the server's own sentence.
 */
function bulkReason(e: unknown, companyName: (cmpId: number) => string | null): string {
  if (!(e instanceof CompanyRegistryError)) return 'That did not work. Please try again.';
  const { code, app, limit, used, cmp_id: cmpId } = (e.details ?? {}) as Record<string, unknown>;
  if (e.code === 'PLAN_LIMIT_REACHED' && code === 'max_users' && typeof app === 'string' && typeof limit === 'number') {
    const label = appLabel(app);
    const where = typeof cmpId === 'number' ? (companyName(cmpId) ?? `company #${cmpId}`) : 'one of your companies';
    const taken = typeof used === 'number' ? used : limit;
    return `No seat left in ${label}: your plan allows ${plural(limit, 'person', 'people')} on ${where}, and ${taken} already ${taken === 1 ? 'has' : 'have'} it. `
      + `Upgrade in Plans & billing, or set ${label} to None.`;
  }
  return e.message;
}

function memberStatus(m: WorkspaceMember): string {
  if (m.membership_type === 'owner') return 'Owner';
  if (m.status === 'invited') return m.invited_at ? `Invited ${shortDate(m.invited_at)} · waiting for them to accept` : 'Invited · waiting for them to accept';
  return m.accepted_at ? `On the team since ${shortDate(m.accepted_at)}` : 'On the team';
}

function plural(n: number, one: string, many: string): string {
  return `${n} ${n === 1 ? one : many}`;
}

/** "Priya", "Priya and Kiran", "Priya, Kiran and Ira". */
function andList(items: string[]): string {
  return items.length <= 1 ? (items[0] ?? '') : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

/** "Rao Traders (every app)" / "Rao Exports (Smart Books, Sales)". */
function companyLine(c: DirectCompany): string {
  const apps = c.apps === null ? 'every app' : c.apps.length > 0 ? c.apps.map(appLabel).join(', ') : 'no app yet';
  return `${c.name} (${c.owner_level ? 'owner-level, every app' : apps})${c.company_status === 'recycled' ? ' — in the recycle bin' : ''}`;
}

function CompanyList({ companies, label }: { companies: DirectCompany[]; label: string }) {
  return (
    <ul aria-label={label} style={{ margin: '4px 0 0', paddingLeft: 18 }}>
      {companies.map((c) => <li key={c.cmp_id}>{companyLine(c)}</li>)}
    </ul>
  );
}

/** An inline confirmation under the row it is about: no modal, keyboard focus moves to its title. */
function Confirm({ id, title, children, confirmLabel, danger, busy, cancelDisabled, onConfirm, onCancel }: {
  id: string; title: string; children: ReactNode; confirmLabel: string; danger?: boolean; busy: boolean;
  /** v1.4.0: while a change for several people runs, it cannot be walked away from half-way. */
  cancelDisabled?: boolean;
  onConfirm: () => void; onCancel: () => void;
}) {
  const heading = useRef<HTMLParagraphElement | null>(null);
  useEffect(() => {
    heading.current?.focus();
  }, []);
  return (
    <div className="aic-ca-confirm" role="group" aria-labelledby={`${id}-title`} aria-describedby={`${id}-body`}>
      <p id={`${id}-title`} ref={heading} tabIndex={-1} className="aic-ca-name" style={{ margin: 0 }}>{title}</p>
      <div id={`${id}-body`} className="aic-ca-stack" style={{ gap: 6, marginTop: 6 }}>{children}</div>
      <span className="aic-ca-row" style={{ marginTop: 10 }}>
        <button type="button" className={`aic-ca-btn aic-ca-btn-sm ${danger ? 'aic-ca-btn-danger' : 'aic-ca-btn-primary'}`} disabled={busy} onClick={onConfirm}>{confirmLabel}</button>
        <button type="button" className="aic-ca-btn aic-ca-btn-sm" disabled={cancelDisabled} onClick={onCancel}>Cancel</button>
      </span>
    </div>
  );
}

/** v1.4.0: "Select all" for one group, mixed (indeterminate) while only some are selected. */
function SelectAll({ label, count, total, disabled, onChange }: {
  label: string; count: number; total: number; disabled: boolean; onChange: (all: boolean) => void;
}) {
  const box = useRef<HTMLInputElement | null>(null);
  const all = total > 0 && count === total;
  useEffect(() => {
    if (box.current) box.current.indeterminate = count > 0 && !all;
  });
  return (
    <label className="aic-ca-row" style={{ gap: 8, flexWrap: 'nowrap', padding: 4, cursor: disabled ? 'not-allowed' : 'pointer' }}>
      <input ref={box} type="checkbox" aria-label={label} checked={all} disabled={disabled} style={{ width: 18, height: 18, margin: 0 }} onChange={() => onChange(!all)} />
      <span>Select all</span>
    </label>
  );
}

/** v1.4.0: one person's checkbox, with room around it for a finger. */
function Pick({ who, checked, disabled, onChange }: { who: string; checked: boolean; disabled: boolean; onChange: (on: boolean) => void }) {
  return (
    <label style={{ display: 'inline-flex', flex: '0 0 auto', padding: 4, marginTop: -3, cursor: disabled ? 'not-allowed' : 'pointer' }}>
      <input type="checkbox" aria-label={`Select ${who}`} checked={checked} disabled={disabled} style={{ width: 18, height: 18, margin: 0 }} onChange={(e) => onChange(e.target.checked)} />
    </label>
  );
}

/**
 * v1.4.0: how a change for several people ended. It takes keyboard focus when it arrives and the focus was lost (the
 * button that started the change was disabled while it ran, or is gone with its form), so the keyboard carries on here.
 */
function BulkNote({ tone, children }: { tone: 'success' | 'warning' | 'danger'; children: ReactNode }) {
  const box = useRef<HTMLDivElement | null>(null);
  useEffect(() => {
    if (document.activeElement === null || document.activeElement === document.body) box.current?.focus();
  }, []);
  return <div ref={box} tabIndex={-1} className={`aic-ca-alert aic-ca-tone-${tone}`} role={tone === 'success' ? 'status' : 'alert'}>{children}</div>;
}

/** v1.4.0: inside the form of a change for several people — how far it has got, then who was not changed and why. */
function BulkStatus({ progress, outcome, verb }: {
  progress: { done: number; total: number } | null; outcome: BulkOutcome | null; verb: string;
}) {
  return (
    <>
      <span className="aic-ca-meta" role="status">{progress ? `${verb} ${Math.min(progress.done + 1, progress.total)} of ${progress.total}…` : ''}</span>
      {outcome && outcome.failed.length > 0 ? (
        <BulkNote tone={outcome.done === 0 ? 'danger' : 'warning'}>
          <span>{outcome.message}</span>
          <ul aria-label="Not changed" style={{ margin: '4px 0 0', paddingLeft: 18 }}>
            {outcome.failed.map((f) => <li key={f.uuid}>{f.who}: {f.reason}</li>)}
          </ul>
          <span style={{ display: 'block', marginTop: 4 }}>They are still selected, so you can try again.</span>
        </BulkNote>
      ) : null}
    </>
  );
}

/**
 * The owner's workspace: its name and the two groups of people it has (v1.3.0).
 *
 *  - Workspace team — opens EVERY company of the workspace (now and later, except companies the owner
 *    hides), in Manage and in each application their team permissions name. Invited (they accept).
 *  - Company users — open ONLY the companies shared with them, and see nothing else of the workspace.
 *    Sharing a company with someone who is not on the team makes them a Company user.
 *
 * The owner moves people between the two ("Move to Company users" lists, before confirming, the
 * companies they keep), removes them (which ends all of their access here), or removes a Company user
 * from one company. Against an older Manage (no company-users route) only the team list is shown.
 *
 * Several people at once (v1.4.0): the owner selects people in either group and gives them one set of
 * team permissions ("Set permissions for N selected"), or moves them all to the team with it ("Move N
 * selected to Workspace team"). Each person is changed by the same call the per-person action makes, one
 * after another; whoever was not changed stays selected, with the reason, for a retry.
 */
export function WorkspaceManager({ client, workspace, onChanged, run: hostRun, busyKey: hostBusy }: WorkspaceManagerProps) {
  injectCompanyAccessStyles();
  const findOwn = workspace == null;
  const [owned, setOwned] = useState<Workspace | null | undefined>(undefined);
  const [members, setMembers] = useState<WorkspaceMember[] | null>(null);
  /** null = an older Manage without Company users (the room is not shown). */
  const [companyUsers, setCompanyUsers] = useState<CompanyUser[] | null>(null);
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
  const [pending, setPending] = useState<Pending | null>(null);
  /** v1.4.0: who is selected on the team and among the Company users, the change running for them, and how it ended. */
  const [teamPicked, setTeamPicked] = useState<Set<string>>(() => new Set());
  const [usersPicked, setUsersPicked] = useState<Set<string>>(() => new Set());
  const [progress, setProgress] = useState<{ kind: BulkKind; done: number; total: number } | null>(null);
  const [outcome, setOutcome] = useState<BulkOutcome | null>(null);

  const ws = findOwn ? owned : workspace;
  const wsId = ws ? ws.workspace_id : null;
  const busyKey = hostRun ? (hostBusy ?? null) : ownBusy;
  const hasRoom = typeof client.companyUsers === 'function';

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
    Promise.all([
      client.workspaceMembers(wsId),
      hasRoom ? client.companyUsers(wsId).catch(() => null) : Promise.resolve(null),
      // The Company users room replaces "people you already share companies with" (v1.3.0).
      hasRoom ? Promise.resolve([] as MemberSuggestion[]) : client.memberSuggestions(wsId).catch(() => [] as MemberSuggestion[]),
    ])
      .then(([m, u, s]) => {
        if (!live) return;
        setMembers(Array.isArray(m) ? m : []);
        setCompanyUsers(Array.isArray(u) ? u : null);
        setSuggestions(s);
      })
      .catch((e: unknown) => {
        if (live) setLoadError(message(e, 'The workspace members could not be loaded.'));
      });
    return () => {
      live = false;
    };
  }, [client, wsId, tick, hasRoom]);

  // Whoever has left a group since they were selected (moved, removed, gone from the reloaded list) is no longer selected there.
  useEffect(() => {
    const listed = new Set(teamRows(members).filter((m) => m.membership_type !== 'owner').map((m) => m.uuid));
    setTeamPicked((picked) => keepListed(picked, listed));
  }, [members]);
  useEffect(() => {
    const listed = new Set((companyUsers ?? []).map((u) => u.uuid));
    setUsersPicked((picked) => keepListed(picked, listed));
  }, [companyUsers]);

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
    setOutcome(null);
    await (hostRun ?? ownRun)(key, async () => {
      await fn();
      ok = true;
    }, done);
    if (!ok) return;
    after?.();
    setPending(null);
    setTick((t) => t + 1);
    onChanged?.();
  };

  /** A company's name from the ones listed here (those shared with someone directly), or null. */
  const companyName = (cmpId: number): string | null =>
    [...(members ?? []).flatMap((m) => m.direct_companies ?? []), ...(companyUsers ?? []).flatMap((u) => u.companies)]
      .find((c) => c.cmp_id === cmpId)?.name ?? null;

  /**
   * v1.4.0 — one change for several people. Each of them goes through the call the per-person action makes (validated,
   * checked against the owner's plan and audited for that person; there is no bulk route, so a relay that passes the
   * per-person calls passes this too), one after another: the last seat a plan has goes to the first person who needs
   * it, and the others are refused by name. Whoever was not changed stays selected, and the form stays open, to retry.
   */
  const runBulk = async (kind: BulkKind, people: Array<{ uuid: string; who: string }>, change: (uuid: string) => Promise<unknown>) => {
    if (people.length === 0) return;
    const failed: BulkOutcome['failed'] = [];
    setOutcome(null);
    setProgress({ kind, done: 0, total: people.length });
    // The host's runner shows it busy and refreshes the host's own views once; the summary is shown here, so it says nothing.
    await (hostRun ?? ownRun)(`ws-${kind}`, async () => {
      for (const p of people) {
        try {
          await change(p.uuid);
        } catch (e) {
          failed.push({ uuid: p.uuid, who: p.who, reason: bulkReason(e, companyName) });
        }
        setProgress((now) => (now ? { ...now, done: now.done + 1 } : now));
      }
    }, '');
    const total = people.length;
    const done = total - failed.length;
    const all = total === 1 ? people[0].who : total === 2 ? 'both' : `all ${total}`;
    setProgress(null);
    setOutcome({
      kind,
      done,
      failed,
      message: kind === 'bulk-set'
        ? (failed.length === 0 ? `Saved for ${all}.` : `Saved for ${done} of ${total}. Not saved:`)
        : (failed.length === 0 ? `Moved ${all} to your Workspace team.` : `Moved ${done} of ${total} to your Workspace team. Not moved:`),
    });
    (kind === 'bulk-set' ? setTeamPicked : setUsersPicked)(new Set(failed.map((f) => f.uuid)));
    if (failed.length === 0) setPending(null);
    if (done > 0) {
      setTick((t) => t + 1);
      onChanged?.();
    }
  };

  if (loadError) return <div className="aic-ca-alert aic-ca-tone-danger" role="alert">{loadError}</div>;
  if (ws === undefined) return <div className="aic-ca-empty">Loading workspace…</div>;
  if (ws === null) return <div className="aic-ca-empty">Your workspace could not be found. Reload the page to try again.</div>;

  const team = teamRows(members);
  const trimmedName = (nameDraft ?? '').trim();
  const teamCompanies = ws.team_company_count;
  const everyCompany = teamCompanies != null ? `every company in your workspace — ${plural(teamCompanies, 'company', 'companies')} now, and any you add later` : 'every company in your workspace, now and later';
  const isPending = (kind: Pending['kind'], uuid: string, cmpId?: number) =>
    pending?.kind === kind && 'uuid' in pending && pending.uuid === uuid
    && (cmpId === undefined || (pending.kind === 'remove-company' && pending.cmpId === cmpId));
  // v1.4.0 — several people at once: only on the owner's own workspace, and only with the role catalogue for the one form it opens.
  const canBulk = ws.is_owner === true && appRoles !== null;
  const running = progress !== null;
  const teamSelectable = team.filter((m) => m.membership_type !== 'owner');
  const teamChosen = teamSelectable.filter((m) => teamPicked.has(m.uuid));
  const usersChosen = (companyUsers ?? []).filter((u) => usersPicked.has(u.uuid));
  const teamBulk = canBulk && teamSelectable.length > 0;
  const usersBulk = canBulk && (companyUsers ?? []).length > 0;
  // While the form for a selection is open (or a change runs), the selection stays as it is: the form is about those people.
  const teamLocked = running || pending?.kind === 'bulk-set';
  const usersLocked = running || pending?.kind === 'bulk-move';
  const storedSettings = (uuid: string) => (members ?? []).find((m) => m.uuid === uuid)?.settings;

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

      <section className="aic-ca-stack" aria-labelledby={`aic-ca-team-${ws.workspace_id}`}>
        <div>
          <h3 id={`aic-ca-team-${ws.workspace_id}`} className="aic-ca-title" style={{ fontSize: 15 }}>Workspace team</h3>
          <p className="aic-ca-sub">
            Your team opens <strong>{everyCompany}</strong>, with the app permissions you choose for each person (companies you hide
            from the workspace are left out). Invited people join once they accept.
          </p>
        </div>
        {teamBulk ? (
          <div className="aic-ca-row" style={{ paddingLeft: 13 }}>
            <SelectAll label="Select all of your Workspace team" count={teamChosen.length} total={teamSelectable.length} disabled={teamLocked}
              onChange={(all) => setTeamPicked(all ? new Set(teamSelectable.map((m) => m.uuid)) : new Set())} />
            {teamChosen.length === 0 ? (
              <span className="aic-ca-meta">Choose people to give them all the same team permissions at once.</span>
            ) : pending?.kind === 'bulk-set' ? null : (
              <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary" disabled={running}
                onClick={() => {
                  setEditing(null);
                  setOutcome(null);
                  setPending({ kind: 'bulk-set', ...sharedStart(teamChosen.map((m) => m.settings)) });
                }}>
                Set permissions for {teamChosen.length} selected
              </button>
            )}
          </div>
        ) : null}
        {outcome?.kind === 'bulk-set' && outcome.failed.length === 0 ? <BulkNote tone="success">{outcome.message}</BulkNote> : null}
        {pending?.kind === 'bulk-set' && appRoles ? (
          <Confirm id={`aic-ca-bulk-team-${ws.workspace_id}`} title={`Set the same permissions for ${plural(teamChosen.length, 'person', 'people')}?`}
            confirmLabel={`Apply to ${plural(teamChosen.length, 'person', 'people')}`} busy={running || teamChosen.length === 0} cancelDisabled={running}
            onCancel={() => {
              setPending(null);
              setOutcome(null);
            }}
            onConfirm={() => void runBulk('bulk-set', teamChosen.map((m) => ({ uuid: m.uuid, who: personName(m) })),
              (uuid) => client.updateMemberSettings(ws.workspace_id, uuid, pending.draft))}>
            <span className="aic-ca-meta">{andList(teamChosen.map((m) => personName(m)))}</span>
            <span>
              Each person listed gets exactly the team permissions below. They <strong>replace</strong> what that person has now:
              their app permissions on every company, and whether they may create companies in your workspace.
            </span>
            {pending.mixed && !outcome ? <span>Their current permissions differ, so nothing is chosen below to start with.</span> : null}
            <WorkspaceMemberSettingsForm value={pending.draft} onChange={(draft) => setPending({ ...pending, draft })} appRoles={appRoles} disabled={running} />
            <BulkStatus progress={progress?.kind === 'bulk-set' ? progress : null} outcome={outcome?.kind === 'bulk-set' ? outcome : null} verb="Saving" />
          </Confirm>
        ) : null}
        {members === null ? (
          <div className="aic-ca-empty">Loading members…</div>
        ) : (
          <ul className="aic-ca-list" aria-label="Workspace team">
            {team.map((m) => {
              const secondary = personSecondary(m);
              const who = personName(m);
              const direct = m.direct_companies ?? [];
              const invited = m.status === 'invited';
              const k = m.uuid;
              return (
                <li key={m.uuid} className="aic-ca-item" style={{ cursor: 'default', flexWrap: 'wrap' }}>
                  <span style={{ minWidth: 0, flex: '1 1 260px', display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                    {!teamBulk ? null : m.membership_type === 'owner' ? <span aria-hidden="true" style={{ flex: '0 0 26px' }} /> : (
                      <Pick who={who} checked={teamPicked.has(m.uuid)} disabled={teamLocked} onChange={(on) => setTeamPicked((p) => toggled(p, m.uuid, on))} />
                    )}
                    <span style={{ minWidth: 0, flex: '1 1 auto' }}>
                      <span className="aic-ca-name" style={{ display: 'block' }}>{who}</span>
                      {secondary ? <span className="aic-ca-meta" style={{ display: 'block' }}>{secondary}</span> : null}
                      <span className="aic-ca-meta" style={{ display: 'block' }}>{memberStatus(m)}</span>
                      {m.membership_type !== 'owner' && m.settings ? (
                        <span className="aic-ca-meta" style={{ display: 'block' }}>{workspaceSettingsSummary(m.settings)}</span>
                      ) : null}
                      {m.membership_type !== 'owner' && direct.length > 0 ? (
                        <span className="aic-ca-meta" style={{ display: 'block' }}>Also shared directly: {direct.map((c) => c.name).join(', ')}</span>
                      ) : null}
                      {editing === m.uuid && appRoles ? (
                        <form
                          className="aic-ca-stack aic-ca-card"
                          style={{ marginTop: 8 }}
                          onSubmit={(e) => {
                            e.preventDefault();
                            void act(`ws-set-${k}`, () => client.updateMemberSettings(ws.workspace_id, m.uuid, editDraft), `Settings for ${who} were saved.`, () => setEditing(null));
                          }}
                        >
                          <WorkspaceMemberSettingsForm value={editDraft} onChange={setEditDraft} appRoles={appRoles} disabled={busyKey === `ws-set-${k}`} />
                          <span className="aic-ca-row">
                            <button type="submit" className="aic-ca-btn aic-ca-btn-primary aic-ca-btn-sm" disabled={busyKey === `ws-set-${k}`}>Save settings</button>
                            <button type="button" className="aic-ca-btn aic-ca-btn-sm" onClick={() => setEditing(null)}>Cancel</button>
                          </span>
                        </form>
                      ) : null}
                    </span>
                  </span>
                  {m.membership_type === 'owner' ? null : (
                    <span className="aic-ca-row" style={{ justifyContent: 'flex-end' }}>
                      {!appRoles || editing === m.uuid ? null : (
                        <button
                          type="button"
                          className="aic-ca-btn aic-ca-btn-sm"
                          aria-label={`Sharing settings for ${who}`}
                          onClick={() => {
                            setEditDraft({ ...emptyWorkspaceSettings(), ...(m.settings ?? {}) });
                            setEditing(m.uuid);
                          }}
                        >
                          Settings
                        </button>
                      )}
                      {hasRoom && !invited ? (
                        <button type="button" className="aic-ca-btn aic-ca-btn-sm" aria-label={`Move ${who} to Company users`}
                          onClick={() => setPending({ kind: 'to-users', uuid: m.uuid })}>
                          Move to Company users
                        </button>
                      ) : null}
                      <button
                        type="button"
                        className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-danger"
                        disabled={busyKey === `ws-${k}`}
                        aria-label={invited ? `Withdraw the invitation for ${who}` : `Remove ${who}`}
                        onClick={() => setPending({ kind: invited ? 'withdraw' : 'remove-team', uuid: m.uuid })}
                      >
                        {invited ? 'Withdraw invitation' : 'Remove'}
                      </button>
                    </span>
                  )}
                  {isPending('to-users', m.uuid) ? (
                    <div style={{ flex: '1 1 100%' }}>
                      <Confirm id={`aic-ca-c-${k}`} title={`Move ${who} to Company users?`} confirmLabel="Move to Company users" busy={busyKey === `ws-move-${k}`}
                        onCancel={() => setPending(null)}
                        onConfirm={() => void act(`ws-move-${k}`, () => client.moveMember(ws.workspace_id, m.uuid, 'company_users'),
                          direct.length > 0 ? `${who} is now a Company user and keeps ${plural(direct.length, 'company', 'companies')}.` : `${who} is now a Company user, with no company of yours.`)}>
                        <span>They will no longer open every company in your workspace.</span>
                        {direct.length > 0 ? (
                          <span>They keep only the {plural(direct.length, 'company', 'companies')} shared with them directly:<CompanyList companies={direct} label={`Companies ${who} keeps`} /></span>
                        ) : (
                          <span><strong>No company is shared with them directly, so they will lose access to your workspace.</strong></span>
                        )}
                        <span className="aic-ca-meta">Their app permissions are kept in case you move them back.</span>
                      </Confirm>
                    </div>
                  ) : null}
                  {isPending('remove-team', m.uuid) ? (
                    <div style={{ flex: '1 1 100%' }}>
                      <Confirm id={`aic-ca-c-${k}`} title={`Remove ${who} from your workspace?`} confirmLabel="Remove from workspace" danger busy={busyKey === `ws-${k}`}
                        onCancel={() => setPending(null)}
                        onConfirm={() => void act(`ws-${k}`, () => client.removeMember(ws.workspace_id, m.uuid), `${who} was removed: all of their access to your workspace has ended.`)}>
                        <span>
                          This ends <strong>all</strong> of their access to your workspace: the Workspace team&apos;s access to every company
                          {direct.length > 0 ? `, and the ${plural(direct.length, 'company', 'companies')} shared with them directly:` : '.'}
                        </span>
                        {direct.length > 0 ? <CompanyList companies={direct} label={`Companies ${who} loses`} /> : null}
                        {hasRoom ? <span className="aic-ca-meta">To let them keep some companies, choose Move to Company users instead.</span> : null}
                      </Confirm>
                    </div>
                  ) : null}
                  {isPending('withdraw', m.uuid) ? (
                    <div style={{ flex: '1 1 100%' }}>
                      <Confirm id={`aic-ca-c-${k}`} title={`Withdraw the invitation for ${who}?`} confirmLabel="Withdraw invitation" danger busy={busyKey === `ws-${k}`}
                        onCancel={() => setPending(null)}
                        onConfirm={() => void act(`ws-${k}`, () => client.removeMember(ws.workspace_id, m.uuid), `The invitation for ${who} was withdrawn.`)}>
                        <span>They will not join your Workspace team.</span>
                        {direct.length > 0 ? <span>The {plural(direct.length, 'company', 'companies')} shared with them directly stay shared:<CompanyList companies={direct} label={`Companies ${who} keeps`} /></span> : null}
                      </Confirm>
                    </div>
                  ) : null}
                </li>
              );
            })}
            {team.length <= 1 ? <li className="aic-ca-item aic-ca-meta" style={{ cursor: 'default' }}>Nobody else is on your team yet.</li> : null}
          </ul>
        )}

        <form
          className="aic-ca-row"
          onSubmit={(e) => {
            e.preventDefault();
            const to = email.trim();
            if (!to) return;
            const body = inviteSettingsOpen && appRoles ? { uuid: '', email: to, settings: inviteSettings } : { uuid: '', email: to };
            void act('inv-email', () => client.inviteMember(ws.workspace_id, body), `${to} was invited to your Workspace team.`, () => {
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
            placeholder="Invite to your team by email (registered Aicountly user)"
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
      </section>

      {companyUsers !== null ? (
        <section className="aic-ca-stack" aria-labelledby={`aic-ca-users-${ws.workspace_id}`}>
          <div>
            <h3 id={`aic-ca-users-${ws.workspace_id}`} className="aic-ca-title" style={{ fontSize: 15 }}>Company users</h3>
            <p className="aic-ca-sub">
              People who open <strong>only</strong> the companies you share with them, and see nothing else of your workspace. Sharing a
              company with someone who is not on your team adds them here.
            </p>
          </div>
          {usersBulk ? (
            <div className="aic-ca-row" style={{ paddingLeft: 13 }}>
              <SelectAll label="Select all Company users" count={usersChosen.length} total={companyUsers.length} disabled={usersLocked}
                onChange={(all) => setUsersPicked(all ? new Set(companyUsers.map((u) => u.uuid)) : new Set())} />
              {usersChosen.length === 0 ? (
                <span className="aic-ca-meta">Choose people to move them to your Workspace team together, with the same permissions.</span>
              ) : pending?.kind === 'bulk-move' ? null : (
                <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary" disabled={running}
                  onClick={() => {
                    setEditing(null);
                    setOutcome(null);
                    setPending({ kind: 'bulk-move', ...sharedStart(usersChosen.map((u) => storedSettings(u.uuid))) });
                  }}>
                  Move {usersChosen.length} selected to Workspace team
                </button>
              )}
            </div>
          ) : null}
          {outcome?.kind === 'bulk-move' && outcome.failed.length === 0 ? <BulkNote tone="success">{outcome.message}</BulkNote> : null}
          {pending?.kind === 'bulk-move' && appRoles ? (
            <Confirm id={`aic-ca-bulk-users-${ws.workspace_id}`} title={`Move ${plural(usersChosen.length, 'person', 'people')} to your Workspace team?`}
              confirmLabel={`Move ${plural(usersChosen.length, 'person', 'people')} to Workspace team`} busy={running || usersChosen.length === 0} cancelDisabled={running}
              onCancel={() => {
                setPending(null);
                setOutcome(null);
              }}
              onConfirm={() => void runBulk('bulk-move', usersChosen.map((u) => ({ uuid: u.uuid, who: personName(u) })),
                (uuid) => client.moveMember(ws.workspace_id, uuid, 'team', pending.draft))}>
              <span className="aic-ca-meta">{andList(usersChosen.map((u) => personName(u)))}</span>
              <span>They will open <strong>{everyCompany}</strong>, with these app permissions:</span>
              {pending.mixed && !outcome ? <span>The permissions saved for them differ, so nothing is chosen below to start with.</span> : null}
              <WorkspaceMemberSettingsForm value={pending.draft} onChange={(draft) => setPending({ ...pending, draft })} appRoles={appRoles} disabled={running} />
              <BulkStatus progress={progress?.kind === 'bulk-move' ? progress : null} outcome={outcome?.kind === 'bulk-move' ? outcome : null} verb="Moving" />
            </Confirm>
          ) : null}
          <ul className="aic-ca-list" aria-label="Company users">
            {companyUsers.map((u) => {
              const secondary = personSecondary(u);
              const who = personName(u);
              const k = u.uuid;
              const member = (members ?? []).find((m) => m.uuid === u.uuid);
              return (
                <li key={u.uuid} className="aic-ca-item" style={{ cursor: 'default', flexWrap: 'wrap', alignItems: 'flex-start' }}>
                  <span style={{ minWidth: 0, flex: '1 1 260px', display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                    {usersBulk ? (
                      <Pick who={who} checked={usersPicked.has(u.uuid)} disabled={usersLocked} onChange={(on) => setUsersPicked((p) => toggled(p, u.uuid, on))} />
                    ) : null}
                    <span style={{ minWidth: 0, flex: '1 1 auto' }}>
                      <span className="aic-ca-name" style={{ display: 'block' }}>{who}</span>
                      {secondary ? <span className="aic-ca-meta" style={{ display: 'block' }}>{secondary}</span> : null}
                      {u.companies.length === 0 ? (
                        <span className="aic-ca-meta" style={{ display: 'block' }}>No company is shared with them — they have no access to your workspace.</span>
                      ) : (
                        <ul aria-label={`Companies shared with ${who}`} style={{ listStyle: 'none', margin: '4px 0 0', padding: 0 }}>
                          {u.companies.map((c) => (
                            <li key={c.cmp_id} className="aic-ca-row" style={{ justifyContent: 'space-between', padding: '2px 0' }}>
                              <span className="aic-ca-meta">{companyLine(c)}</span>
                              {c.owner_level ? null : (
                                <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-danger" aria-label={`Remove ${who} from ${c.name}`}
                                  onClick={() => setPending({ kind: 'remove-company', uuid: u.uuid, cmpId: c.cmp_id })}>
                                  Remove
                                </button>
                              )}
                              {isPending('remove-company', u.uuid, c.cmp_id) ? (() => {
                                const others = u.companies.filter((o) => o.cmp_id !== c.cmp_id);
                                return (
                                  <div style={{ flex: '1 1 100%' }}>
                                    <Confirm id={`aic-ca-c-${k}-${c.cmp_id}`} title={`Remove ${who} from ${c.name}?`} confirmLabel={`Remove from ${c.name}`} danger busy={busyKey === `cu-${k}-${c.cmp_id}`}
                                      onCancel={() => setPending(null)}
                                      onConfirm={() => void act(`cu-${k}-${c.cmp_id}`, () => client.removeFromCompany(c.cmp_id, u.uuid), `${who} no longer has access to ${c.name}.`)}>
                                      <span>They lose access to {c.name} only.</span>
                                      {others.length > 0 ? (
                                        <span>They keep the other {plural(others.length, 'company', 'companies')} shared with them:<CompanyList companies={others} label={`Companies ${who} keeps`} /></span>
                                      ) : (
                                        <span><strong>It is the only company shared with them, so they will have no access to your workspace.</strong></span>
                                      )}
                                    </Confirm>
                                  </div>
                                );
                              })() : null}
                            </li>
                          ))}
                        </ul>
                      )}
                    </span>
                  </span>
                  <span className="aic-ca-row" style={{ justifyContent: 'flex-end' }}>
                    <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-primary" aria-label={`Move ${who} to the Workspace team`}
                      onClick={() => setPending({ kind: 'to-team', uuid: u.uuid, draft: { ...emptyWorkspaceSettings(), ...(member?.settings ?? {}) } })}>
                      Move to Workspace team
                    </button>
                    {u.companies.length > 0 ? (
                      <button type="button" className="aic-ca-btn aic-ca-btn-sm aic-ca-btn-danger" aria-label={`Remove ${who} from all companies`}
                        onClick={() => setPending({ kind: 'remove-all', uuid: u.uuid })}>
                        Remove from all
                      </button>
                    ) : null}
                  </span>
                  {pending?.kind === 'to-team' && pending.uuid === u.uuid ? (
                    <div style={{ flex: '1 1 100%' }}>
                      <Confirm id={`aic-ca-c-${k}`} title={`Move ${who} to your Workspace team?`} confirmLabel="Move to Workspace team" busy={busyKey === `cu-move-${k}`}
                        onCancel={() => setPending(null)}
                        onConfirm={() => void act(`cu-move-${k}`, () => client.moveMember(ws.workspace_id, u.uuid, 'team', pending.draft), `${who} is now on your Workspace team.`)}>
                        <span>They will open <strong>{everyCompany}</strong>{appRoles ? ', with these app permissions:' : '.'}</span>
                        {appRoles ? (
                          <WorkspaceMemberSettingsForm value={pending.draft} onChange={(draft) => setPending({ kind: 'to-team', uuid: u.uuid, draft })} appRoles={appRoles} disabled={busyKey === `cu-move-${k}`} />
                        ) : null}
                      </Confirm>
                    </div>
                  ) : null}
                  {isPending('remove-all', u.uuid) ? (
                    <div style={{ flex: '1 1 100%' }}>
                      <Confirm id={`aic-ca-c-${k}`} title={`Remove ${who} from all ${plural(u.companies.length, 'company', 'companies')}?`} confirmLabel="Remove from all companies" danger busy={busyKey === `cu-${k}`}
                        onCancel={() => setPending(null)}
                        onConfirm={() => void act(`cu-${k}`, () => client.removeMember(ws.workspace_id, u.uuid), `${who} no longer has access to any company of yours.`)}>
                        <span>They lose access to every company of yours shared with them:</span>
                        <CompanyList companies={u.companies} label={`Companies ${who} loses`} />
                      </Confirm>
                    </div>
                  ) : null}
                </li>
              );
            })}
            {companyUsers.length === 0 ? (
              <li className="aic-ca-item aic-ca-meta" style={{ cursor: 'default' }}>Nobody yet. Share a company with someone who is not on your team and they appear here.</li>
            ) : null}
          </ul>
        </section>
      ) : null}

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
    </div>
  );
}
