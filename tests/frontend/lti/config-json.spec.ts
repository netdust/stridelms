import { test, expect } from '@playwright/test';

/**
 * LTI Configuration JSON Endpoint Tests
 *
 * GET /lti/configure-json should return valid IMS LTI 1.3 tool configuration.
 */
test.describe('LTI Config JSON', () => {
  test('returns 200 with JSON content type', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/json');
  });

  test('contains required IMS fields', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    expect(json).toHaveProperty('title');
    expect(json).toHaveProperty('oidc_initiation_url');
    expect(json).toHaveProperty('target_link_uri');
    expect(json).toHaveProperty('jwks_uri');
    expect(json).toHaveProperty('claims');
    expect(json).toHaveProperty('messages');
    expect(json).toHaveProperty('scopes');
  });

  test('URLs point to /lti/ paths', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    expect(json.oidc_initiation_url).toContain('/lti/login');
    expect(json.target_link_uri).toContain('/lti/launch');
    expect(json.jwks_uri).toContain('/lti/jwks');
  });

  test('messages include resource link and deep linking', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    const types = json.messages.map((m: any) => m.type);
    expect(types).toContain('LtiResourceLinkRequest');
    expect(types).toContain('LtiDeepLinkingRequest');
  });

  test('scopes include AGS lineitem and score', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    expect(json.scopes).toContain('https://purl.imsglobal.org/spec/lti-ags/scope/lineitem');
    expect(json.scopes).toContain('https://purl.imsglobal.org/spec/lti-ags/scope/score');
  });

  test('claims include required identity claims', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    expect(json.claims).toContain('sub');
    expect(json.claims).toContain('email');
    expect(json.claims).toContain('name');
  });
});
