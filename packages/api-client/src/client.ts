/**
 * WPNativeClient — universal WordPress client built on the Abilities API.
 *
 * One client. No subclasses. No per-site wrappers.
 *
 * The client wraps a Transport (FetchTransport, AuthFetchTransport, or
 * WpApiFetchTransport) and exposes two surfaces:
 *
 *   1. Discovery — fetch the site's ability catalog at startup
 *   2. Execution — call abilities by name with typed input/output
 *
 * Site-specific concerns (extrachill/* abilities, woocommerce/* abilities,
 * etc.) are addressed via ability namespacing on the server side. The
 * client itself never knows the difference between a core WP ability and
 * a plugin-registered one — they're all just strings + JSON Schemas.
 *
 * Example:
 *
 *   import { WPNativeClient, AuthFetchTransport } from 'wp-native-client';
 *
 *   const client = new WPNativeClient(
 *     new AuthFetchTransport({ baseUrl: 'https://example.com/wp-json', ... })
 *   );
 *
 *   await client.discover();
 *
 *   const posts = await client.execute<Post[]>('wp/post.list', { per_page: 20 });
 *   const me    = await client.execute<User>('wp-native/user.me');
 */

import type { DerivableTransport, Transport, TransportRequest } from './transports/types';
import { AbilityCatalog } from './abilities/catalog';
import { discoverAbilities, encodeAbilityName, fetchAbility } from './abilities/discovery';
import type {
  AbilityDescriptor,
} from './abilities/types';

const RUN_PATH_PREFIX = 'wp-abilities/v1/abilities';

export interface WPNativeClientConfig {
  /**
   * Auto-fail execute() calls for abilities not present in the catalog.
   *
   * When true (default), execute() throws synchronously if the ability
   * name is not registered on the site, before making the HTTP request.
   * This catches typos and missing-plugin scenarios at the call site.
   *
   * Set to false if you want to call abilities before discover() has
   * run (auth bootstrap, for example) — but prefer executeUnchecked()
   * for that case so the intent is explicit.
   */
  validateAbilityNames?: boolean;
}

/**
 * A request to a plain REST route on the client's REST root.
 *
 * `path` is relative to the root (e.g. `extrachill/v1/media`). Absolute URLs,
 * protocol-relative URLs and `..` traversal are rejected so the transport's
 * credentials can never be sent off-root.
 */
export interface WPNativeRequest {
  path: string;
  method?: TransportRequest['method'];
  /** Encoded with the same bracket notation used for ability input. */
  query?: Record<string, unknown>;
  /** JSON object, or FormData for multipart uploads (sent untouched). */
  body?: Record<string, unknown> | FormData;
  headers?: Record<string, string>;
}

export class WPNativeClient {
  private readonly transport: Transport;
  private readonly config: Required<WPNativeClientConfig>;
  private _catalog: AbilityCatalog | null = null;
  private readonly descriptors = new Map<string, AbilityDescriptor>();

  constructor(transport: Transport, config: WPNativeClientConfig = {}) {
    this.transport = transport;
    this.config = {
      validateAbilityNames: config.validateAbilityNames ?? true,
    };
  }

  /**
   * Create a client for another REST root while retaining transport-owned state.
   *
   * Each derived client owns an independent ability catalog. The transport
   * decides which REST roots are approved and which state is safe to share.
   */
  derive(baseUrl: string): WPNativeClient {
    if (!isDerivableTransport(this.transport)) {
      throw new Error('WPNativeClient: the configured transport does not support derivation.');
    }

    return new WPNativeClient(this.transport.derive(baseUrl), this.config);
  }

  /**
   * Walk the Abilities API and populate the in-memory catalog.
   *
   * Call once at app startup, after auth is established. Subsequent
   * calls replace the catalog (cheap to re-run if the server registers
   * new abilities at runtime).
   *
   * Optionally filter to a subset by category — useful when bootstrapping
   * a minimal client that only needs auth abilities.
   */
  async discover(options: { category?: string } = {}): Promise<AbilityCatalog> {
    const filter: { category?: string } = {};
    if (options.category !== undefined) {
      filter.category = options.category;
    }
    const catalog = await discoverAbilities(this.transport, filter);
    this._catalog = catalog;
    for (const ability of catalog.all()) {
      this.descriptors.set(ability.name, ability);
    }
    return catalog;
  }

