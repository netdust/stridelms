/**
 * Unit: test-login key computation (the admin auth fixture's seam).
 *
 * Tier A — this is the auth-key predicate the test-login backdoor
 * (web/app/mu-plugins/test-login-helper.php) validates with hash_equals().
 * A wrong algorithm or stale secret produces a key the backend REJECTS, so
 * the computation has a real, falsifiable contract and a denial path.
 *
 * No browser, no DDEV — pure function asserted against a known HMAC vector
 * and against the OLD (md5) algorithm that the backend rejects.
 */

import { test, expect } from '@playwright/test';
import * as crypto from 'crypto';
import { computeTestLoginKey } from './fixtures/admin-helpers';

// Backend contract: hash_hmac('sha256', 'login:' . $userId, $secret).
function backendExpectedKey(userId: number, secret: string): string {
  return crypto
    .createHmac('sha256', secret)
    .update(`login:${userId}`)
    .digest('hex');
}

// The OLD, broken algorithm the fixture used before this task: md5 over a
// `stride_test_<id>_<secret>` message. The backend never accepted this.
function oldBrokenKey(userId: number, secret: string): string {
  return crypto
    .createHash('md5')
    .update(`stride_test_${userId}_${secret}`)
    .digest('hex');
}

test.describe('computeTestLoginKey', () => {
  test('matches the backend HMAC-SHA256 vector for a known id + secret', () => {
    const userId = 13740;
    const secret = 'somesecret';

    expect(computeTestLoginKey(userId, secret)).toBe(
      backendExpectedKey(userId, secret),
    );
  });

  test('reproduces the documented live vector for the real seeded admin', () => {
    // Vector verified in-container this session for the 64-char secret:
    //   hash_hmac('sha256', 'login:13740', <secret>)
    //     = 132d1980365acd85dfcfaa4e145227497d25c05cff4e3a78575d6b69e2b39da2
    // We can't read the real secret here, but we prove the algorithm is the
    // one that produced it: re-deriving with the SAME secret gives the SAME
    // vector. (Asserted indirectly via the algorithm-equivalence above.)
    const secret = process.env.STRIDE_TEST_LOGIN_SECRET;
    test.skip(
      !secret,
      'real secret not exported to host — algorithm verified by the synthetic vector above',
    );
    expect(computeTestLoginKey(13740, secret!)).toBe(
      '132d1980365acd85dfcfaa4e145227497d25c05cff4e3a78575d6b69e2b39da2',
    );
  });

  test('the OLD md5 algorithm would be REJECTED by the backend (denial path)', () => {
    const userId = 13740;
    const secret = 'somesecret';

    // The previous fixture computed this. The backend validates with
    // hash_equals against the HMAC, so this key must NOT equal what the
    // backend expects — proving the old fixture was sending a bad key.
    expect(oldBrokenKey(userId, secret)).not.toBe(
      backendExpectedKey(userId, secret),
    );
    // And the new function must NOT reproduce the old broken key.
    expect(computeTestLoginKey(userId, secret)).not.toBe(
      oldBrokenKey(userId, secret),
    );
  });

  test('a tampered secret yields a key the backend rejects (denial path)', () => {
    const userId = 13740;
    const realKey = computeTestLoginKey(userId, 'realsecret');
    const tamperedKey = computeTestLoginKey(userId, 'realsecret-TAMPERED');

    expect(tamperedKey).not.toBe(realKey);
    expect(tamperedKey).not.toBe(backendExpectedKey(userId, 'realsecret'));
  });
});
