import assert from 'node:assert/strict';
import test from 'node:test';
import {
  ApiError,
  AuthFetchTransport,
  FetchTransport,
  WPNativeClient,
  isApiError,
} from '../src/index.ts';
import { WpApiFetchTransport } from '../src/wordpress.ts';

const ROOT = 'https://primary.test/wp-json';
const FUTURE_EXPIRY = Math.floor(Date.now() / 1000) + 3600;
const ABILITY = 'demo/verify-code';
const ABILITY_PATH = `${ROOT}/wp-abilities/v1/abilities/${ABILITY}`;
const DESCRIPTOR = {
  name: ABILITY,
  label: 'Verify code',
  description: '',
  category: 'demo',
  input_schema: { type: 'object' },
  meta: { annotations: { readonly: false } },
};

function jsonResponse(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

async function withFetch(handler, fn) {
  const originalFetch = globalThis.fetch;
  const calls = [];
  globalThis.fetch = async (url, init) => {
    calls.push({ url: String(url), init });
    return handler(String(url), init, calls.length);
  };
  try {
    await fn(calls);
  } finally {
    globalThis.fetch = originalFetch;
  }
}

async function authTransport(overrides = {}) {
  const transport = new AuthFetchTransport({
    baseUrl: ROOT,
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

/** Serve the ability descriptor, and `runResponse` for the run endpoint. */
function abilityHandler(runResponse) {
  return (url) => (url === ABILITY_PATH ? jsonResponse(DESCRIPTOR) : runResponse(url));
}

const TOO_FAST = {
  code: 'two_factor_too_fast',
  message: 'Please wait before trying again.',
  data: { status: 429, retry_after: 27 },
};

function assertTooFast(error) {
  assert.ok(error instanceof ApiError);
  assert.ok(isApiError(error));
  assert.equal(error.name, 'ApiError');
  assert.equal(error.code, 'two_factor_too_fast');
  assert.equal(error.status, 429);
  assert.equal(error.message, 'Please wait before trying again.');
  assert.deepEqual(error.data, { status: 429, retry_after: 27 });
  return true;
}

test('FetchTransport ability errors keep WP_Error data (pre-auth executeUnchecked)', async () => {
  const client = new WPNativeClient(new FetchTransport({ baseUrl: ROOT }));
  await withFetch(abilityHandler(() => jsonResponse(TOO_FAST, 429)), async () => {
    await assert.rejects(client.executeUnchecked(ABILITY, { code: '1' }), assertTooFast);
  });
});

test('AuthFetchTransport ability errors keep WP_Error data', async () => {
  const client = new WPNativeClient(await authTransport());
  await withFetch(abilityHandler(() => jsonResponse(TOO_FAST, 429)), async () => {
    await assert.rejects(client.execute(ABILITY, { code: '1' }), assertTooFast);
  });
});

test('request() errors keep WP_Error data, including nested objects', async () => {
  const body = {
    code: 'revalidation_required',
    message: 'Confirm it is you.',
    data: { status: 403, revalidation: { required: true, rest_route: '/two-factor/1.0/revalidate' } },
  };
  const client = new WPNativeClient(await authTransport());
  await withFetch(() => jsonResponse(body, 403), async () => {
    await assert.rejects(
      client.request({ path: 'two-factor/1.0/users/7/providers', method: 'POST', body: {} }),
      (error) => {
        assert.ok(isApiError(error));
        assert.equal(error.code, 'revalidation_required');
        assert.equal(error.status, 403);
        assert.deepEqual(error.data.revalidation, { required: true, rest_route: '/two-factor/1.0/revalidate' });
        return true;
      },
    );
  });
});

test('a non-auth 401 that survives refresh-and-retry keeps its WP_Error data', async () => {
  const rejected = {
    code: 'two_factor_invalid',
    message: 'Invalid verification code.',
    data: { status: 401, continuation_token: 'rotated-token' },
  };
  const transport = await authTransport();
  let refreshed = 0;
  await withFetch(
    (url) => {
      if (url === `${ROOT}/wp-native/v1/auth/refresh`) {
        refreshed += 1;
        return jsonResponse({
          access_token: 'new-access',
          refresh_token: 'new-refresh',
          access_expires_at: new Date((FUTURE_EXPIRY + 3600) * 1000).toISOString(),
        });
      }
      return jsonResponse(rejected, 401);
    },
    async () => {
      await assert.rejects(
        new WPNativeClient(transport).request({ path: 'demo/v1/verify', method: 'POST', body: { code: '1' } }),
        (error) => {
          assert.ok(isApiError(error));
          assert.equal(error.code, 'two_factor_invalid');
          assert.equal(error.status, 401);
          assert.equal(error.message, 'Invalid verification code.');
          assert.equal(error.data.continuation_token, 'rotated-token');
          return true;
        },
      );
      assert.equal(refreshed, 1);
    },
  );
});

test('error bodies without WP_Error fields fall back to the old message/code and empty data', async () => {
  const fetchClient = new WPNativeClient(new FetchTransport({ baseUrl: ROOT }));
  const authClient = new WPNativeClient(await authTransport());
  await withFetch(() => new Response('<html>Bad gateway</html>', { status: 502 }), async () => {
    for (const client of [fetchClient, authClient]) {
      await assert.rejects(client.request({ path: 'demo/v1/thing' }), (error) => {
        assert.ok(error instanceof ApiError);
        assert.equal(error.message, 'Request failed with status 502');
        assert.equal(error.code, 'request_failed');
        assert.equal(error.status, 502);
        assert.deepEqual(error.data, {});
        return true;
      });
    }
  });
  // Non-object `data` is dropped rather than mis-typed.
  await withFetch(() => jsonResponse({ code: 'x', message: 'y', data: 'nope' }, 400), async () => {
    await assert.rejects(fetchClient.request({ path: 'demo/v1/thing' }), (error) => {
      assert.deepEqual(error.data, {});
      return true;
    });
  });
});

test('an unrecoverable 401 without a body still ends as session_expired with empty data', async () => {
  const transport = await authTransport();
  await withFetch(
    (url) => (url.endsWith('/auth/refresh') ? jsonResponse({}, 401) : new Response(null, { status: 401 })),
    async () => {
      await assert.rejects(new WPNativeClient(transport).request({ path: 'demo/v1/thing' }), (error) => {
        assert.ok(error instanceof ApiError);
        assert.equal(error.status, 401);
        assert.equal(error.code, 'session_expired');
        assert.deepEqual(error.data, {});
        return true;
      });
    },
  );
});

test('WpApiFetchTransport keeps api-fetch error data', async () => {
  const transport = new WpApiFetchTransport(async () => {
    throw TOO_FAST;
  });
  await assert.rejects(new WPNativeClient(transport).request({ path: 'demo/v1/thing' }), assertTooFast);
  const noData = new WpApiFetchTransport(async () => {
    throw { code: 'fetch_error', message: 'You are probably offline.' };
  });
  await assert.rejects(noData.request({ path: 'x', method: 'GET' }), (error) => {
    assert.equal(error.status, 500);
    assert.deepEqual(error.data, {});
    return true;
  });
});

test('ApiError stays constructible with three arguments and isApiError duck-types copies', () => {
  const legacy = new ApiError('Nope', 'nope', 400);
  assert.deepEqual(legacy.data, {});
  assert.ok(legacy instanceof Error);

  // An ApiError from a duplicate copy of the package fails instanceof.
  const foreign = Object.assign(new Error('Slow down'), { name: 'ApiError', code: 'x', status: 429, data: {} });
  assert.equal(foreign instanceof ApiError, false);
  assert.equal(isApiError(foreign), true);
  assert.equal(isApiError(new Error('plain')), false);
  assert.equal(isApiError({ name: 'ApiError', code: 'x', status: 1 }), false);
  assert.equal(isApiError(null), false);
});
