import type { DuplicateMatch } from './companyRegistryClient.ts';
import { CompanyAccessBadge } from './CompanyAccessBadge.tsx';
import { injectCompanyAccessStyles } from './styles.ts';

export interface DuplicateCompanyNoticeProps {
  matches: DuplicateMatch[];
  /** Shown for "confirm" matches: the person states this is a different legal entity. */
  acknowledged?: boolean;
  onAcknowledge?: (value: boolean) => void;
  /** Request access to an existing company instead (opens the host's request flow). */
  onRequestAccess?: (match: DuplicateMatch) => void;
}

/**
 * "This company may already be registered" — shown on a create-company form after
 * client.duplicateCheck(). Lists only companies the person can already discover.
 */
export function DuplicateCompanyNotice({ matches, acknowledged, onAcknowledge, onRequestAccess }: DuplicateCompanyNoticeProps) {
  injectCompanyAccessStyles();
  if (matches.length === 0) return null;
  const blocking = matches.some((m) => m.severity === 'block');
  return (
    <div className={`aic-ca aic-ca-alert aic-ca-tone-${blocking ? 'danger' : 'warning'} aic-ca-stack`} role="alert">
      <strong>{blocking ? 'This company is already registered.' : 'A company with this name is already registered.'}</strong>
      <ul className="aic-ca-list">
        {matches.map((m) => (
          <li key={m.cmp_id} className="aic-ca-item" style={{ cursor: 'default' }}>
            <span>
              <span className="aic-ca-name">{m.legal_name}</span>
              <span className="aic-ca-meta">
                {' '}· #{m.cmp_id} · {m.match === 'identifier' ? `same ${m.matched_on}` : 'same name'}
                {m.workspace ? ` · ${m.workspace.workspace_name}` : ''}
              </span>
            </span>
            <span className="aic-ca-row">
              <CompanyAccessBadge status={m.access_status} />
              {onRequestAccess && !m.is_owner && m.access_status !== 'ACCESS_GRANTED' ? (
                <button type="button" className="aic-ca-btn aic-ca-btn-sm" onClick={() => onRequestAccess(m)}>Request access</button>
              ) : null}
            </span>
          </li>
        ))}
      </ul>
      {blocking ? (
        <span>Ask the owner for access instead of registering it again.</span>
      ) : onAcknowledge ? (
        <label className="aic-ca-row">
          <input type="checkbox" checked={acknowledged === true} onChange={(e) => onAcknowledge(e.target.checked)} />
          This is a different legal entity — create it anyway.
        </label>
      ) : null}
    </div>
  );
}
