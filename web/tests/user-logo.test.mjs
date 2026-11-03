/**
 * The account menu's profile photo, and the Company block beside it.
 *
 * The photo comes from the portal through this product's own same-origin relay
 * (`/api/global/user/logo`), and every way that can fail — nobody uploaded one, the relay is
 * down, the portal answered a JSON error with a 200 — has to end as "no photo" so the menu draws
 * initials instead. Nothing may throw out of it and nothing may be reported to the person
 * (AICOUNTLY_ACCOUNT_MENU.md §6).
 *
 * The shipped module is loaded through Vite's SSR module graph under a minimal fake browser, the
 * same harness as tests/accounts.test.mjs. The menu itself is TSX whose panel only exists once it
 * is opened, which no renderer here can do, so the Company block is checked by reading the source
 * — which is what tests/ui.test.mjs does for the rest of the design contract.
 *
 *   npm run test:ui
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { after, afterEach, describe, it } from 'node:test'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

// ---------------------------------------------------------------------------
// A fake browser
// ---------------------------------------------------------------------------

function memoryStorage() {
  const data = new Map()
  return {
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => void data.set(key, String(value)),
    removeItem: (key) => void data.delete(key),
    clear: () => data.clear(),
  }
}

globalThis.window = {
  location: {
    href: 'https://messaging.aicountly.com/inbox',
    origin: 'https://messaging.aicountly.com',
    protocol: 'https:',
    host: 'messaging.aicountly.com',
    hostname: 'messaging.aicountly.com',
    pathname: '/inbox',
    search: '',
    hash: '',
    assign: () => {},
  },
  localStorage: memoryStorage(),
  sessionStorage: memoryStorage(),
  addEventListener: () => {},
  removeEventListener: () => {},
}
globalThis.localStorage = globalThis.window.localStorage
globalThis.sessionStorage = globalThis.window.sessionStorage
globalThis.document = { get cookie() { return '' }, set cookie(value) { void value } }

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const server = await createServer({
  root,
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const { fetchUserLogoObjectUrl } = await server.ssrLoadModule('/src/services/userLogo.ts')
const tokens = await server.ssrLoadModule('/src/auth/tokens.ts')

after(async () => {
  await server.close()
})

let calls = []
let created = 0
URL.createObjectURL = () => `blob:messaging/${++created}`

/** Answer the next relay call with this, and record what was asked for. */
function respondWith(answer) {
  globalThis.fetch = async (url, init = {}) => {
    calls.push({ url: String(url), headers: init.headers ?? {} })
    if (answer instanceof Error) throw answer
    return answer()
  }
}

const image = (type = 'image/png', bytes = Uint8Array.from([137, 80, 78, 71])) =>
  () => new Response(bytes, { status: 200, headers: { 'Content-Type': type } })

afterEach(() => {
  calls = []
  tokens.clearAllTokens()
})

