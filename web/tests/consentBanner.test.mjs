import { test } from 'node:test'
import { verifyNoGa4 } from '../scripts/check-ga4-retired.mjs'
test('retired GA4 cannot load tags or display consent UI', verifyNoGa4)
