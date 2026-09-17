/**
 * Settings, access control and the audit history.
 *
 * ## Three things on one screen, and why they belong together
 *
 * Every control here decides what Messaging is *allowed* to do — the policy,
 * who holds which permission, and the record of who changed it. Splitting the
 * audit history away from the controls it records would make it possible to
 * change a policy without ever seeing the trail it leaves.
 *
 * ## The two settings that are not like the others
 *
 * `first_response_target_minutes` may be left unset, and unset is a real
 * answer rather than a missing one: the Command Centre reports breaches only
 * against a target the business actually agreed to. The field therefore has a
 * "not set" state that is deliberately reachable, not just an empty box.
 *
 * `ai_autosend_allowed` puts an unreviewed, AI-written message in front of a
 * customer. It needs `messaging.ai.manage` on top of `messaging.settings.manage`,
 * and the backend refuses the change unless `confirm_autonomous_sending` comes
 * with it — so the UI asks in words rather than offering a switch that quietly
 * removes the human from the loop.
 *
 * ## Permissions are granted, never assumed
 *
 * The access tab draws the whole catalogue, but a permission this caller does
 * not hold themselves is rendered DISABLED with the reason, not hidden. You
 * can only grant what you hold — otherwise "manage access" would be a route to
 * every other permission — and an administrator seeing a locked row learns
 * something true. The backend enforces it either way; hiding a checkbox is a
 * courtesy.
 *
 * ## What the audit history contains
 *
 * Messaging's own actions, and references to other products' records — never
 * those products' records. An entry saying a balance was read names the
 * invoice reference and the decision taken, and the balance itself stays in
 * Books where it is current.
 */

import { useEffect, useMemo, useState } from 'react'
import { Lock, Plus, Save, UserMinus, UserPlus } from 'lucide-react'
import { useApi, useMutation } from '../hooks/useApi'
import { useUrlFilters } from '../hooks/useUrlState'
import { api, ApiError } from '../services/api'
import type {
  AccessResponse,
  AuditEvent,
  MessagingSettings,
  PermissionProfile,
  SettingsResponse,
} from '../services/types'
import { useMessaging } from '../context/MessagingContext'
import {
  Announce,
  Button,
  Drawer,
  EmptyState,
  Field,
  LoadingRows,
  Notice,
  Panel,
  PanelState,
  PermissionState,
  StatusPill,
  formatCount,
  formatDateTime,
  timeAgo,
} from '../ui'
import { humanise } from './CommandCentre'

