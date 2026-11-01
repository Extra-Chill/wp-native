import assert from 'node:assert/strict';
import test from 'node:test';
import {
  ApiError,
  AuthFetchTransport,
  FetchTransport,
  WPNativeClient,
} from '../src/index.ts';
import { WpApiFetchTransport } from '../src/wordpress.ts';

const ROOT = 'https://primary.test/wp-json';
const ALTERNATE = 'https://alternate.test/wp-json';
const FUTURE_EXPIRY = Math.floor(Date.now() / 1000) + 3600;

function jsonResponse(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

async function authTransport(overrides = {}) {
  const transport = new AuthFetchTransport({
    baseUrl: ROOT,
    allowedBaseUrls: [ALTERNATE],
    getDeviceId: () => 'device-id',
    loadTokens: () => null,
    saveTokens: () => {},
    clearTokens: () => {},
    ...overrides,
  });
  await transport.setTokens({
    accessToken: 'access-token',
    refreshToken: 'refresh-token',
    accessExpiresAt: FUTURE_EXPIRY,
  });
  return transport;
}

async function withFetch(handler, fn) {
  const originalFetch = globalThis.fetch;
  const calls = [];
  globalThis.fetch = async (url, init) => {
    calls.push({ url: String(url), init });
    return handler(String(url), init);
  };
  try {
    await fn(calls);
  } finally {
    globalThis.fetch = originalFetch;
  }
}

function recordingTransport(response = { ok: true }) {
  const requests = [];
  return {
    requests,
    async request(req) {
      requests.push(req);
      return response;
    },
  };
}

test('JSON GET encodes query with ability-style bracket notation', async () => {
  const t = recordingTransport([{ id: 1 }]);
  const result = await new WPNativeClient(t).request({
    path: 'extrachill/v1/community/topics/42/editor',
    query: { context: 'edit', include: [1, 2], filter: { status: 'publish' }, skip: null },
  });
  assert.deepEqual(result, [{ id: 1 }]);
  assert.equal(t.requests[0].method, 'GET');
  assert.equal(
    t.requests[0].path,
    'extrachill/v1/community/topics/42/editor?context=edit&include%5B0%5D=1&include%5B1%5D=2&filter%5Bstatus%5D=publish',
  );
  assert.equal('body' in t.requests[0], false);
});

test('JSON GET through AuthFetchTransport hits the root with bearer auth', async () => {
  const transport = await authTransport();
  await withFetch(
    () => jsonResponse({ content: 'hello' }),
    async (calls) => {
      const result = await new WPNativeClient(transport).request({
        path: '/extrachill/v1/community/topics/42/editor',
        query: { context: 'edit' },
      });
      assert.deepEqual(result, { content: 'hello' });
      assert.equal(calls[0].url, `${ROOT}/extrachill/v1/community/topics/42/editor?context=edit`);
      assert.equal(calls[0].init.method, 'GET');
      assert.equal(calls[0].init.headers.Authorization, 'Bearer access-token');
      assert.equal(calls[0].init.body, null);
    },
  );
});

test('JSON POST sends a JSON body with JSON content type and auth', async () => {
  const transport = await authTransport();
  await withFetch(
    () => jsonResponse({ saved: true }),
    async (calls) => {
      const result = await new WPNativeClient(transport).request({
        path: 'extrachill/v1/community/topics/42/editor',
        method: 'POST',
        body: { content: '<p>hi</p>' },
        headers: { 'X-Custom': 'yes' },
      });
      assert.deepEqual(result, { saved: true });
      assert.equal(calls[0].init.method, 'POST');
      assert.equal(calls[0].init.headers['Content-Type'], 'application/json');
      assert.equal(calls[0].init.headers['X-Custom'], 'yes');
      assert.equal(calls[0].init.headers.Authorization, 'Bearer access-token');
      assert.deepEqual(JSON.parse(calls[0].init.body), { content: '<p>hi</p>' });
    },
  );
});

test('FormData POST is passed through untouched with no JSON content type (AuthFetchTransport)', async () => {
  const transport = await authTransport();
  const form = new FormData();
  form.append('file', new Blob(['png-bytes'], { type: 'image/png' }), 'photo.png');
  form.append('context', 'post');

  await withFetch(
    () => jsonResponse({ id: 99, url: 'https://primary.test/photo.png' }),
    async (calls) => {
      const result = await new WPNativeClient(transport).request({
        path: 'extrachill/v1/media',
        method: 'POST',
        body: form,
        headers: { 'Content-Type': 'application/json' },
      });
      assert.deepEqual(result, { id: 99, url: 'https://primary.test/photo.png' });
      const { init } = calls[0];
      assert.equal(calls[0].url, `${ROOT}/extrachill/v1/media`);
      assert.strictEqual(init.body, form);
      const headerNames = Object.keys(init.headers).map((name) => name.toLowerCase());
      assert.equal(headerNames.includes('content-type'), false);
      assert.equal(init.headers.Authorization, 'Bearer access-token');

      // The runtime generates a multipart boundary from the untouched body.
      const encoded = new Request('https://x.test', { method: 'POST', body: init.body });
      assert.match(encoded.headers.get('content-type'), /^multipart\/form-data; boundary=/);
    },
  );
});

test('FormData POST is passed through untouched (FetchTransport)', async () => {
  const transport = new FetchTransport({
    baseUrl: ROOT,
    getAuthHeaders: () => ({ Authorization: 'Basic abc' }),
  });
  const form = new FormData();
  form.append('file', new Blob(['bytes']), 'a.bin');

  await withFetch(
    () => jsonResponse({ id: 1 }),
    async (calls) => {
      await new WPNativeClient(transport).request({
        path: 'extrachill/v1/media',
        method: 'POST',
        body: form,
      });
      assert.strictEqual(calls[0].init.body, form);
      assert.equal('Content-Type' in calls[0].init.headers, false);
      assert.equal(calls[0].init.headers.Authorization, 'Basic abc');
    },
  );
});

test('FormData POST is passed through as body, not data (WpApiFetchTransport)', async () => {
  const seen = [];
  const transport = new WpApiFetchTransport(async (options) => {
    seen.push(options);
    return { id: 1 };
  });
  const form = new FormData();
  form.append('file', new Blob(['bytes']), 'a.bin');

  await new WPNativeClient(transport).request({
    path: 'extrachill/v1/media',
    method: 'POST',
    body: form,
  });
  assert.strictEqual(seen[0].body, form);
  assert.equal('data' in seen[0], false);
  assert.equal(seen[0].path, 'extrachill/v1/media');

  await new WPNativeClient(transport).request({
    path: 'extrachill/v1/thing',
    method: 'POST',
    body: { a: 1 },
  });
  assert.deepEqual(seen[1].data, { a: 1 });
  assert.equal('body' in seen[1], false);
});

test('rejects absolute, protocol-relative, and traversal paths without sending', async () => {
  const t = recordingTransport();
  const client = new WPNativeClient(t);
  const bad = [
    'https://evil.test/wp-json/x',
    'HTTP://evil.test/x',
    'javascript:alert(1)',
    '//evil.test/x',
    '\\\\evil.test/x',
    '/\\evil.test/x',
    '../wp-admin',
    'extrachill/v1/../../secret',
    'extrachill/v1/%2e%2e/secret',
    'extrachill/v1/%2E%2E/secret',
    'extrachill/v1/./x',
    'extrachill/v1/x#frag',
    '',
    '/',
    '   ',
  ];
  for (const path of bad) {
    await assert.rejects(client.request({ path }), Error, `expected rejection for ${JSON.stringify(path)}`);
  }
  assert.equal(t.requests.length, 0);
});

test('derived clients send requests to their own REST root', async () => {
  const transport = await authTransport();
  const alternateClient = new WPNativeClient(transport).derive(ALTERNATE);
  await withFetch(
    () => jsonResponse({ ok: true }),
    async (calls) => {
      await alternateClient.request({ path: 'extrachill/v1/media', method: 'POST', body: { a: 1 } });
      assert.equal(calls[0].url, `${ALTERNATE}/extrachill/v1/media`);
      assert.equal(calls[0].init.headers.Authorization, 'Bearer access-token');
    },
  );
});

test('WP_Error responses map to ApiError like ability execution', async () => {
  const transport = await authTransport();
  await withFetch(
    () =>
      jsonResponse(
        { code: 'rest_forbidden', message: 'You cannot edit this topic.', data: { status: 403 } },
        403,
      ),
    async () => {
      await assert.rejects(
        new WPNativeClient(transport).request({ path: 'extrachill/v1/community/topics/1/editor' }),
        (error) => {
          assert.ok(error instanceof ApiError);
          assert.equal(error.code, 'rest_forbidden');
          assert.equal(error.status, 403);
          assert.equal(error.message, 'You cannot edit this topic.');
          return true;
        },
      );
    },
  );
});

test('401 on request() triggers the transport refresh-and-retry path', async () => {
  const transport = await authTransport();
  await withFetch(
    (url, init) => {
      if (url === `${ROOT}/wp-native/v1/auth/refresh`) {
        return jsonResponse({
          access_token: 'new-access',
          refresh_token: 'new-refresh',
          access_expires_at: new Date((FUTURE_EXPIRY + 3600) * 1000).toISOString(),
        });
      }
      if (init.headers.Authorization === 'Bearer access-token') {
        return jsonResponse({ code: 'unauthorized' }, 401);
      }
      return jsonResponse({ ok: true });
    },
    async (calls) => {
      const result = await new WPNativeClient(transport).request({ path: 'extrachill/v1/media' });
      assert.deepEqual(result, { ok: true });
      assert.equal(calls.at(-1).init.headers.Authorization, 'Bearer new-access');
    },
  );
});