  /**
   * The current catalog. Throws if discover() has not been called.
   *
   * Use catalogOrNull() if you want to feature-detect without throwing.
   */
  get catalog(): AbilityCatalog {
    if (!this._catalog) {
      throw new Error(
        'WPNativeClient: catalog not loaded. Call discover() before accessing catalog.',
      );
    }
    return this._catalog;
  }

  /**
   * Non-throwing accessor for the catalog. Returns null if discover()
   * has not been called.
   */
  catalogOrNull(): AbilityCatalog | null {
    return this._catalog;
  }

  /**
   * Whether discover() has populated the catalog.
   */
  hasCatalog(): boolean {
    return this._catalog !== null;
  }

  /**
   * Execute an ability by name.
   *
   * The ability is looked up in the catalog (when validateAbilityNames is
   * enabled), then sent to /wp-abilities/v1/abilities/{name}/run using the
   * HTTP method required by the ability annotations.
   *
   * Throws:
   *   - Error    if the ability is not in the catalog (and validation enabled)
   *   - ApiError if the server returns a non-2xx response
   */
  async execute<TResult = unknown, TInput = unknown>(
    name: string,
    input?: TInput,
  ): Promise<TResult> {
    if (this.config.validateAbilityNames && this._catalog) {
      if (!this._catalog.has(name)) {
        throw new Error(
          `WPNativeClient: ability "${name}" is not registered on this site. ` +
            `Run discover() to refresh the catalog, or use executeUnchecked() ` +
            `to bypass validation.`,
        );
      }
    }

    let descriptor = this._catalog?.get(name) ?? this.descriptors.get(name);
    if (!descriptor) {
      descriptor = await fetchAbility(this.transport, name);
      this.descriptors.set(name, descriptor);
    }

    return this.executeDescriptor<TResult, TInput>(descriptor, input);
  }

  /**
   * Execute an ability without checking the catalog.
   *
   * Use this for the auth bootstrap path (login → discover) where the
   * client must call abilities before the catalog exists. For all other
   * call sites, prefer execute() so missing abilities fail loudly at
   * the call site.
   */
  async executeUnchecked<TResult = unknown, TInput = unknown>(
    name: string,
    input?: TInput,
  ): Promise<TResult> {
    const descriptor = await fetchAbility(this.transport, name);
    this.descriptors.set(name, descriptor);
    return this.executeDescriptor<TResult, TInput>(descriptor, input);
  }

  private async executeDescriptor<TResult, TInput>(
    descriptor: AbilityDescriptor,
    input?: TInput,
  ): Promise<TResult> {
    const annotations = descriptor.meta?.annotations;
    const annotationMap = annotations && typeof annotations === 'object' ? annotations : {};
    const method = annotationMap.readonly
      ? 'GET'
      : annotationMap.destructive && annotationMap.idempotent
        ? 'DELETE'
        : 'POST';
    let path = `${RUN_PATH_PREFIX}/${encodeAbilityName(descriptor.name)}/run`;
    // Omitted input for an object-typed schema is sent as an empty object.
    // Core only substitutes the schema `default` for null input, so abilities
    // whose object schema lacks a default would otherwise reject the call
    // with "input is not of type object". Explicit null is preserved.
    const sendEmptyObject = input === undefined && acceptsObjectInput(descriptor.input_schema);
    const body: { input: TInput | Record<string, never> | null } = {
      input: sendEmptyObject ? {} : input === undefined ? null : input,
    };

    if (method !== 'POST') {
      const query = new URLSearchParams();
      if (sendEmptyObject) {
        // `?input=` is the only query encoding core accepts as an empty
        // object: rest_is_object('') is true and it sanitizes to array().
        query.append('input', '');
      } else {
        appendQueryValue(query, 'input', body.input);
      }
      const queryString = query.toString();
      if (queryString) {
        path += `?${queryString}`;
      }
    }

    return this.transport.request<TResult>({
      path,
      method,
      ...(method === 'POST' ? { body: body as Record<string, unknown> } : {}),
    });
  }