export function SettingsPage() {
  const { can } = useMessaging()
  const [filters, setFilters] = useUrlFilters({ tab: 'policy' })

  const tabs = [
    ...(can('messaging.settings.manage') ? [{ key: 'policy', label: 'Policy' }] : []),
    ...(can('messaging.access.manage') ? [{ key: 'access', label: 'Access' }] : []),
    ...(can('messaging.audit.view') ? [{ key: 'audit', label: 'Audit history' }] : []),
  ]

  if (tabs.length === 0) {
    return (
      <div className="msg-ui">
        <PermissionState what="Messaging settings" />
      </div>
    )
  }

  const tab = tabs.some((entry) => entry.key === filters.tab) ? filters.tab : tabs[0].key

  return (
    <div className="msg-ui">
      <div className="msg-page-header">
        <div>
          <h1>Settings</h1>
          <p>What Messaging is allowed to do, who may do it, and what has been changed.</p>
        </div>
        <div className="msg-page-actions">
          <div className="msg-chips" role="tablist" aria-label="Settings sections">
            {tabs.map((entry) => (
              <button
                key={entry.key}
                type="button"
                role="tab"
                className="msg-chip"
                aria-selected={tab === entry.key}
                aria-pressed={tab === entry.key}
                onClick={() => setFilters({ tab: entry.key })}
              >
                {entry.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {tab === 'policy' && <PolicyTab />}
      {tab === 'access' && <AccessTab />}
      {tab === 'audit' && <AuditTab />}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Policy
// ---------------------------------------------------------------------------

/** The editable subset, as the form holds it. Times and targets are strings here. */
interface PolicyForm {
  timezone: string
  currency: string
  first_response_target_minutes: string
  resolution_target_minutes: string
  quiet_hours_start: string
  quiet_hours_end: string
  ai_draft_allowed: boolean
  ai_translate_allowed: boolean
  ai_summarise_allowed: boolean
  ai_suggest_allowed: boolean
  ai_autosend_allowed: boolean
  default_languages: string[]
}

function toForm(settings: MessagingSettings): PolicyForm {
  return {
    timezone: settings.timezone ?? '',
    currency: settings.currency ?? '',
    // Null becomes an empty box, and an empty box is sent back as null. The
    // round trip has to preserve "nobody set a target".
    first_response_target_minutes:
      settings.first_response_target_minutes === null ? '' : String(settings.first_response_target_minutes),
    resolution_target_minutes:
      settings.resolution_target_minutes === null ? '' : String(settings.resolution_target_minutes),
    quiet_hours_start: (settings.quiet_hours_start ?? '').slice(0, 5),
    quiet_hours_end: (settings.quiet_hours_end ?? '').slice(0, 5),
    ai_draft_allowed: Boolean(settings.ai_draft_allowed),
    ai_translate_allowed: Boolean(settings.ai_translate_allowed),
    ai_summarise_allowed: Boolean(settings.ai_summarise_allowed),
    ai_suggest_allowed: Boolean(settings.ai_suggest_allowed),
    ai_autosend_allowed: Boolean(settings.ai_autosend_allowed),
    default_languages: [...(settings.default_languages ?? [])],
  }
}

function PolicyTab() {
  const { session, timezone, reload: reloadSession, can } = useMessaging()
  const state = useApi((signal) => api.get<SettingsResponse>('v1/settings', undefined, signal), [])

  const [form, setForm] = useState<PolicyForm | null>(null)
  const [confirmAutosend, setConfirmAutosend] = useState(false)
  const [announcement, setAnnouncement] = useState<string | null>(null)

  const loaded = state.data?.settings
  useEffect(() => {
    if (loaded) setForm(toForm(loaded))
  }, [loaded])

  const save = useMutation((payload: Record<string, unknown>) =>
    api.put<MessagingSettings>('v1/settings', payload),
  )

  const notes = state.data?.notes ?? {}
  const languages = state.data?.available_languages ?? {}
  const canManageAi = can('messaging.ai.manage')

  const error = save.error instanceof ApiError ? save.error : null
  const fieldErrors = error?.fieldErrors ?? {}
  // The backend reports the offending field in `details.field`; a message with
  // no field attached belongs at the top of the form, not beside a guess.
  const offendingField = typeof error?.details.field === 'string' ? error.details.field : null

  function patch(next: Partial<PolicyForm>) {
    setForm((current) => (current ? { ...current, ...next } : current))
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    if (!form || !loaded) return

    const payload: Record<string, unknown> = {
      timezone: form.timezone,
      currency: form.currency.toUpperCase(),
      // Empty means "no target". Sent as null rather than omitted, so clearing
      // a target actually clears it.
      first_response_target_minutes:
        form.first_response_target_minutes === '' ? null : Number(form.first_response_target_minutes),
      resolution_target_minutes:
        form.resolution_target_minutes === '' ? null : Number(form.resolution_target_minutes),
      quiet_hours_start: form.quiet_hours_start === '' ? null : form.quiet_hours_start,
      quiet_hours_end: form.quiet_hours_end === '' ? null : form.quiet_hours_end,
      ai_draft_allowed: form.ai_draft_allowed,
      ai_translate_allowed: form.ai_translate_allowed,
      ai_summarise_allowed: form.ai_summarise_allowed,
      ai_suggest_allowed: form.ai_suggest_allowed,
      ai_autosend_allowed: form.ai_autosend_allowed,
      default_languages: form.default_languages,
    }

    // Only sent when the user actually ticked the confirmation, and only when
    // the change is the one that needs it. A flag sent by default would make
    // the backend's guard decorative.
    const turningOnAutosend = form.ai_autosend_allowed && !loaded.ai_autosend_allowed
    if (turningOnAutosend && confirmAutosend) {
      payload.confirm_autonomous_sending = true
    }

    const result = await save.run(payload)
    if (result) {
      setAnnouncement('Settings saved.')
      setConfirmAutosend(false)
      state.reload()
      // The shell reads timezone, currency and the targets from the session, so
      // a saved change has to reach it or every other screen keeps formatting
      // against the old timezone.
      reloadSession()
    }
  }

  return (
    <>
      <Announce message={announcement} />

      <PanelState
        // The form is seeded from the response by an effect, so the frame
        // between "arrived" and "seeded" counts as loading. Without this the
        // screen flashes "nothing to show" over settings that are right there.
        loading={state.loading || (state.data !== null && form === null)}
        error={state.error}
        data={form}
        onRetry={state.reload}
        skeletonRows={5}
      >
        {() =>
          form === null ? (
            <LoadingRows rows={4} />
          ) : (
            <form className="msg-stack" onSubmit={submit}>
              {error && (
                <Notice tone="danger" title="That change was not saved">
                  {error.message}
                  {error.details.requires_confirmation === true && (
                    <> Tick the confirmation below and save again.</>
                  )}
                </Notice>
              )}

              {loaded && loaded.updated_at && (
                <p className="msg-small msg-muted">
                  Last changed {timeAgo(loaded.updated_at)}
                  {loaded.updated_by ? ` by ${loaded.updated_by}` : ''}. Every change is recorded in the audit
                  history.
                </p>
              )}

              <Panel
                title="Company and calendar"
                subtitle="Every period, quiet-hours window and timestamp on every screen is read in this timezone."
              >
                <div className="msg-grid msg-grid-2">
                  <Field
                    label="Timezone"
                    htmlFor="settings-timezone"
                    required
                    error={offendingField === 'timezone' ? error?.message : fieldErrors.timezone}
                    hint="An IANA name, such as Asia/Kolkata."
                  >
                    <input
                      id="settings-timezone"
                      className="msg-input"
                      value={form.timezone}
                      list="settings-timezone-options"
                      aria-describedby="settings-timezone-hint"
                      aria-invalid={offendingField === 'timezone' || undefined}
                      onChange={(event) => patch({ timezone: event.target.value })}
                    />
                    <datalist id="settings-timezone-options">
                      {TIMEZONE_SUGGESTIONS.map((zone) => (
                        <option key={zone} value={zone} />
                      ))}
                    </datalist>
                  </Field>

                  <Field
                    label="Reporting currency"
                    htmlFor="settings-currency"
                    required
                    error={offendingField === 'currency' ? error?.message : fieldErrors.currency}
                    hint={notes.currency}
                  >
                    <input
                      id="settings-currency"
                      className="msg-input"
                      value={form.currency}
                      maxLength={3}
                      style={{ textTransform: 'uppercase', maxWidth: '10rem' }}
                      aria-describedby="settings-currency-hint"
                      aria-invalid={offendingField === 'currency' || undefined}
                      onChange={(event) => patch({ currency: event.target.value.toUpperCase() })}
                    />
                  </Field>
                </div>
              </Panel>

              <Panel
                title="Response targets"
                subtitle="Leave either blank and no screen will report a breach of it."
              >
                <div className="msg-grid msg-grid-2">
                  <Field
                    label="First response target (minutes)"
                    htmlFor="settings-first-response"
                    error={
                      offendingField === 'first_response_target_minutes'
                        ? error?.message
                        : fieldErrors.first_response_target_minutes
                    }
                    hint={notes.first_response_target_minutes}
                  >
                    <input
                      id="settings-first-response"
                      className="msg-input"
                      type="number"
                      min={1}
                      max={10080}
                      inputMode="numeric"
                      placeholder="Not set"
                      style={{ maxWidth: '12rem' }}
                      value={form.first_response_target_minutes}
                      aria-describedby="settings-first-response-hint"
                      onChange={(event) => patch({ first_response_target_minutes: event.target.value })}
                    />
                  </Field>

                  <Field
                    label="Resolution target (minutes)"
                    htmlFor="settings-resolution"
                    error={
                      offendingField === 'resolution_target_minutes'
                        ? error?.message
                        : fieldErrors.resolution_target_minutes
                    }
                    hint="Measured from the first inbound message to the conversation being resolved."
                  >
                    <input
                      id="settings-resolution"
                      className="msg-input"
                      type="number"
                      min={1}
                      max={10080}
                      inputMode="numeric"
                      placeholder="Not set"
                      style={{ maxWidth: '12rem' }}
                      value={form.resolution_target_minutes}
                      aria-describedby="settings-resolution-hint"
                      onChange={(event) => patch({ resolution_target_minutes: event.target.value })}
                    />
                  </Field>
                </div>
              </Panel>

              <Panel
                title="Quiet hours"
                subtitle={notes.quiet_hours}
              >
                <div className="msg-grid msg-grid-2">
                  <Field
                    label="Quiet from"
                    htmlFor="settings-quiet-start"
                    error={offendingField === 'quiet_hours_start' ? error?.message : undefined}
                    hint={`Local time in ${form.timezone || timezone}.`}
                  >
                    <input
                      id="settings-quiet-start"
                      className="msg-input"
                      type="time"
                      style={{ maxWidth: '10rem' }}
                      value={form.quiet_hours_start}
                      aria-describedby="settings-quiet-start-hint"
                      onChange={(event) => patch({ quiet_hours_start: event.target.value })}
                    />
                  </Field>

                  <Field
                    label="Quiet until"
                    htmlFor="settings-quiet-end"
                    error={offendingField === 'quiet_hours_end' ? error?.message : undefined}
                    hint="A window that crosses midnight is understood, so 21:00 to 08:00 works."
                  >
                    <input
                      id="settings-quiet-end"
                      className="msg-input"
                      type="time"
                      style={{ maxWidth: '10rem' }}
                      value={form.quiet_hours_end}
                      aria-describedby="settings-quiet-end-hint"
                      onChange={(event) => patch({ quiet_hours_end: event.target.value })}
                    />
                  </Field>
                </div>

                {(form.quiet_hours_start === '') !== (form.quiet_hours_end === '') && (
                  <Notice tone="warning" title="Half a window is no window">
                    Quiet hours apply only when both ends are set. As it stands, promotional messages will be sent
                    at any hour.
                  </Notice>
                )}
              </Panel>

              <Panel
                title="Languages"
                subtitle="Offered when drafting and translating. The first is the default for new drafts."
              >
                <fieldset style={{ border: 0, margin: 0, padding: 0 }}>
                  <legend className="msg-visually-hidden">Languages offered when drafting</legend>
                  <div className="msg-chips">
                    {Object.entries(languages).map(([code, label]) => {
                      const selected = form.default_languages.includes(code)
                      return (
                        <label key={code} className="msg-chip" aria-pressed={selected}>
                          <input
                            type="checkbox"
                            checked={selected}
                            style={{ marginRight: '0.4rem' }}
                            onChange={(event) =>
                              patch({
                                default_languages: event.target.checked
                                  ? [...form.default_languages, code]
                                  : form.default_languages.filter((entry) => entry !== code),
                              })
                            }
                          />
                          {label}
                        </label>
                      )
                    })}
                  </div>
                </fieldset>

                {form.default_languages.length === 0 && (
                  <Notice tone="warning" title="At least one language is required">
                    The backend will refuse an empty list, because a composer with no language offered is a
                    composer nobody can use.
                  </Notice>
                )}
              </Panel>

              <Panel
                title="What AI may do"
                subtitle={
                  session?.ai.available
                    ? 'Each of these is a separate permission for the AI itself, not for the people using it.'
                    : undefined
                }
              >
                {!session?.ai.available && (
                  <Notice tone="info" title="AI is not configured for this deployment">
                    {session?.ai.reason ??
                      'Aicountly Console holds the model configuration and credentials. These switches can be set '
                        + 'now and take effect once it is connected.'}
                  </Notice>
                )}

                <div className="msg-stack">
                  <Toggle
                    id="settings-ai-draft"
                    label="Draft replies"
                    detail="Suggest a reply an agent then reads, edits and approves."
                    checked={form.ai_draft_allowed}
                    onChange={(value) => patch({ ai_draft_allowed: value })}
                  />
                  <Toggle
                    id="settings-ai-translate"
                    label="Translate"
                    detail="A translation that loses an amount or a reference is refused rather than sent."
                    checked={form.ai_translate_allowed}
                    onChange={(value) => patch({ ai_translate_allowed: value })}
                  />
                  <Toggle
                    id="settings-ai-summarise"
                    label="Summarise a conversation"
                    detail="For handover and for reopening a thread that has gone quiet."
                    checked={form.ai_summarise_allowed}
                    onChange={(value) => patch({ ai_summarise_allowed: value })}
                  />
                  <Toggle
                    id="settings-ai-suggest"
                    label="Suggest next actions"
                    detail="Suggestions are counted from Messaging's own records, and each one says what it counted."
                    checked={form.ai_suggest_allowed}
                    onChange={(value) => patch({ ai_suggest_allowed: value })}
                  />

                  <Toggle
                    id="settings-ai-autosend"
                    label="Send without human review"
                    detail={notes.ai_autosend_allowed ?? 'Off by default.'}
                    checked={form.ai_autosend_allowed}
                    disabled={!canManageAi}
                    disabledReason="Changing this needs the “Change what Messaging AI is allowed to do” permission."
                    tone="danger"
                    onChange={(value) => {
                      patch({ ai_autosend_allowed: value })
                      if (!value) setConfirmAutosend(false)
                    }}
                  />

                  {form.ai_autosend_allowed && !loaded?.ai_autosend_allowed && (
                    <Notice tone="danger" title="This removes the human from the loop">
                      <p style={{ margin: '0 0 0.5rem' }}>
                        With this on, a message an AI wrote can reach a customer without anybody reading it first.
                        Approval gates, consent and quiet hours still apply — the review step does not.
                      </p>
                      <label className="msg-small" style={{ display: 'flex', gap: '0.5rem' }}>
                        <input
                          type="checkbox"
                          checked={confirmAutosend}
                          onChange={(event) => setConfirmAutosend(event.target.checked)}
                        />
                        <span>I understand, and I am turning on autonomous sending deliberately.</span>
                      </label>
                    </Notice>
                  )}
                </div>
              </Panel>

              <div className="msg-actions">
                <Button type="submit" tone="primary" pending={save.pending}>
                  <Save size={15} aria-hidden /> Save settings
                </Button>
                <Button
                  tone="ghost"
                  onClick={() => {
                    if (loaded) setForm(toForm(loaded))
                    setConfirmAutosend(false)
                    save.reset()
                  }}
                >
                  Discard changes
                </Button>
              </div>
            </form>
          )
        }
      </PanelState>
    </>
  )
}

/** The zones a business on this platform actually uses, as suggestions only. */
const TIMEZONE_SUGGESTIONS = [
  'Asia/Kolkata',
  'Asia/Dubai',
  'Asia/Singapore',
  'Asia/Karachi',
  'Asia/Dhaka',
  'Asia/Kathmandu',
  'Europe/London',
  'Europe/Berlin',
  'America/New_York',
  'America/Los_Angeles',
  'Australia/Sydney',
  'UTC',
]

function Toggle({
  id,
  label,
  detail,
  checked,
  onChange,
  disabled = false,
  disabledReason,
  tone,
}: {
  id: string
  label: string
  detail: string
  checked: boolean
  onChange: (value: boolean) => void
  disabled?: boolean
  disabledReason?: string
  tone?: 'danger'
}) {
  return (
    <div className="msg-row">
      <label htmlFor={id} className="msg-row-body" style={{ cursor: disabled ? 'not-allowed' : 'pointer' }}>
        <strong style={tone === 'danger' && checked ? { color: 'var(--danger)' } : undefined}>{label}</strong>
        <small>{detail}</small>
        {disabled && disabledReason && (
          <small className="msg-muted">
            <Lock size={12} aria-hidden /> {disabledReason}
          </small>
        )}
      </label>
      <input
        id={id}
        type="checkbox"
        checked={checked}
        disabled={disabled}
        aria-describedby={`${id}-detail`}
        onChange={(event) => onChange(event.target.checked)}
      />
      {/* The detail is already in the label, but a screen reader reading the
          control alone should still get it. */}
      <span id={`${id}-detail`} className="msg-visually-hidden">
        {detail}
        {disabled && disabledReason ? ` ${disabledReason}` : ''}
      </span>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------

function AccessTab() {
  const { timezone } = useMessaging()
  const state = useApi((signal) => api.get<AccessResponse>('v1/access', undefined, signal), [])
  const [editing, setEditing] = useState<PermissionProfile | 'new' | null>(null)
  const [assigning, setAssigning] = useState<PermissionProfile | null>(null)
  const [announcement, setAnnouncement] = useState<string | null>(null)

  const revoke = useMutation((userUuid: string, profileId: number) =>
    api.del<{ revoked: boolean }>('v1/access/assignments', { user_uuid: userUuid, profile_id: profileId }),
  )

  return (
    <>
      <Announce message={announcement} />

      <PanelState
        loading={state.loading}
        error={state.error}
        data={state.data}
        onRetry={state.reload}
        context="access control"
        skeletonRows={5}
      >
        {(access) => (
          <div className="msg-stack">
            {access.notes.map((note) => (
              <Notice key={note} tone="info">
                {note}
              </Notice>
            ))}

            <Panel
              title="Permission profiles"
              subtitle="A named set of permissions, assigned to people."
              action={
                <Button tone="primary" small onClick={() => setEditing('new')}>
                  <Plus size={15} aria-hidden /> New profile
                </Button>
              }
            >
              {access.profiles.length === 0 ? (
                <EmptyState title="No profiles yet">
                  Until a profile is assigned, every member gets the conservative default: they can read the inbox
                  and add notes, but they cannot see financial context, approve a draft or send a message.
                </EmptyState>
              ) : (
                <div className="msg-table-wrap">
                  <table className="msg-table">
                    <caption className="msg-visually-hidden">Messaging permission profiles</caption>
                    <thead>
                      <tr>
                        <th scope="col">Profile</th>
                        <th scope="col">Permissions</th>
                        <th scope="col" className="num">
                          People
                        </th>
                        <th scope="col">Status</th>
                        <th scope="col">
                          <span className="msg-visually-hidden">Actions</span>
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {access.profiles.map((profile) => (
                        <tr key={profile.profile_id}>
                          <th scope="row">
                            <strong>{profile.name}</strong>
                            {profile.description && <small className="msg-muted"> {profile.description}</small>}
                          </th>
                          <td>
                            <span className="msg-small">{formatCount(profile.permissions.length)} granted</span>
                            {profile.permissions.some((permission) =>
                              access.sensitive.includes(permission),
                            ) && (
                              <>
                                {' '}
                                <StatusPill tone="warning">Includes sensitive</StatusPill>
                              </>
                            )}
                          </td>
                          <td className="num">{formatCount(profile.member_count)}</td>
                          <td>
                            <StatusPill tone={profile.is_active ? 'success' : 'neutral'}>
                              {profile.is_active ? 'Active' : 'Inactive'}
                            </StatusPill>
                          </td>
                          <td>
                            <div className="msg-actions">
                              <Button tone="ghost" small onClick={() => setEditing(profile)}>
                                Edit
                              </Button>
                              <Button tone="ghost" small onClick={() => setAssigning(profile)}>
                                <UserPlus size={14} aria-hidden /> Assign
                              </Button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Panel>

            <Panel
              title="Who holds what"
              subtitle="A person may hold more than one profile; their permissions are the union."
            >
              {access.assignments.length === 0 ? (
                <EmptyState title="Nobody has been assigned a profile">Everyone is on the default.</EmptyState>
              ) : (
                <div className="msg-table-wrap">
                  <table className="msg-table">
                    <caption className="msg-visually-hidden">Profile assignments</caption>
                    <thead>
                      <tr>
                        <th scope="col">User</th>
                        <th scope="col">Profile</th>
                        <th scope="col">Assigned</th>
                        <th scope="col">
                          <span className="msg-visually-hidden">Actions</span>
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {access.assignments.map((assignment) => (
                        <tr key={assignment.assignment_id}>
                          {/* The portal owns names; Messaging holds only the
                              reference, so the reference is what it shows. */}
                          <th scope="row" className="msg-truncate">
                            <code>{assignment.user_uuid}</code>
                          </th>
                          <td>{assignment.profile_name}</td>
                          <td>
                            <span title={formatDateTime(assignment.created_at, timezone)}>
                              {timeAgo(assignment.created_at)}
                            </span>
                          </td>
                          <td>
                            <Button
                              tone="ghost"
                              small
                              pending={revoke.pending}
                              onClick={async () => {
                                const result = await revoke.run(assignment.user_uuid, assignment.profile_id)
                                if (result) {
                                  setAnnouncement(`Removed ${assignment.profile_name}.`)
                                  state.reload()
                                }
                              }}
                            >
                              <UserMinus size={14} aria-hidden /> Remove
                            </Button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {revoke.error && (
                <Notice tone="danger" title="That profile was not removed">
                  {revoke.error.message}
                </Notice>
              )}
            </Panel>

            {editing !== null && (
              <ProfileDrawer
                access={access}
                profile={editing === 'new' ? null : editing}
                onClose={() => setEditing(null)}
                onSaved={(name) => {
                  setEditing(null)
                  setAnnouncement(`Saved ${name}.`)
                  state.reload()
                }}
              />
            )}

            {assigning !== null && (
              <AssignDrawer
                profile={assigning}
                onClose={() => setAssigning(null)}
                onAssigned={() => {
                  setAssigning(null)
                  setAnnouncement(`Assigned ${assigning.name}.`)
                  state.reload()
                }}
              />
            )}
          </div>
        )}
      </PanelState>
    </>
  )
}

function ProfileDrawer({
  access,
  profile,
  onClose,
  onSaved,
}: {
  access: AccessResponse
  profile: PermissionProfile | null
  onClose: () => void
  onSaved: (name: string) => void
}) {
  const [name, setName] = useState(profile?.name ?? '')
  const [description, setDescription] = useState(profile?.description ?? '')
  const [isActive, setIsActive] = useState(profile?.is_active ?? true)
  const [selected, setSelected] = useState<string[]>(
    () => profile?.permissions ?? access.defaults,
  )

  const save = useMutation((payload: Record<string, unknown>) =>
    api.post<{ profile_id: number; permissions: string[] }>('v1/access/profiles', payload),
  )

  const grantable = useMemo(() => new Set(access.grantable), [access.grantable])
  const sensitive = useMemo(() => new Set(access.sensitive), [access.sensitive])

  // A profile being edited may already hold something this caller cannot grant.
  // Sending it back would be refused, so it is shown, locked, and kept.
  const locked = selected.filter((permission) => !grantable.has(permission))

  const error = save.error instanceof ApiError ? save.error : null

  function toggle(permission: string, on: boolean) {
    setSelected((current) =>
      on ? [...new Set([...current, permission])] : current.filter((entry) => entry !== permission),
    )
  }

  return (
    <Drawer
      title={profile ? `Edit ${profile.name}` : 'New permission profile'}
      subtitle="You can only grant permissions you hold yourself."
      onClose={onClose}
      footer={
        <div className="msg-actions">
          <Button
            tone="primary"
            pending={save.pending}
            disabled={name.trim() === ''}
            onClick={async () => {
              const result = await save.run({
                profile_id: profile?.profile_id,
                name: name.trim(),
                description,
                is_active: isActive,
                permissions: selected,
              })
              if (result) onSaved(name.trim())
            }}
          >
            {profile ? 'Save profile' : 'Create profile'}
          </Button>
          <Button tone="ghost" onClick={onClose}>
            Cancel
          </Button>
        </div>
      }
    >
      <div className="msg-stack">
        {error && (
          <Notice tone="danger" title="That profile was not saved">
            {error.message}
          </Notice>
        )}

        <Field label="Name" htmlFor="profile-name" required>
          <input
            id="profile-name"
            className="msg-input"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </Field>

        <Field label="What this profile is for" htmlFor="profile-description" hint="Shown to whoever assigns it.">
          <input
            id="profile-description"
            className="msg-input"
            value={description}
            aria-describedby="profile-description-hint"
            onChange={(event) => setDescription(event.target.value)}
          />
        </Field>

        <div className="msg-row">
          <label htmlFor="profile-active" className="msg-row-body">
            <strong>Active</strong>
            <small>An inactive profile grants nothing, without being deleted.</small>
          </label>
          <input
            id="profile-active"
            type="checkbox"
            checked={isActive}
            onChange={(event) => setIsActive(event.target.checked)}
          />
        </div>

        {locked.length > 0 && (
          <Notice tone="warning" title="Some permissions here are not yours to change">
            This profile holds {formatCount(locked.length)} permission
            {locked.length === 1 ? '' : 's'} you do not hold yourself. They are shown locked and will be kept as
            they are.
          </Notice>
        )}

        {Object.entries(access.catalog).map(([group, permissions]) => (
          <fieldset key={group} style={{ border: 0, margin: 0, padding: 0 }}>
            <legend className="msg-small" style={{ fontWeight: 600, padding: '0 0 0.35rem' }}>
              {group}
            </legend>
            <div className="msg-stack">
              {Object.entries(permissions).map(([permission, label]) => {
                const held = selected.includes(permission)
                const canGrant = grantable.has(permission)
                const id = `perm-${permission.replace(/\./g, '-')}`

                return (
                  <div className="msg-row msg-row-tight" key={permission}>
                    <label htmlFor={id} className="msg-row-body" style={{ cursor: canGrant ? 'pointer' : 'not-allowed' }}>
                      <strong>{label}</strong>
                      <small className="msg-muted">
                        <code>{permission}</code>
                        {sensitive.has(permission) && (
                          <>
                            {' '}
                            <StatusPill tone="warning">Sensitive</StatusPill>
                          </>
                        )}
                        {!canGrant && (
                          <>
                            {' '}
                            <Lock size={12} aria-hidden /> You do not hold this
                          </>
                        )}
                      </small>
                    </label>
                    <input
                      id={id}
                      type="checkbox"
                      checked={held}
                      disabled={!canGrant}
                      onChange={(event) => toggle(permission, event.target.checked)}
                    />
                  </div>
                )
              })}
            </div>
          </fieldset>
        ))}
      </div>
    </Drawer>
  )
}

function AssignDrawer({
  profile,
  onClose,
  onAssigned,
}: {
  profile: PermissionProfile
  onClose: () => void
  onAssigned: () => void
}) {
  const [userUuid, setUserUuid] = useState('')
  const assign = useMutation((payload: Record<string, unknown>) =>
    api.post<{ assigned: boolean }>('v1/access/assignments', payload),
  )

  return (
    <Drawer
      title={`Assign ${profile.name}`}
      subtitle="Aicountly Manage owns the member list. Messaging stores only the reference."
      onClose={onClose}
      footer={
        <div className="msg-actions">
          <Button
            tone="primary"
            pending={assign.pending}
            disabled={userUuid.trim() === ''}
            onClick={async () => {
              const result = await assign.run({ user_uuid: userUuid.trim(), profile_id: profile.profile_id })
              if (result) onAssigned()
            }}
          >
            Assign profile
          </Button>
          <Button tone="ghost" onClick={onClose}>
            Cancel
          </Button>
        </div>
      }
    >
      <div className="msg-stack">
        {assign.error && (
          <Notice tone="danger" title="That profile was not assigned">
            {assign.error.message}
          </Notice>
        )}

        <Field
          label="User"
          htmlFor="assign-uuid"
          required
          hint="The portal user UUID, as it appears in Aicountly Manage. Messaging does not keep a copy of the
                member list, so there is nothing here to search."
        >
          <input
            id="assign-uuid"
            className="msg-input"
            value={userUuid}
            autoComplete="off"
            spellCheck={false}
            aria-describedby="assign-uuid-hint"
            onChange={(event) => setUserUuid(event.target.value)}
          />
        </Field>

        <Panel title="What this grants" subtitle={`${formatCount(profile.permissions.length)} permissions`}>
          <ul className="msg-small" style={{ margin: 0, paddingLeft: '1.1rem' }}>
            {profile.permissions.map((permission) => (
              <li key={permission}>
                <code>{permission}</code>
              </li>
            ))}
          </ul>
        </Panel>
      </div>
    </Drawer>
  )
}

// ---------------------------------------------------------------------------
// Audit
// ---------------------------------------------------------------------------

const AUDIT_PAGE_SIZE = 50

function AuditTab() {
  const { timezone } = useMessaging()
  const [filters, setFilters] = useUrlFilters({ action: '', entity_type: '', actor_uuid: '', offset: '0' })
  const offset = Number(filters.offset) || 0

  const state = useApi(
    (signal) =>
      api.list<AuditEvent>(
        'v1/audit',
        {
          action: filters.action || undefined,
          entity_type: filters.entity_type || undefined,
          actor_uuid: filters.actor_uuid || undefined,
          limit: AUDIT_PAGE_SIZE,
          offset,
        },
        signal,
      ),
    [filters.action, filters.entity_type, filters.actor_uuid, offset],
  )

  const [expanded, setExpanded] = useState<number | null>(null)
  const actions = (state.data?.meta.actions as string[] | undefined) ?? []
  const total = state.data?.meta.total ?? 0

  return (
    <Panel
      title="Audit history"
      subtitle={(state.data?.meta.note as string | undefined) ?? undefined}
    >
      <div className="msg-filters">
        <label className="msg-visually-hidden" htmlFor="audit-action">
          Action
        </label>
        <select
          id="audit-action"
          className="msg-select"
          value={filters.action}
          onChange={(event) => setFilters({ action: event.target.value, offset: '0' })}
        >
          <option value="">Every action</option>
          {actions.map((action) => (
            <option key={action} value={action}>
              {humanise(action)}
            </option>
          ))}
        </select>

        <label className="msg-visually-hidden" htmlFor="audit-actor">
          Actor UUID
        </label>
        <input
          id="audit-actor"
          className="msg-input"
          placeholder="Filter by user UUID"
          value={filters.actor_uuid}
          onChange={(event) => setFilters({ actor_uuid: event.target.value, offset: '0' })}
        />
      </div>

      {state.loading && state.data === null ? (
        <LoadingRows rows={6} />
      ) : state.error ? (
        <Notice tone="danger" title="The audit history could not be read">
          {state.error.message}
        </Notice>
      ) : (state.data?.data ?? []).length === 0 ? (
        <EmptyState title="Nothing recorded for these filters">
          Audit entries appear as settings, access, consent, channels and dispatch are changed.
        </EmptyState>
      ) : (
        <>
          <div className="msg-timeline">
            {(state.data?.data ?? []).map((event) => (
              <div className="msg-timeline-row" key={event.audit_id}>
                <span
                  className={`msg-timeline-dot${
                    event.action.includes('revoked') || event.action.includes('suppress')
                      ? ' msg-timeline-dot-warning'
                      : ''
                  }`}
                  aria-hidden
                />
                <div className="msg-timeline-body">
                  <strong>{humanise(event.action)}</strong>
                  <small>
                    {formatDateTime(event.created_at, timezone)}
                    {' · '}
                    {event.actor_kind === 'service'
                      ? `${event.source_app ?? 'another Aicountly product'} (service)`
                      : event.actor_uuid ?? 'unknown actor'}
                    {event.entity_type && (
                      <>
                        {' · '}
                        {humanise(event.entity_type)}
                        {event.entity_id ? ` ${event.entity_id}` : ''}
                      </>
                    )}
                  </small>
                  {event.reason && <small className="msg-muted">“{event.reason}”</small>}

                  {(event.before_state || event.after_state) && (
                    <>
                      <button
                        type="button"
                        className="msg-button msg-button-ghost msg-button-small"
                        aria-expanded={expanded === event.audit_id}
                        onClick={() => setExpanded(expanded === event.audit_id ? null : event.audit_id)}
                      >
                        {expanded === event.audit_id ? 'Hide what changed' : 'Show what changed'}
                      </button>

                      {expanded === event.audit_id && (
                        <StateDiff before={event.before_state} after={event.after_state} />
                      )}
                    </>
                  )}
                </div>
              </div>
            ))}
          </div>

          <div className="msg-actions">
            <Button tone="ghost" small disabled={offset === 0} onClick={() => setFilters({ offset: String(Math.max(0, offset - AUDIT_PAGE_SIZE)) })}>
              Newer
            </Button>
            <span className="msg-small msg-muted">
              {formatCount(offset + 1)}–{formatCount(Math.min(offset + AUDIT_PAGE_SIZE, total))} of{' '}
              {formatCount(total)}
            </span>
            <Button
              tone="ghost"
              small
              disabled={offset + AUDIT_PAGE_SIZE >= total}
              onClick={() => setFilters({ offset: String(offset + AUDIT_PAGE_SIZE) })}
            >
              Older
            </Button>
          </div>
        </>
      )}
    </Panel>
  )
}

/**
 * Before and after, field by field.
 *
 * Secrets never reach here — Audit::redact() strips them, and drops foreign
 * payloads entirely rather than storing another product's records. Where a
 * value was redacted, the placeholder it left behind is shown as it is, because
 * "[redacted]" in an audit trail is information.
 */
function StateDiff({
  before,
  after,
}: {
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
}) {
  const keys = [...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})])].sort()

  if (keys.length === 0) {
    return <p className="msg-small msg-muted">No field-level detail was recorded for this entry.</p>
  }

  return (
    <div className="msg-table-wrap">
      <table className="msg-table">
        <caption className="msg-visually-hidden">What changed</caption>
        <thead>
          <tr>
            <th scope="col">Field</th>
            <th scope="col">Before</th>
            <th scope="col">After</th>
          </tr>
        </thead>
        <tbody>
          {keys.map((key) => {
            const from = renderValue(before?.[key])
            const to = renderValue(after?.[key])
            const changed = from !== to

            return (
              <tr key={key}>
                <th scope="row">{humanise(key)}</th>
                <td className={changed ? undefined : 'msg-muted'}>{from}</td>
                <td>
                  {changed ? <strong>{to}</strong> : <span className="msg-muted">{to}</span>}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function renderValue(value: unknown): string {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (Array.isArray(value)) return value.length === 0 ? '—' : value.map((entry) => String(entry)).join(', ')
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}
