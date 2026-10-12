import type { AccessStatus } from './companyRegistryClient.ts';
import { accessStatusLabel, accessStatusTone } from './accessStatus.ts';
import { injectCompanyAccessStyles } from './styles.ts';

export interface CompanyAccessBadgeProps {
  status: AccessStatus | string | null | undefined;
  label?: string;
}

/** The access status of a company in the current application, in the shared vocabulary. */
export function CompanyAccessBadge({ status, label }: CompanyAccessBadgeProps) {
  injectCompanyAccessStyles();
  return (
    <span className={`aic-ca-badge aic-ca-tone-${accessStatusTone(status)}`} data-status={status ?? ''}>
      {label ?? accessStatusLabel(status)}
    </span>
  );
}