  /**
   * Make an authenticated request to a plain REST route on this client's
   * REST root (including derive()d roots).
   *
   * Goes through the client's own transport, so auth headers, token refresh,
   * redirect protection and ApiError mapping apply exactly as they do for
   * ability execution. FormData bodies are passed through untouched so the
   * runtime sets the multipart boundary; any caller-supplied Content-Type is
   * dropped for FormData bodies.
   *
   * Throws:
   *   - Error    if `path` is absolute, protocol-relative, or traverses with `..`
   *   - ApiError if the server returns a non-2xx response
   */
  async request<T = unknown>(req: WPNativeRequest): Promise<T> {
    let path = normalizeRequestPath(req.path);

    if (req.query) {
      const query = new URLSearchParams();
      for (const [key, value] of Object.entries(req.query)) {
        appendQueryValue(query, key, value);
      }
      const queryString = query.toString();
      if (queryString) {
        path += `${path.includes('?') ? '&' : '?'}${queryString}`;
      }
    }

    const isFormData = typeof FormData !== 'undefined' && req.body instanceof FormData;
    let headers = req.headers;
    if (headers && isFormData) {
      headers = Object.fromEntries(
        Object.entries(headers).filter(([name]) => name.toLowerCase() !== 'content-type'),
      );
    }

    return this.transport.request<T>({
      path,
      method: req.method ?? 'GET',
      ...(req.body !== undefined ? { body: req.body } : {}),
      ...(headers ? { headers } : {}),
    });
  }

  /**
   * Get a single ability descriptor from the catalog.
   * Returns undefined if not registered or catalog not loaded.
   */
  describe(name: string): AbilityDescriptor | undefined {
    return this._catalog?.get(name);
  }
}

function isDerivableTransport(transport: Transport): transport is DerivableTransport {
  return 'derive' in transport && typeof transport.derive === 'function';
}

/**
 * Validate a caller-supplied REST path and return it relative to the root.
 *
 * A single leading slash is tolerated (`/wp/v2/posts`), but anything that
 * could resolve outside the REST root is rejected.
 */
function normalizeRequestPath(rawPath: string): string {
  if (typeof rawPath !== 'string' || rawPath.trim() === '') {
    throw new Error('WPNativeClient.request: path must be a non-empty string.');
  }
  const path = rawPath.trim();

  if (/^[a-z][a-z0-9+.-]*:/i.test(path)) {
    throw new Error(`WPNativeClient.request: absolute URLs are not allowed: ${path}`);
  }
  if (path.startsWith('//')) {
    throw new Error(`WPNativeClient.request: protocol-relative URLs are not allowed: ${path}`);
  }
  // Backslashes are normalized to slashes by URL parsers (`\\evil.test`).
  if (path.includes('\\') || path.includes('#') || /[\u0000-\u001f\u007f]/.test(path)) {
    throw new Error(`WPNativeClient.request: invalid path: ${path}`);
  }

  const relative = path.replace(/^\//, '');
  const pathname = relative.split('?')[0] ?? '';
  let decoded: string;
  try {
    decoded = decodeURIComponent(pathname);
  } catch {
    throw new Error(`WPNativeClient.request: invalid path encoding: ${path}`);
  }
  if (decoded.split('/').some((segment) => segment === '..' || segment === '.')) {
    throw new Error(`WPNativeClient.request: path traversal is not allowed: ${path}`);
  }
  if (relative === '' || pathname === '') {
    throw new Error('WPNativeClient.request: path must not be empty.');
  }

  return relative;
}

function acceptsObjectInput(schema: unknown): boolean {
  if (!schema || typeof schema !== 'object') {
    return false;
  }
  const type = (schema as { type?: unknown }).type;
  return type === 'object' || (Array.isArray(type) && type.includes('object'));
}

function appendQueryValue(
  query: URLSearchParams,
  key: string,
  value: unknown,
): void {
  if (value === null || value === undefined) {
    return;
  }

  if (Array.isArray(value)) {
    value.forEach((item, index) => appendQueryValue(query, `${key}[${index}]`, item));
    return;
  }

  if (typeof value === 'object') {
    Object.entries(value).forEach(([childKey, childValue]) => {
      appendQueryValue(query, `${key}[${childKey}]`, childValue);
    });
    return;
  }

  query.append(key, String(value));
}
