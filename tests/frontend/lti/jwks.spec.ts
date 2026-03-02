import { test, expect } from '@playwright/test';

/**
 * LTI JWKS Endpoint Tests
 *
 * GET /lti/jwks should return a valid JSON Web Key Set.
 */
test.describe('LTI JWKS', () => {
  test('returns 200 with JSON content type', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/json');
  });

  test('contains keys array', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    const json = await response.json();

    expect(json).toHaveProperty('keys');
    expect(Array.isArray(json.keys)).toBe(true);
    expect(json.keys.length).toBeGreaterThan(0);
  });

  test('key has required JWK fields', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    const json = await response.json();
    const key = json.keys[0];

    expect(key).toHaveProperty('kty', 'RSA');
    expect(key).toHaveProperty('n');
    expect(key).toHaveProperty('e');
    expect(key).toHaveProperty('kid');
    expect(key).toHaveProperty('use', 'sig');
  });

  test('key has correct algorithm', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    const json = await response.json();
    const key = json.keys[0];

    expect(key).toHaveProperty('alg', 'RS256');
  });

  test('response is cacheable', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    const cacheControl = response.headers()['cache-control'] || '';

    expect(cacheControl).toContain('public');
  });
});
