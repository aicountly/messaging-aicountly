/**
 * AICOUNTLY company registry client (Manage /api/v1/*) — shared by every product.
 *
 * Copied verbatim into each product (see README.md). Do not edit a product's copy: change it here
 * and copy it again, so every product talks to the registry the same way.
 *
 * The registry answers two different questions and never conflates them:
 *   directory()  which companies may this person SEE (owned, shared, and every workspace they
 *                actively belong to) — with their access status in THIS application;
 *   access()     may they OPEN this company here. Products still enforce this server-side through
 *                Manage companyinfo with their application key; this call only drives the UI.
 */

export type AccessStatus =
  | 'OWNER'
  | 'ACCESS_GRANTED'
  | 'ACCESS_RESTRICTED'
  | 'ACCESS_REQUESTED'
  | 'ACCESS_PENDING'
  | 'ACCESS_EXPIRED'
  | 'ACCESS_REVOKED'
  | 'SUBSCRIPTION_REQUIRED'
  | 'COMPANY_ARCHIVED'
  | 'WORKSPACE_MEMBERSHIP_INACTIVE';

export interface DirectoryCompany {
  canonical_company_id: number;
  cmp_id: number;
  legal_name: string;
  display_name: string;
  company_code: string;
  company_status: 'active' | 'archived';
  relationship: 'owner' | 'member' | 'workspace';
  workspace: { workspace_id: number; workspace_name: string } | null;
  is_owner: boolean;
  is_discoverable: boolean;
  workspace_visibility: 'discoverable' | 'hidden' | null;
  app: string | null;
  access_status: AccessStatus;
  can_open_in_current_app: boolean;
  can_request_access: boolean;
  can_manage_access: boolean;
  request: { request_id: number; status: string; created_at: string } | null;
  entitlement_version: number;
  entitlement?: Entitlement | null;
}

export interface DirectoryMeta {
  app: string | null;
  app_name: string | null;
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export interface Entitlement {
  grant_id: number | null;
  uuid: string;
  app_key: string;
  app_name: string;
  role_key: string | null;
  state: 'active' | 'expired' | 'revoked';
  granted_by: string | null;
  granted_at: string | null;
  revoked_by: string | null;
  revoked_at: string | null;
  expires_at: string | null;
}

export interface PermissionMember {
  uuid: string;
  name: string | null;
  email: string | null;
  relationship: 'owner' | 'member' | 'workspace';
  is_owner: boolean;
  legacy_co_owner: boolean;
  app_scope: 'all' | 'restricted' | 'none';
  entitlement_version: number;
  member_since: string | null;
  books_profile: string | null;
  entitlements: Entitlement[];
  app_access_status?: AccessStatus;
}

export interface CompanyPermissions {
  company: { cmp_id: number; name: string; company_status: string };
  is_owner: boolean;
  can_manage_access: boolean;
  app: string | null;
  applications: Array<{ key: string; name: string }>;
  members: PermissionMember[];
}

export interface AccessRequest {
  request_id: number;
  cmp_id: number;
  company_name: string | null;
  workspace_id: number | null;
  requester_uuid: string;
  requester_name: string | null;
  requester_email: string | null;
  app_key: string;
  app_name: string;
  requested_role: string | null;
  justification: string | null;
  status: 'pending' | 'approved' | 'rejected' | 'cancelled';
  reviewed_by: string | null;
  reviewed_at: string | null;
  review_note: string | null;
  created_at: string;
}

export interface Workspace {
  workspace_id: number;
  workspace_name: string;
  is_owner: boolean;
  membership_status: 'invited' | 'active';
  membership_type: 'owner' | 'member';
  invited_at: string | null;
  accepted_at: string | null;
  company_count: number | null;
  member_count: number | null;
}

export interface WorkspaceMember {
  membership_id: number;
  uuid: string;
  membership_type: 'owner' | 'member';
  status: 'invited' | 'active' | 'declined' | 'exited' | 'removed';
  name: string | null;
  email: string | null;
  invited_at: string | null;
  accepted_at: string | null;
}

export interface MemberSuggestion {
  uuid: string;
  name: string | null;
  email: string | null;
  shared_company_count: number;
}

export interface AuditEntry {
  audit_id: number;
  occurred_at: string;
  actor_uuid: string;
  operation: string;
  cmp_id: number | null;
  workspace_id: number | null;
  target_uuid: string | null;
  app_key: string | null;
  before: unknown;
  after: unknown;
  reason: string | null;
  correlation_id: string | null;
}

export interface DuplicateMatch {
  cmp_id: number;
  legal_name: string;
  is_owner: boolean;
  relationship: string;
  workspace: { workspace_id: number; workspace_name: string } | null;
  access_status: AccessStatus;
  match: 'identifier' | 'name';
  matched_on: string;
  severity: 'block' | 'confirm';
}

export interface DirectoryParams {
  q?: string;
  workspace_id?: number;
  access?: 'all' | 'granted' | 'restricted';
  include_archived?: boolean;
  page?: number;
  per_page?: number;
}

export class CompanyRegistryError extends Error {
  readonly status: number;
  readonly code: string;
  readonly details: Record<string, unknown> | null;

