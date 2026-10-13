import { useState } from 'react';
import type { CompanyRegistryClient, DirectoryCompany } from './companyRegistryClient.ts';
import { CompanyAccessBadge } from './CompanyAccessBadge.tsx';
import { RestrictedCompanyDialog } from './RestrictedCompanyDialog.tsx';
import { isOpenable } from './accessStatus.ts';
import { useCompanyDirectory } from './useCompanyDirectory.ts';
import { injectCompanyAccessStyles } from './styles.ts';

export interface CompanyDirectoryPickerProps {
  client: CompanyRegistryClient;
  /** The product's name as people know it: "Aicountly Books", "Sales", … */
  appName: string;
  /** Called only for a company the person may open here. */
  onOpen: (company: DirectoryCompany) => void;
  selectedCmpId?: number | null;
  /** Product roles a requester may ask for (optional). */
  roles?: Array<{ key: string; label: string }>;
  /** Heading; pass null to hide it (when the host renders its own). */
  title?: string | null;
  /** Where "Create company" should go (Manage's create page); omit to hide the link. */
  createCompanyHref?: string | null;
}

type Filter = 'all' | 'granted' | 'restricted';

/**
 * Select Company — every company the person can discover, with their access in THIS application.
 * A restricted company is listed (so nobody concludes it does not exist and registers it again)
 * and opens an explanation with "Request access", never the company itself.
 */
export function CompanyDirectoryPicker({ client, appName, onOpen, selectedCmpId, roles, title, createCompanyHref }: CompanyDirectoryPickerProps) {
  injectCompanyAccessStyles();
  const [q, setQ] = useState('');
  const [filter, setFilter] = useState<Filter>('all');
  const [restricted, setRestricted] = useState<DirectoryCompany | null>(null);
  const dir = useCompanyDirectory(client, { q: q.trim() || undefined, access: filter });

  const choose = (company: DirectoryCompany) => {
    if (isOpenable(company)) onOpen(company);
    else setRestricted(company);
  };

  return (
    <div className="aic-ca aic-ca-stack">
      {title !== null ? (
        <div>
          <h2 className="aic-ca-title">{title ?? `Select Company — ${appName}`}</h2>
          <p className="aic-ca-sub">Every company registered in your workspaces. Restricted companies exist — ask the owner for access instead of creating them again.</p>
        </div>
      ) : null}
      <div className="aic-ca-row">
        <input
          className="aic-ca-input"
          style={{ flex: '1 1 220px' }}
          type="search"
          placeholder="Search by name or #id"
          aria-label="Search companies"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <select className="aic-ca-select" style={{ width: 'auto' }} aria-label="Filter by access" value={filter} onChange={(e) => setFilter(e.target.value as Filter)}>
          <option value="all">All companies</option>
          <option value="granted">I can open</option>
          <option value="restricted">Restricted</option>
        </select>
      </div>
      {dir.error ? (
        <div className="aic-ca-alert aic-ca-tone-danger aic-ca-between" role="alert">
          <span>{dir.error.message}</span>
          <button type="button" className="aic-ca-btn aic-ca-btn-sm" onClick={dir.reload}>Retry</button>
        </div>
      ) : null}
      {dir.companies.length === 0 && !dir.loading && !dir.error ? (
        <div className="aic-ca-empty">
          {q.trim() ? (
            <>
              <div>No company matches “{q.trim()}” in your workspaces.</div>
              <div className="aic-ca-meta" style={{ marginTop: 6 }}>
                If it belongs to someone else's workspace, ask its owner to add you. Search before creating it, so it is registered only once.
              </div>
            </>
          ) : (
            'No companies yet.'
          )}
          {createCompanyHref ? (
            <div style={{ marginTop: 10 }}>
              <a className="aic-ca-btn aic-ca-btn-primary" href={createCompanyHref}>Register a company</a>
            </div>
          ) : null}
        </div>
      ) : (
        <ul className="aic-ca-list" aria-busy={dir.loading}>
          {dir.companies.map((c) => {
            const open = isOpenable(c);
            return (
              <li key={c.cmp_id}>
                <button
                  type="button"
                  className={`aic-ca-item${open ? '' : ' aic-ca-item-restricted'}`}
                  aria-current={selectedCmpId === c.cmp_id ? 'true' : undefined}
                  onClick={() => choose(c)}
                  title={open ? `Open ${c.legal_name}` : `${c.legal_name} — access restricted in ${appName}`}
                >
                  <span style={{ minWidth: 0 }}>
                    <span className="aic-ca-name" style={{ display: 'block' }}>{c.legal_name}</span>
                    <span className="aic-ca-meta">
                      #{c.cmp_id}
                      {c.workspace ? ` · ${c.workspace.workspace_name}` : ''}
                      {c.relationship === 'workspace' ? ' · workspace' : ''}
                    </span>
                  </span>
                  <CompanyAccessBadge status={c.access_status} />
                </button>
              </li>
            );
          })}
        </ul>
      )}
      <div className="aic-ca-between">
        <span className="aic-ca-meta">{dir.loading ? 'Loading…' : dir.meta ? `${dir.meta.total} compan${dir.meta.total === 1 ? 'y' : 'ies'}` : ''}</span>
        {dir.hasMore ? (
          <button type="button" className="aic-ca-btn aic-ca-btn-sm" onClick={dir.loadMore} disabled={dir.loading}>Load more</button>
        ) : null}
      </div>
      {restricted ? (
        <RestrictedCompanyDialog
          client={client}
          company={restricted}
          appName={appName}
          roles={roles}
          onClose={() => setRestricted(null)}
          onRequested={(next) => dir.update(next)}
        />
      ) : null}
    </div>
  );
}
