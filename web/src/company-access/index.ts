/**
 * AICOUNTLY shared company access — copy this folder into a product as `web/src/company-access/`.
 * See README.md. Version: ./VERSION.
 */
export * from './companyRegistryClient.ts';
export * from './accessStatus.ts';
export { injectCompanyAccessStyles } from './styles.ts';
export { useCompanyDirectory } from './useCompanyDirectory.ts';
export type { CompanyDirectoryState } from './useCompanyDirectory.ts';
export { CompanyAccessBadge } from './CompanyAccessBadge.tsx';
export type { CompanyAccessBadgeProps } from './CompanyAccessBadge.tsx';
export { RestrictedCompanyDialog } from './RestrictedCompanyDialog.tsx';
export type { RestrictedCompanyDialogProps } from './RestrictedCompanyDialog.tsx';
export { CompanyDirectoryPicker } from './CompanyDirectoryPicker.tsx';
export type { CompanyDirectoryPickerProps } from './CompanyDirectoryPicker.tsx';
export { CompanyUsersAccessPanel } from './CompanyUsersAccessPanel.tsx';
export type { CompanyUsersAccessPanelProps } from './CompanyUsersAccessPanel.tsx';
export { WorkspaceManager } from './WorkspaceManager.tsx';
export type { WorkspaceManagerProps, WorkspaceRun } from './WorkspaceManager.tsx';
export { personName } from './people.ts';
export { MyWorkspacesPanel, workspaceDisplayName } from './MyWorkspacesPanel.tsx';
export type { MyWorkspacesPanelProps } from './MyWorkspacesPanel.tsx';
export { DuplicateCompanyNotice } from './DuplicateCompanyNotice.tsx';
export type { DuplicateCompanyNoticeProps } from './DuplicateCompanyNotice.tsx';
// v1.2.0 — workspace sharing settings and company creation in a workspace (CONTRACTS §1, §2).
// fetchCreationTargets, fetchAppRoles, createCompanyRequestFields and emptyWorkspaceSettings come
// from companyRegistryClient.ts above.
export {
  WorkspaceTargetPicker,
  CreatorAccessFields,
  WorkspaceMemberSettingsForm,
  workspaceSettingsSummary,
  appLabel,
} from './WorkspaceCreation.tsx';
export type {
  WorkspaceTargetPickerProps,
  CreatorAccessFieldsProps,
  WorkspaceMemberSettingsFormProps,
} from './WorkspaceCreation.tsx';