  constructor(status: number, code: string, message: string, details: Record<string, unknown> | null = null) {
    super(message);
    this.name = 'CompanyRegistryError';
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

export type RegistryFetch = (url: string, init: RequestInit) => Promise<Response>;

export interface CompanyRegistryClientOptions {
  /** This product's Manage catalog id: books, sales, purchases, inventory, hrms, docs (Drive), chat (Connect)… */
  appKey: string;
  /** Manage API base, e.g. https://manage.aicountly.com/api. Default: resolved from the page's host. */
  baseUrl?: string;
  /** The signed-in person's my.aicountly ses_key (sent as Bearer). Ignored when fetchImpl is given. */
  getSesKey?: () => string | null | undefined | Promise<string | null | undefined>;
  /** The product's own authenticated fetch (session refresh, proxy). Receives absolute URLs. */
  fetchImpl?: RegistryFetch;
}

/** Manage's API base for the page this code runs on (sandbox hosts → manage.gh). */
export function resolveManageApiBase(hostname?: string): string {
  const host = (hostname ?? (typeof window !== 'undefined' ? window.location.hostname : '')).toLowerCase();
  const sandbox =
    host === '' ||
    host === 'localhost' ||
    host.startsWith('127.') ||
    host.endsWith('.gh.aicountly.com') ||
    /^gh-[a-z0-9-]+\.aicountly\.com$/.test(host);
  return sandbox ? 'https://manage.gh.aicountly.com/api' : 'https://manage.aicountly.com/api';
}

function newCorrelationId(): string {
  const bytes = new Uint8Array(12);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < bytes.length; i += 1) bytes[i] = Math.floor(Math.random() * 256);
  }
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

function query(params: Record<string, string | number | boolean | undefined | null>): string {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '' || v === false) continue;
    q.set(k, String(v));
  }
  const s = q.toString();
  return s ? `?${s}` : '';
}

interface Envelope<T> {
  success?: boolean;
  data: T;
  meta?: DirectoryMeta;
}

