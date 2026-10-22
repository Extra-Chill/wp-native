import assert from 'node:assert/strict';
import test from 'node:test';
import { WPNativeClient } from '../src/index.ts';

function descriptor(name, inputSchema, annotations = {}) {
  return {
    name,
    label: name,
    description: name,
    category: 'test',
    input_schema: inputSchema,
    output_schema: {},
    meta: { annotations },
  };
}

function recordingTransport(abilities) {
  const requests = [];
  return {
    requests,
    async request(req) {
      const match = req.path.match(/^wp-abilities\/v1\/abilities\/(.+?)(\/run)?(\?.*)?$/);
      if (match && !match[2]) {
        return abilities[decodeURIComponent(match[1])];
      }
      requests.push(req);
      return { ok: true };
    },
  };
}

const OBJECT = { type: 'object', properties: {} };

test('POST sends {} when an object-schema ability is executed without input', async () => {
  const t = recordingTransport({ 'x/write': descriptor('x/write', OBJECT) });
  await new WPNativeClient(t).execute('x/write');
  assert.equal(t.requests[0].method, 'POST');
  assert.deepEqual(t.requests[0].body, { input: {} });
});

test('GET sends ?input= when a readonly object-schema ability is executed without input', async () => {
  const t = recordingTransport({ 'x/read': descriptor('x/read', OBJECT, { readonly: true }) });
  await new WPNativeClient(t).execute('x/read');
  assert.equal(t.requests[0].method, 'GET');
  assert.equal(t.requests[0].path, 'wp-abilities/v1/abilities/x/read/run?input=');
});

test('DELETE sends ?input= for destructive idempotent object-schema abilities', async () => {
  const t = recordingTransport({
    'x/del': descriptor('x/del', OBJECT, { destructive: true, idempotent: true }),
  });
  await new WPNativeClient(t).execute('x/del');
  assert.equal(t.requests[0].method, 'DELETE');
  assert.equal(t.requests[0].path, 'wp-abilities/v1/abilities/x/del/run?input=');
});

test('type arrays including object are treated as object schemas', async () => {
  const t = recordingTransport({ 'x/mixed': descriptor('x/mixed', { type: ['object', 'null'] }) });
  await new WPNativeClient(t).execute('x/mixed');
  assert.deepEqual(t.requests[0].body, { input: {} });
});

test('explicit null input is preserved', async () => {
  const t = recordingTransport({ 'x/write': descriptor('x/write', OBJECT) });
  await new WPNativeClient(t).execute('x/write', null);
  assert.deepEqual(t.requests[0].body, { input: null });
  const r = recordingTransport({ 'x/read': descriptor('x/read', OBJECT, { readonly: true }) });
  await new WPNativeClient(r).execute('x/read', null);
  assert.equal(r.requests[0].path, 'wp-abilities/v1/abilities/x/read/run');
});

test('non-object schemas keep null / omitted input', async () => {
  const t = recordingTransport({
    'x/none': descriptor('x/none', {}),
    'x/str': descriptor('x/str', { type: 'string' }, { readonly: true }),
  });
  const client = new WPNativeClient(t);
  await client.execute('x/none');
  await client.execute('x/str');
  assert.deepEqual(t.requests[0].body, { input: null });
  assert.equal(t.requests[1].path, 'wp-abilities/v1/abilities/x/str/run');
});

test('provided input is sent unchanged', async () => {
  const t = recordingTransport({ 'x/read': descriptor('x/read', OBJECT, { readonly: true }) });
  await new WPNativeClient(t).executeUnchecked('x/read', { per_page: 5 });
  assert.equal(t.requests[0].path, 'wp-abilities/v1/abilities/x/read/run?input%5Bper_page%5D=5');
});
