import { test, expect } from '@playwright/test';

/**
 * LTI Configuration XML Endpoint Tests
 *
 * GET /lti/configure-xml should return Canvas-compatible XML config.
 */
test.describe('LTI Config XML', () => {
  test('returns 200 with XML content type', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/xml');
  });

  test('contains valid XML with cartridge root element', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    const body = await response.text();

    expect(body).toContain('<?xml version="1.0"');
    expect(body).toContain('<cartridge_basiclti_link');
    expect(body).toContain('</cartridge_basiclti_link>');
  });

  test('contains required blti elements', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    const body = await response.text();

    expect(body).toContain('<blti:title>');
    expect(body).toContain('<blti:description>');
    expect(body).toContain('<blti:launch_url>');
  });

  test('contains Canvas extensions', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    const body = await response.text();

    expect(body).toContain('platform="canvas.instructure.com"');
    expect(body).toContain('course_navigation');
  });

  test('launch URL points to /lti/launch', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    const body = await response.text();

    expect(body).toContain('/lti/launch</blti:launch_url>');
  });
});
