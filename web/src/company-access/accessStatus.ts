/** Labels and tones for registry access statuses — one vocabulary across every product. */
import type { AccessStatus, DirectoryCompany } from './companyRegistryClient.ts';

export type StatusTone = 'success' | 'neutral' | 'warning' | 'danger' | 'info';

const LABELS: Record<AccessStatus, string> = {
  OWNER: 'Owner',
  ACCESS_GRANTED: 'Access granted',
  ACCESS_RESTRICTED: 'Access restricted',
  ACCESS_REQUESTED: 'Access requested',
  ACCESS_PENDING: 'Invitation pending',
  ACCESS_EXPIRED: 'Access expired',
  ACCESS_REVOKED: 'Access revoked',
  SUBSCRIPTION_REQUIRED: 'Subscription required',
  COMPANY_ARCHIVED: 'Archived',
  WORKSPACE_MEMBERSHIP_INACTIVE: 'Membership ended',
};

const TONES: Record<AccessStatus, StatusTone> = {
  OWNER: 'success',
  ACCESS_GRANTED: 'success',
  ACCESS_RESTRICTED: 'neutral',
  ACCESS_REQUESTED: 'info',
  ACCESS_PENDING: 'info',
  ACCESS_EXPIRED: 'warning',
  ACCESS_REVOKED: 'danger',
  SUBSCRIPTION_REQUIRED: 'warning',
  COMPANY_ARCHIVED: 'neutral',
  WORKSPACE_MEMBERSHIP_INACTIVE: 'danger',
};

export function accessStatusLabel(status: AccessStatus | string | null | undefined): string {
  return (status && LABELS[status as AccessStatus]) || 'Unknown';
}

export function accessStatusTone(status: AccessStatus | string | null | undefined): StatusTone {
  return (status && TONES[status as AccessStatus]) || 'neutral';
}

export function isOpenable(company: Pick<DirectoryCompany, 'access_status' | 'can_open_in_current_app'>): boolean {
  return company.can_open_in_current_app === true || company.access_status === 'OWNER' || company.access_status === 'ACCESS_GRANTED';
}

/** The explanation shown when a restricted company is chosen — never a hint that it does not exist. */
export function restrictedExplanation(company: Pick<DirectoryCompany, 'access_status'>, appName: string): string {
  switch (company.access_status) {
    case 'ACCESS_REQUESTED':
      return `You have asked the company owner for access in ${appName}. You will be able to open it here once they approve.`;
    case 'ACCESS_PENDING':
      return 'An invitation to this company is waiting for you. Accept it in Manage → Invitations.';
    case 'ACCESS_EXPIRED':
      return `Your access to this company in ${appName} has expired. You can ask the company owner to renew it.`;
    case 'ACCESS_REVOKED':
      return `Your access to this company in ${appName} was removed by the company owner. You can ask for it again.`;
    case 'COMPANY_ARCHIVED':
      return 'This company is archived. It cannot be opened for new work.';
    default:
      return `This company is registered within your Aicountly workspace, but you do not currently have permission to access it in ${appName}. You can request access from the company owner.`;
  }
}