describe('the account menu photo', () => {
  it("asks this product's own API, same-origin, with the person's ses_key", async () => {
    tokens.saveSession('ses-logo', 900)
    respondWith(image())

    const url = await fetchUserLogoObjectUrl()

    assert.equal(calls.length, 1)
    assert.equal(calls[0].url, 'https://messaging.aicountly.com/api/global/user/logo')
    assert.equal(calls[0].headers.Authorization, 'Bearer ses-logo')
    // Never my.aicountly.com: its CORS allow-list does not name product domains, and production
    // hosting can answer a cross-site call with a challenge page carrying no CORS headers at all.
    assert.ok(!calls[0].url.includes('my.aicountly.com'))
    assert.match(url, /^blob:messaging\//)
  })

  for (const [name, answer] of [
    ['nobody has uploaded a photo (204)', () => new Response(null, { status: 204 })],
    ['the portal has none for this person (404)', () => new Response('{}', { status: 404, headers: { 'Content-Type': 'application/json' } })],
    ['the relay is down (503)', () => new Response('', { status: 503 })],
    ['the answer is a 200 that is not an image', () => new Response('{"error":"nope"}', { status: 200, headers: { 'Content-Type': 'application/json' } })],
    ['the answer is an empty image body', image('image/png', new Uint8Array(0))],
  ]) {
    it(`falls back to initials when ${name}`, async () => {
      tokens.saveSession('ses-logo', 900)
      respondWith(answer)

      assert.equal(await fetchUserLogoObjectUrl(), null)
    })
  }

  it('falls back to initials when the network throws, rather than letting it out', async () => {
    tokens.saveSession('ses-logo', 900)
    respondWith(new TypeError('Failed to fetch'))

    assert.equal(await fetchUserLogoObjectUrl(), null)
  })

  it('falls back to initials when there is no session to ask with, and asks nothing', async () => {
    respondWith(image())

    assert.equal(await fetchUserLogoObjectUrl(), null)
    assert.equal(calls.length, 0)
  })
})

// ---------------------------------------------------------------------------
// The menu, read as source
// ---------------------------------------------------------------------------

const read = (...parts) => readFileSync(join(root, 'src', ...parts), 'utf8')
const menu = read('shell', 'AccountMenu.tsx')
/** The same source with its comments removed — so "no toast" is about the code, not the prose. */
const code = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')

describe('the photo in the menu', () => {
  it('is drawn in the identity block, not only on the trigger, with initials as the fallback', () => {
    const identity = menu.slice(menu.indexOf('shell-user shell-user-lg'), menu.indexOf('shell-account-section-title">Accounts'))
    assert.match(identity, /photo !== null/)
    assert.match(identity, /shell-user-photo/)
    assert.match(identity, /initials \|\| <User size=\{18\} \/>/)
  })

  it('revokes the object URL on unmount, so a blob never outlives the menu', () => {
    const hook = menu.slice(menu.indexOf('function useUserLogo'), menu.indexOf('export function AccountMenu'))
    assert.match(hook, /return \(\) => \{[\s\S]*URL\.revokeObjectURL\(objectUrl\)/)
    // A photo that arrives after unmount is released too, not leaked.
    assert.match(hook, /if \(cancelled\) \{[\s\S]*URL\.revokeObjectURL\(url\)/)
  })

  it('reports nothing when there is no photo: no toast, no error state', () => {
    assert.doesNotMatch(code(read('services', 'userLogo.ts')), /toast|notify|console\.(error|warn)/)
    assert.doesNotMatch(code(menu.slice(menu.indexOf('function useUserLogo'), menu.indexOf('export function AccountMenu'))), /toast|notify|console\.(error|warn)/)
  })
})

describe('the Company block', () => {
  const block = menu.slice(menu.indexOf('shell-account-section-title">Company'), menu.indexOf("manageUrl('/profile')"))

  it('is present: Messaging is company-scoped, and the picker in the bar is not a reason to omit it', () => {
    assert.notEqual(block, '')
  })

  it("lists the lines Messaging can perform, in Books' order", () => {
    const entries = [...block.matchAll(/(Switch company|Edit company|Manage branches)/g)].map((m) => m[1])
    assert.deepEqual(entries, ['Switch company', 'Edit company', 'Manage branches'])
  })

  it('switches company by opening the workspace-companies drawer beside it — one action, two doors', () => {
    assert.match(block, /window\.dispatchEvent\(new CustomEvent\(OPEN_COMPANY_SWITCHER_EVENT\)\)/)
    // …and that drawer really listens for it.
    const discovery = read('shell', 'CompanyDiscovery.tsx')
    assert.match(discovery, /export const OPEN_COMPANY_SWITCHER_EVENT = 'messaging:open-company-switcher'/)
    assert.match(discovery, /window\.addEventListener\(OPEN_COMPANY_SWITCHER_EVENT, onOpen\)/)
    assert.match(discovery, /window\.removeEventListener\(OPEN_COMPANY_SWITCHER_EVENT, onOpen\)/)
  })

  it('opens the company and its branches in Manage, in a new tab, with the id encoded and no token', () => {
    assert.match(block, /manageUrl\(`\/company\/\$\{encodeURIComponent\(String\(cmpId\)\)\}\/edit`\)/)
    assert.match(block, /manageUrl\(`\/branch\/\$\{encodeURIComponent\(String\(cmpId\)\)\}`\)/)
    assert.equal((block.match(/target="_blank"/g) ?? []).length, 2)
    assert.equal((block.match(/rel="noopener noreferrer"/g) ?? []).length, 2)
    // manageUrl builds a plain hash address on Manage's own origin; no token is ever put in one.
    assert.match(menu, /function manageUrl\(path: string\): string \{\s*return `\$\{getManageApiOrigin\(\)\}\/#\$\{path\}`/)
  })

  it('stands between the accounts block and the links, per the suite-wide order', () => {
    assert.ok(menu.indexOf('shell-account-section-title">Accounts') < menu.indexOf('shell-account-section-title">Company'))
    assert.ok(menu.indexOf('shell-account-section-title">Company') < menu.indexOf("manageUrl('/profile')"))
  })
})