export interface CompanyRegistryClient {
  readonly appKey: string;
  readonly baseUrl: string;
  applications(): Promise<Array<{ key: string; name: string }>>;
  directory(params?: DirectoryParams): Promise<{ data: DirectoryCompany[]; meta: DirectoryMeta }>;
  summary(cmpId: number): Promise<DirectoryCompany>;
  access(cmpId: number, appKey?: string): Promise<DirectoryCompany>;
  permissions(cmpId: number, appKey?: string | null): Promise<CompanyPermissions>;
  grant(cmpId: number, body: { uuid: string; app?: string; role_key?: string | null; expires_at?: string | null; reason?: string }): Promise<{ entitlement: Entitlement; changed: boolean }>;
  updateGrant(cmpId: number, grantRef: string, body: { role_key?: string | null; expires_at?: string | null; reason?: string }): Promise<{ entitlement: Entitlement }>;
  revoke(cmpId: number, grantRef: string, reason?: string): Promise<{ entitlement: Entitlement; materialized: string[] }>;
  setVisibility(cmpId: number, visibility: 'discoverable' | 'hidden'): Promise<{ cmp_id: number; workspace_visibility: string }>;
  accessAudit(cmpId: number, page?: number): Promise<AuditEntry[]>;
  requestAccess(cmpId: number, body: { app?: string; requested_role?: string | null; justification?: string }): Promise<{ request: AccessRequest; created: boolean }>;
  companyRequests(cmpId: number, status?: string): Promise<AccessRequest[]>;
  inbox(role: 'mine' | 'owner', status?: string): Promise<AccessRequest[]>;
  approveRequest(requestId: number, body?: { role_key?: string | null; expires_at?: string | null; note?: string }): Promise<{ request: AccessRequest }>;
  rejectRequest(requestId: number, note?: string): Promise<{ request: AccessRequest }>;
  cancelRequest(requestId: number): Promise<{ request: AccessRequest }>;
  workspaces(): Promise<Workspace[]>;
  workspaceMembers(workspaceId: number): Promise<WorkspaceMember[]>;
  memberSuggestions(workspaceId: number): Promise<MemberSuggestion[]>;
  inviteMember(workspaceId: number, body: { uuid: string; name?: string | null; email?: string | null }): Promise<{ changed: boolean }>;
  removeMember(workspaceId: number, uuid: string, revokeCompanyAccess?: boolean): Promise<unknown>;
  respondToWorkspace(workspaceId: number, action: 'accept' | 'decline' | 'exit'): Promise<unknown>;
  duplicateCheck(body: { comp_name?: string; pan?: string; cin?: string; llpin?: string; gstins?: string[] }): Promise<{ matches: DuplicateMatch[]; can_create: boolean; needs_acknowledgement: boolean }>;
}

