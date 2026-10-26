/**
 * No blank errors: every API call sends a fresh X-Request-Id, and a 5xx names the reference.
 *
 *   npm run test:ui
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { errorMessage, newRequestId, requestIdOf } from '../src/services/requestRef.ts'

const api = readFileSync(new URL('../src/services/api.ts', import.meta.url), 'utf8')

describe('request references', () => {
  it('an id is a fresh random 32-hex string', () => {
    const a = newRequestId()
    const b = newRequestId()
    assert.match(a, /^[0-9a-f]{32}$/)
    assert.match(b, /^[0-9a-f]{32}$/)
    assert.notEqual(a, b)
  })

  it('every fetch in the API client sends X-Request-Id', () => {
    const fetches = api.split('\n').filter((line) => /\bfetch\(/.test(line) && !/^\s*(\/\/|\*)/.test(line))
    const sends = api.match(/'X-Request-Id': requestId/g) ?? []
    assert.ok(fetches.length >= 1)
    assert.equal(sends.length, fetches.length)
  })

  it('the reference is what the server answered with, else what was sent', () => {
    const withHeader = new Response('', { status: 500, headers: { 'X-Request-Id': 'srv-ref-1234' } })
    assert.equal(requestIdOf(withHeader, null, 'sent-123456'), 'srv-ref-1234')
    assert.equal(requestIdOf(new Response('', { status: 500 }), { request_id: 'body-ref-99' }, 'sent-123456'), 'body-ref-99')
    assert.equal(requestIdOf(new Response('', { status: 500 }), { error: { request_id: 'err-ref-77' } }, 'sent-123456'), 'err-ref-77')
    assert.equal(requestIdOf(new Response('', { status: 500 }), null, 'sent-123456'), 'sent-123456')
  })

  it('an empty 5xx is never a bare "Request failed (500)": it says what happened and quotes the Ref', () => {
    const message = errorMessage(500, undefined, 'abc123def456')
    assert.notEqual(message, 'Request failed (500)')
    assert.match(message, /unexpected error/)
    assert.ok(message.endsWith(' (Ref: abc123def456)'))
  })

  it('a 5xx message gets the Ref once; a message that already quotes it is left alone', () => {
    assert.equal(errorMessage(503, 'Customer 360 is unreachable.', 'ref-000001'), 'Customer 360 is unreachable. (Ref: ref-000001)')
    assert.equal(errorMessage(500, 'Quote reference ref-000002 if it keeps failing.', 'ref-000002'), 'Quote reference ref-000002 if it keeps failing.')
  })

  it('a 4xx keeps the server\'s sentence', () => {
    assert.equal(errorMessage(422, 'Pick a time.', 'ref-000003'), 'Pick a time.')
    assert.equal(errorMessage(404, undefined, 'ref-000004'), 'Request failed (404)')
  })
})
