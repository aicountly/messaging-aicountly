import { useEffect, useRef, useState } from 'react';
import type { CompanyRegistryClient, DirectoryCompany } from './companyRegistryClient.ts';
import { CompanyRegistryError } from './companyRegistryClient.ts';
import { CompanyAccessBadge } from './CompanyAccessBadge.tsx';
import { restrictedExplanation } from './accessStatus.ts';
import { injectCompanyAccessStyles } from './styles.ts';

export interface RestrictedCompanyDialogProps {
  client: CompanyRegistryClient;
  company: DirectoryCompany;
  appName: string;
  /** Optional product roles a requester may ask for. */
  roles?: Array<{ key: string; label: string }>;
  onClose: () => void;
  /** Called after a request was sent (or found already open), with the updated company row. */
  onRequested?: (company: DirectoryCompany) => void;
}

/**
 * "Company Access Restricted" — shown instead of opening a company the person can see but not
 * open in this application. No company data is loaded behind it.
 */
export function RestrictedCompanyDialog({ client, company, appName, roles, onClose, onRequested }: RestrictedCompanyDialogProps) {
  injectCompanyAccessStyles();
  const [justification, setJustification] = useState('');
  const [role, setRole] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [current, setCurrent] = useState<DirectoryCompany>(company);
  const closeRef = useRef<HTMLButtonElement | null>(null);

  useEffect(() => {
    closeRef.current?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const requested = current.access_status === 'ACCESS_REQUESTED';
  const canRequest = current.can_request_access && !requested;

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await client.requestAccess(current.cmp_id, { justification: justification.trim() || undefined, requested_role: role || null });
      const next: DirectoryCompany = { ...current, access_status: 'ACCESS_REQUESTED', can_request_access: false };
      setCurrent(next);
      onRequested?.(next);
    } catch (e) {
      setError(e instanceof CompanyRegistryError ? e.message : 'The request could not be sent. Please try again.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="aic-ca aic-ca-overlay" role="presentation" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="aic-ca-dialog aic-ca-stack" role="dialog" aria-modal="true" aria-labelledby="aic-ca-restricted-title">
        <div className="aic-ca-between">
          <h2 className="aic-ca-title" id="aic-ca-restricted-title">
            {requested ? 'Access requested' : 'Company Access Restricted'}
          </h2>
          <CompanyAccessBadge status={current.access_status} />
        </div>
        <div>
          <div className="aic-ca-name">{current.legal_name}</div>
          {current.workspace ? <div className="aic-ca-meta">{current.workspace.workspace_name}</div> : null}
        </div>
        <p className="aic-ca-sub" style={{ color: 'inherit' }}>{restrictedExplanation(current, appName)}</p>
        {requested && current.request ? (
          <p className="aic-ca-meta">Requested on {new Date(current.request.created_at).toLocaleString()}.</p>
        ) : null}
        {canRequest ? (
          <div className="aic-ca-stack">
            {roles && roles.length > 0 ? (
              <label className="aic-ca-stack" style={{ gap: 4 }}>
                <span className="aic-ca-meta">Access you need (optional)</span>
                <select className="aic-ca-select" value={role} onChange={(e) => setRole(e.target.value)}>
                  <option value="">Let the owner decide</option>
                  {roles.map((r) => (
                    <option key={r.key} value={r.key}>{r.label}</option>
                  ))}
                </select>
              </label>
            ) : null}
            <label className="aic-ca-stack" style={{ gap: 4 }}>
              <span className="aic-ca-meta">Why do you need access? (optional)</span>
              <textarea className="aic-ca-textarea" rows={3} maxLength={1000} value={justification} onChange={(e) => setJustification(e.target.value)} />
            </label>
          </div>
        ) : null}
        {error ? <div className="aic-ca-alert aic-ca-tone-danger" role="alert">{error}</div> : null}
        <div className="aic-ca-row" style={{ justifyContent: 'flex-end' }}>
          <button type="button" className="aic-ca-btn" onClick={onClose} ref={closeRef}>Return to company list</button>
          {canRequest ? (
            <button type="button" className="aic-ca-btn aic-ca-btn-primary" onClick={submit} disabled={busy}>
              {busy ? 'Sending…' : 'Request access'}
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}