export function createCompanyRegistryClient(options: CompanyRegistryClientOptions): CompanyRegistryClient {
  const appKey = options.appKey;
  const baseUrl = (options.baseUrl ?? resolveManageApiBase()).replace(/\/+$/, '');

  async function call<T>(method: string, path: string, body?: unknown): Promise<Envelope<T>> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Correlation-Id': newCorrelationId(),
    };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const init: RequestInit = { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined };
    let res: Response;
    try {
      if (options.fetchImpl) {
        res = await options.fetchImpl(`${baseUrl}${path}`, init);
      } else {
        const key = options.getSesKey ? await options.getSesKey() : null;
        if (key) headers.Authorization = `Bearer ${key}`;
        res = await fetch(`${baseUrl}${path}`, init);
      }
    } catch {
      throw new CompanyRegistryError(0, 'network_error', 'Manage could not be reached. Check your connection and try again.');
    }
    let json: unknown = null;
    try {
      json = await res.json();
    } catch {
      json = null;
    }
    if (!res.ok) {
      const j = (json ?? {}) as { code?: string; message?: string; error?: { code?: string; message?: string; details?: Record<string, unknown> } };
      throw new CompanyRegistryError(
        res.status,
        j.code ?? j.error?.code ?? (res.status === 401 ? 'unauthorized' : 'request_failed'),
        j.message ?? j.error?.message ?? `Manage answered ${res.status}.`,
        j.error?.details ?? null,
      );
    }
    return (json ?? { data: null }) as Envelope<T>;
  }

  const enc = encodeURIComponent;

  return {
    appKey,
    baseUrl,
    applications: async () => (await call<Array<{ key: string; name: string }>>('GET', '/v1/applications')).data,
    directory: async (params = {}) => {
      const r = await call<DirectoryCompany[]>('GET', `/v1/company-directory${query({ app: appKey, ...params })}`);
      return { data: r.data ?? [], meta: r.meta ?? { app: appKey, app_name: null, page: 1, per_page: 50, total: 0, total_pages: 1 } };
    },
    summary: async (cmpId) => (await call<DirectoryCompany>('GET', `/v1/companies/${cmpId}/summary${query({ app: appKey })}`)).data,
    access: async (cmpId, app) => (await call<DirectoryCompany>('GET', `/v1/companies/${cmpId}/access${query({ app: app ?? appKey })}`)).data,
    permissions: async (cmpId, app) =>
      (await call<CompanyPermissions>('GET', `/v1/companies/${cmpId}/permissions${query({ app: app === null ? undefined : (app ?? appKey) })}`)).data,
    grant: async (cmpId, body) => (await call<{ entitlement: Entitlement; changed: boolean }>('POST', `/v1/companies/${cmpId}/permissions`, { app: appKey, ...body })).data,
    updateGrant: async (cmpId, grantRef, body) => (await call<{ entitlement: Entitlement }>('PATCH', `/v1/companies/${cmpId}/permissions/${enc(grantRef)}`, body)).data,
    revoke: async (cmpId, grantRef, reason) =>
      (await call<{ entitlement: Entitlement; materialized: string[] }>('DELETE', `/v1/companies/${cmpId}/permissions/${enc(grantRef)}`, reason ? { reason } : {})).data,
    setVisibility: async (cmpId, visibility) => (await call<{ cmp_id: number; workspace_visibility: string }>('PUT', `/v1/companies/${cmpId}/visibility`, { visibility })).data,
    accessAudit: async (cmpId, page = 1) => (await call<AuditEntry[]>('GET', `/v1/companies/${cmpId}/access-audit${query({ page })}`)).data ?? [],
    requestAccess: async (cmpId, body) => (await call<{ request: AccessRequest; created: boolean }>('POST', `/v1/companies/${cmpId}/access-requests`, { app: appKey, ...body })).data,
    companyRequests: async (cmpId, status) => (await call<AccessRequest[]>('GET', `/v1/companies/${cmpId}/access-requests${query({ status })}`)).data ?? [],
    inbox: async (role, status = 'pending') => (await call<AccessRequest[]>('GET', `/v1/access-requests${query({ role, status })}`)).data ?? [],
    approveRequest: async (requestId, body = {}) => (await call<{ request: AccessRequest }>('POST', `/v1/access-requests/${requestId}/approve`, body)).data,
    rejectRequest: async (requestId, note) => (await call<{ request: AccessRequest }>('POST', `/v1/access-requests/${requestId}/reject`, note ? { note } : {})).data,
    cancelRequest: async (requestId) => (await call<{ request: AccessRequest }>('POST', `/v1/access-requests/${requestId}/cancel`, {})).data,
    workspaces: async () => (await call<Workspace[]>('GET', '/v1/workspaces')).data ?? [],
    workspaceMembers: async (workspaceId) => (await call<WorkspaceMember[]>('GET', `/v1/workspaces/${workspaceId}/members`)).data ?? [],
    memberSuggestions: async (workspaceId) => (await call<MemberSuggestion[]>('GET', `/v1/workspaces/${workspaceId}/member-suggestions`)).data ?? [],
    inviteMember: async (workspaceId, body) => (await call<{ changed: boolean }>('POST', `/v1/workspaces/${workspaceId}/members`, body)).data,
    removeMember: async (workspaceId, uuid, revokeCompanyAccess = false) =>
      (await call<unknown>('DELETE', `/v1/workspaces/${workspaceId}/members/${enc(uuid)}`, { revoke_company_access: revokeCompanyAccess })).data,
    respondToWorkspace: async (workspaceId, action) => (await call<unknown>('POST', `/v1/workspaces/${workspaceId}/${action}`, {})).data,
    duplicateCheck: async (body) =>
      (await call<{ matches: DuplicateMatch[]; can_create: boolean; needs_acknowledgement: boolean }>('POST', '/v1/companies/duplicate-check', body)).data,
  };
}
