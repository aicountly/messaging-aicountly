import { useCallback, useEffect, useRef, useState } from 'react';
import type { CompanyRegistryClient, DirectoryCompany, DirectoryMeta, DirectoryParams } from './companyRegistryClient.ts';
import { CompanyRegistryError } from './companyRegistryClient.ts';

export interface CompanyDirectoryState {
  companies: DirectoryCompany[];
  meta: DirectoryMeta | null;
  loading: boolean;
  error: CompanyRegistryError | null;
  hasMore: boolean;
  reload: () => void;
  loadMore: () => void;
  /** Replace one row in place (after a request was sent, for example). */
  update: (company: DirectoryCompany) => void;
}

/**
 * The person's company directory for this application, paged, with a debounced search. One request
 * per page — never one per company — and stale answers are dropped when the search changes.
 */
export function useCompanyDirectory(client: CompanyRegistryClient | null, params: DirectoryParams = {}, debounceMs = 250): CompanyDirectoryState {
  const [companies, setCompanies] = useState<DirectoryCompany[]>([]);
  const [meta, setMeta] = useState<DirectoryMeta | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<CompanyRegistryError | null>(null);
  const [page, setPage] = useState(1);
  const [nonce, setNonce] = useState(0);
  const seq = useRef(0);
  const key = JSON.stringify(params);

  useEffect(() => {
    setPage(1);
  }, [key]);

  useEffect(() => {
    if (!client) return undefined;
    const mySeq = ++seq.current;
    const parsed = JSON.parse(key) as DirectoryParams;
    const timer = setTimeout(() => {
      setLoading(true);
      setError(null);
      client
        .directory({ per_page: 50, ...parsed, page })
        .then((r) => {
          if (mySeq !== seq.current) return;
          setMeta(r.meta);
          setCompanies((prev) => (page === 1 ? r.data : [...prev, ...r.data.filter((c) => !prev.some((p) => p.cmp_id === c.cmp_id))]));
        })
        .catch((e: unknown) => {
          if (mySeq !== seq.current) return;
          setError(e instanceof CompanyRegistryError ? e : new CompanyRegistryError(0, 'request_failed', 'The company list could not be loaded.'));
        })
        .finally(() => {
          if (mySeq === seq.current) setLoading(false);
        });
    }, page === 1 ? debounceMs : 0);
    return () => clearTimeout(timer);
  }, [client, key, page, nonce, debounceMs]);

  const reload = useCallback(() => {
    setPage(1);
    setNonce((n) => n + 1);
  }, []);
  const loadMore = useCallback(() => setPage((p) => p + 1), []);
  const update = useCallback((company: DirectoryCompany) => {
    setCompanies((prev) => prev.map((c) => (c.cmp_id === company.cmp_id ? company : c)));
  }, []);

  return {
    companies,
    meta,
    loading,
    error,
    hasMore: meta !== null && meta.page < meta.total_pages,
    reload,
    loadMore,
    update,
  };
}
