/**
 * The left navigation.
 *
 * Two groups, separated. The first five are the workspaces the brief names,
 * and everything below the divider is a supporting surface.
 *
 * `permission` hides an item somebody cannot use. That is a COURTESY: the
 * backend asserts every permission before the query, so hiding a link saves a
 * wasted click and nothing more.
 */

import {
  BarChart3,
  Inbox,
  LayoutDashboard,
  Route,
  Settings as SettingsIcon,
  ShieldCheck,
  Users,
  type LucideIcon,
} from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  exact?: boolean
  permission?: string
  /** Renders a divider above this item. */
  separator?: boolean
}

export const NAV: NavItem[] = [
  { to: '/', label: 'Command Centre', icon: LayoutDashboard, exact: true, permission: 'messaging.command_centre.view' },
  { to: '/inbox', label: 'Unified Inbox', icon: Inbox, permission: 'messaging.conversations.view' },
  { to: '/journeys', label: 'Journeys & Templates', icon: Route, permission: 'messaging.journeys.view' },
  { to: '/outcomes', label: 'Business Outcomes', icon: BarChart3, permission: 'messaging.outcomes.view' },
  { to: '/trust', label: 'Channels & Trust', icon: ShieldCheck, permission: 'messaging.channels.view' },

  { to: '/contacts', label: 'Contacts', icon: Users, separator: true, permission: 'messaging.conversations.view' },
  { to: '/settings', label: 'Settings', icon: SettingsIcon },
]

/**
 * The five workspaces, as the route table and the page headers both read them.
 *
 * Titles and subtitles live here rather than in five components so renaming a
 * workspace is one edit.
 */
export const WORKSPACES = [
  {
    id: 'command',
    path: '/',
    label: 'Command Centre',
    title: 'Messaging Command Centre',
    subtitle: 'Every conversation. A clearer next step.',
  },
  {
    id: 'inbox',
    path: '/inbox',
    label: 'Unified Inbox',
    title: 'Unified Inbox',
    subtitle: 'Context-aware conversations, with you in control.',
  },
  {
    id: 'journeys',
    path: '/journeys',
    label: 'Journeys & Templates',
    title: 'Journeys & Templates',
    subtitle: 'Turn intent into a reviewed messaging journey.',
  },
  {
    id: 'outcomes',
    path: '/outcomes',
    label: 'Business Outcomes',
    title: 'Business Outcomes',
    subtitle: 'Connect messaging activity to measurable business events.',
  },
  {
    id: 'trust',
    path: '/trust',
    label: 'Channels & Trust',
    title: 'Channels & Trust',
    subtitle: 'Healthy delivery. Clear consent. Controlled AI.',
  },
] as const

/**
 * Where a suggestion's action goes.
 *
 * The server sends a route NAME and a set of filters — never a URL. Nothing
 * arriving from an API, or from anything that influenced one, can point a
 * browser at an address this application did not choose. That matters more
 * than usual here: a suggestion's inputs include text a customer wrote.
 */
export const ROUTES: Record<string, string> = {
  command: '/',
  inbox: '/inbox',
  journeys: '/journeys',
  templates: '/journeys?tab=templates',
  outcomes: '/outcomes',
  trust: '/trust',
  delivery: '/trust?tab=delivery',
  consent: '/trust?tab=consent',
  contacts: '/contacts',
  settings: '/settings',
  access: '/settings?tab=access',
  audit: '/settings?tab=audit',
}

export function resolveRoute(
  route: string | null | undefined,
  params?: Record<string, string | number>,
): string | null {
  if (!route) return null

  const base = ROUTES[route]
  if (!base) return null

  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    search.set(key, String(value))
  }

  if (!search.toString()) return base
  return base.includes('?') ? `${base}&${search}` : `${base}?${search}`
}
