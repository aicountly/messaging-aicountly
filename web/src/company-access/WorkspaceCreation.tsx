import type { AppRoles, CreationTarget, CreatorAccessChoice, WorkspaceSettings } from './companyRegistryClient.ts';
import { injectCompanyAccessStyles } from './styles.ts';

/** Display names for the applications with a role catalogue (v1.2.0). */
const APP_LABELS: Record<string, string> = {
  books: 'Smart Books',
  inventory: 'Inventory',
  billing: 'Billing',
  docs: 'Drive',
  notes: 'Notes',
};

export function appLabel(key: string): string {
  return APP_LABELS[key] ?? key.charAt(0).toUpperCase() + key.slice(1);
}

function ownerName(t: CreationTarget): string {
  return (t.owner?.name ?? '').trim() || 'the workspace owner';
}

export interface WorkspaceTargetPickerProps {
  targets: CreationTarget[];
  /** The chosen workspace_id (null = none chosen yet). */
  value: number | null;
  onChange: (workspaceId: number) => void;
  disabled?: boolean;
}

/**
 * "Where does this company live?" — the person's own workspace first, then the workspaces shared
 * with them. A workspace they may not create in is listed but cannot be chosen, with the reason.
 * Hidden by the host when there is only one target (their own).
 */
export function WorkspaceTargetPicker({ targets, value, onChange, disabled }: WorkspaceTargetPickerProps) {
  injectCompanyAccessStyles();
  return (
    <div className="aic-ca aic-ca-stack" role="radiogroup" aria-label="Workspace for the new company">
      <ul className="aic-ca-list">
        {targets.map((t) => {
          const chosen = value === t.workspace_id;
          const blocked = !t.can_create;
          const why = t.reason === 'plan_limit_reached'
            ? (t.is_own
              ? 'Your plan\'s company limit is reached. Upgrade in Plans & billing to add more.'
              : `${ownerName(t)}'s plan allows no more companies.`)
            : t.reason === 'limit_reached'
              ? `You have created as many companies here as ${ownerName(t)} allows.`
              : `${ownerName(t)} has not allowed you to create companies here.`;
          return (
            <li key={t.workspace_id}>
              <label
                className="aic-ca-item"
                aria-current={chosen ? 'true' : undefined}
                style={{ cursor: blocked || disabled ? 'not-allowed' : 'pointer', opacity: blocked ? 0.6 : 1 }}
              >
                <span className="aic-ca-row" style={{ minWidth: 0, flexWrap: 'nowrap' }}>
                  <input
                    type="radio"
                    name="aic-ca-workspace-target"
                    checked={chosen}
                    disabled={blocked || disabled}
                    onChange={() => onChange(t.workspace_id)}
                  />
                  <span style={{ minWidth: 0 }}>
                    <span className="aic-ca-name" style={{ display: 'block' }}>{t.name}</span>
                    <span className="aic-ca-meta" style={{ display: 'block' }}>
                      {t.is_own ? 'Your workspace · you will own the company' : `Owned by ${ownerName(t)} · they will own the company`}
                      {t.companies_remaining != null && !t.is_own ? ` · ${t.companies_remaining} left` : ''}
                    </span>
                    {blocked ? <span className="aic-ca-meta" style={{ display: 'block' }}>{why}</span> : null}
                  </span>
                </span>
                {t.is_own ? <span className="aic-ca-badge aic-ca-tone-success">Yours</span> : <span className="aic-ca-badge aic-ca-tone-info">Shared</span>}
              </label>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

export interface CreatorAccessFieldsProps {
  /** The chosen target. Renders nothing for one's own workspace (the owner holds everything). */
  target: CreationTarget | null | undefined;
  value: CreatorAccessChoice[];
  onChange: (next: CreatorAccessChoice[]) => void;
  disabled?: boolean;
}

/**
 * "Your access" — for a company created in someone else's workspace: which applications, and which
 * role in each, within what the workspace owner allows. The server checks the same rules again.
 */
export function CreatorAccessFields({ target, value, onChange, disabled }: CreatorAccessFieldsProps) {
  injectCompanyAccessStyles();
  if (!target || target.is_own) return null;
  const roles = target.creator_roles ?? {};
  const apps = Object.keys(roles).filter((a) => (roles[a] ?? []).length > 0);
  const chosen = new Map(value.map((c) => [c.app, c.role_key]));

  const set = (app: string, role: string | null) => {
    const rest = value.filter((c) => c.app !== app);
    onChange(role ? [...rest, { app, role_key: role }] : rest);
  };

  return (
    <div className="aic-ca aic-ca-stack">
      <div className="aic-ca-alert aic-ca-tone-info" role="note">
        This company will be owned by <strong>{ownerName(target)}</strong>. Their plan applies. You will get the access you choose below.
      </div>
      {apps.length === 0 ? (
        <div className="aic-ca-alert aic-ca-tone-warning" role="alert">
          {ownerName(target)} has not allowed you any access on companies you create here, so you cannot create one in this workspace.
        </div>
      ) : (
        <ul className="aic-ca-list" aria-label="Your access on the new company">
          {apps.map((app) => {
            const on = chosen.has(app);
            const options = roles[app] ?? [];
            return (
              <li key={app} className="aic-ca-item" style={{ cursor: 'default' }}>
                <label className="aic-ca-row" style={{ flexWrap: 'nowrap' }}>
                  <input
                    type="checkbox"
                    checked={on}
                    disabled={disabled}
                    onChange={(e) => set(app, e.target.checked ? (chosen.get(app) ?? options[0]?.key ?? null) : null)}
                  />
                  <span className="aic-ca-name">{appLabel(app)}</span>
                </label>
                <select
                  className="aic-ca-select"
                  style={{ maxWidth: 220 }}
                  aria-label={`Your role in ${appLabel(app)}`}
                  disabled={disabled || !on}
                  value={chosen.get(app) ?? ''}
                  onChange={(e) => set(app, e.target.value || null)}
                >
                  {!on ? <option value="">No access</option> : null}
                  {options.map((r) => (
                    <option key={r.key} value={r.key}>{r.label}</option>
                  ))}
                </select>
              </li>
            );
          })}
        </ul>
      )}
      {apps.length > 0 && value.length === 0 ? (
        <p className="aic-ca-meta">Choose at least one application — otherwise you could not open the company you create.</p>
      ) : null}
    </div>
  );
}

export interface WorkspaceMemberSettingsFormProps {
  value: WorkspaceSettings;
  onChange: (next: WorkspaceSettings) => void;
  /** GET v1/app-roles (owner never listed). */
  appRoles: AppRoles;
  disabled?: boolean;
}

/**
 * What one Workspace team member may do in the owner's workspace (CONTRACTS §1; v1.3.0): their app
 * permissions on every company of the workspace, and whether they may create companies there (you
 * will own them; your plan applies), how many, and which roles they may give themselves on those.
 * Nothing chosen = they open every company in Manage, and in no application yet.
 */
export function WorkspaceMemberSettingsForm({ value, onChange, appRoles, disabled }: WorkspaceMemberSettingsFormProps) {
  injectCompanyAccessStyles();
  const apps = Object.keys(appRoles);
  const patch = (p: Partial<WorkspaceSettings>) => onChange({ ...value, ...p });
  const toggleCreatorRole = (app: string, role: string, on: boolean) => {
    const current = new Set(value.creator_roles[app] ?? []);
    if (on) current.add(role);
    else current.delete(role);
    const next = { ...value.creator_roles };
    if (current.size > 0) next[app] = (appRoles[app] ?? []).map((r) => r.key).filter((k) => current.has(k));
    else delete next[app];
    patch({ creator_roles: next });
  };
  const setWide = (app: string, role: string) => {
    const next = { ...value.all_companies_access };
    if (role) next[app] = role;
    else delete next[app];
    patch({ all_companies_access: next });
  };

  return (
    <fieldset className="aic-ca aic-ca-stack" style={{ border: 0, padding: 0, margin: 0 }} disabled={disabled}>
      <label className="aic-ca-row">
        <input
          type="checkbox"
          checked={value.can_create_companies}
          onChange={(e) => patch({ can_create_companies: e.target.checked })}
        />
        <span>
          <span className="aic-ca-name">May create companies in your workspace</span>
          <span className="aic-ca-meta" style={{ display: 'block' }}>You will own every company they create, and your plan applies to it.</span>
        </span>
      </label>
      {value.can_create_companies ? (
        <div className="aic-ca-stack" style={{ paddingLeft: 24 }}>
          <label className="aic-ca-row">
            <span className="aic-ca-meta">At most</span>
            <input
              className="aic-ca-input"
              style={{ width: 90 }}
              type="number"
              min={0}
              aria-label="Most companies they may create"
              placeholder="No limit"
              value={value.max_companies_created ?? ''}
              onChange={(e) => patch({ max_companies_created: e.target.value === '' ? null : Math.max(0, Math.floor(Number(e.target.value))) })}
            />
            <span className="aic-ca-meta">companies (empty: only your plan's limit)</span>
          </label>
          <div className="aic-ca-meta">Roles they may give themselves on companies they create:</div>
          {apps.map((app) => (
            <div key={app} className="aic-ca-row" role="group" aria-label={`Roles in ${appLabel(app)}`}>
              <span className="aic-ca-name" style={{ minWidth: 100 }}>{appLabel(app)}</span>
              {(appRoles[app] ?? []).map((r) => (
                <label key={r.key} className="aic-ca-chip" style={{ cursor: 'pointer' }}>
                  <input
                    type="checkbox"
                    checked={(value.creator_roles[app] ?? []).includes(r.key)}
                    onChange={(e) => toggleCreatorRole(app, r.key, e.target.checked)}
                  />{' '}
                  {r.label}
                </label>
              ))}
            </div>
          ))}
        </div>
      ) : null}
      <div className="aic-ca-stack">
        <div>
          <span className="aic-ca-name">App permissions on every company in your workspace</span>
          <span className="aic-ca-meta" style={{ display: 'block' }}>Applies to all your companies (except ones you hide), now and later. Ends at once if they leave, you move them to Company users or you remove them.</span>
        </div>
        {apps.map((app) => (
          <label key={app} className="aic-ca-row">
            <span className="aic-ca-name" style={{ minWidth: 100 }}>{appLabel(app)}</span>
            <select
              className="aic-ca-select"
              style={{ maxWidth: 220 }}
              aria-label={`Access to every company in ${appLabel(app)}`}
              value={value.all_companies_access[app] ?? ''}
              onChange={(e) => setWide(app, e.target.value)}
            >
              <option value="">None</option>
              {(appRoles[app] ?? []).map((r) => (
                <option key={r.key} value={r.key}>{r.label}</option>
              ))}
            </select>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

/** A one-line summary of a Workspace team member's settings, for lists (v1.3.0: they open every company). */
export function workspaceSettingsSummary(s: WorkspaceSettings | null | undefined): string {
  if (!s) return '';
  const parts: string[] = [];
  const wide = Object.entries(s.all_companies_access ?? {});
  parts.push(wide.length > 0 ? `every company in ${wide.map(([a]) => appLabel(a)).join(', ')}` : 'every company in Manage only (no app permissions yet)');
  if (s.can_create_companies) {
    parts.push(s.max_companies_created != null ? `may create up to ${s.max_companies_created} compan${s.max_companies_created === 1 ? 'y' : 'ies'}` : 'may create companies');
  }
  return parts.join(' · ');
}
