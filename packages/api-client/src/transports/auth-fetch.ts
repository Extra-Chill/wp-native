/**
 * Authenticated fetch transport with token lifecycle.
 *
 * Wraps FetchTransport and adds:
 * - Bearer token injection via getAuthHeaders
 * - Proactive token refresh before expiry
 * - 401 retry with refresh lock (prevents thundering herd)
 * - Auth failure callback for logout/navigation
 *
 * Platform-specific storage is injected via callbacks:
 *   - loadTokens: read persisted tokens on init
 *   - saveTokens: persist after login/refresh
 *   - clearTokens: wipe on logout/auth failure
 *
 * Usage:
 *
 *   const transport = new AuthFetchTransport({
 *     baseUrl: 'https://wordpress.test/wp-json',
 *     refreshPath: 'wp-native/v1/auth/refresh',
 *     getDeviceId: () => getSecureItem('device_id'),
 *     loadTokens: () => getSecureItem('tokens').then(JSON.parse),
 *     saveTokens: (t) => setSecureItem('tokens', JSON.stringify(t)),
 *     clearTokens: () => deleteSecureItem('tokens'),
 *     onAuthFailure: () => router.replace('/login'),
 *   });
 *
 *   // Inject your platform's secure storage via the callbacks above.
 *   await transport.initialize(); // loads stored tokens
 */

import type { DerivableTransport, TransportRequest } from './types';
import { ApiError, readApiError } from './fetch';

/** Token data stored by consumers. */
export interface StoredTokens {
  accessToken: string;
  refreshToken: string;
  /** Unix timestamp (seconds) when the access token expires. */
  accessExpiresAt: number;
}

export interface AuthFetchTransportConfig {
  /** Base URL for the REST API, e.g. "https://example.com/wp-json" */
  baseUrl: string;

  /**
   * Alternate REST roots that may receive this transport's bearer token.
   * Values are matched exactly after URL normalization. The root baseUrl is
   * always approved; no alternate roots are approved by default.
   */
  allowedBaseUrls?: readonly string[];

  /**
   * REST path for the token refresh endpoint.
   * @default 'wp-native/v1/auth/refresh'
   */
  refreshPath?: string;

  /**
   * How many milliseconds before expiry to proactively refresh.
   * @default 60000 (1 minute)
   */
  refreshBufferMs?: number;

  /**
   * Return a device ID for refresh requests.
   * Mobile apps generate a UUID and persist it; Node scripts can return a fixed string.
   */
  getDeviceId: () => string | Promise<string>;

  /**
   * Load persisted tokens on initialize().
   * Return null if no tokens are stored.
   */
  loadTokens: () => StoredTokens | null | Promise<StoredTokens | null>;

  /**
   * Persist tokens after login, register, or refresh.
   */
  saveTokens: (tokens: StoredTokens) => void | Promise<void>;

  /**
   * Clear persisted tokens on logout or auth failure.
   */
  clearTokens: () => void | Promise<void>;

  /**
   * Called when authentication cannot be recovered (refresh failed, no tokens).
   * Use this to navigate to a login screen or reset app state.
   */
  onAuthFailure?: () => void;

  /**
   * Extra headers to include on every request.
   * Useful for custom client identification headers.
   */
  defaultHeaders?: Record<string, string>;
}

export class AuthFetchTransport implements DerivableTransport {
  private config: AuthFetchTransportConfig;
  private readonly baseUrl: string;
  private readonly allowedBaseUrls: ReadonlySet<string>;
  private accessToken: string | null = null;
  private refreshToken: string | null = null;
  private accessExpiresAt: number | null = null;
  private refreshPromise: Promise<boolean> | null = null;
  private initialized = false;
  private authFailed = false;

  constructor(config: AuthFetchTransportConfig) {
    this.config = config;
    this.baseUrl = normalizeBaseUrl(config.baseUrl);
    this.allowedBaseUrls = new Set([
      this.baseUrl,
      ...(config.allowedBaseUrls ?? []).map(normalizeBaseUrl),
    ]);
  }

  /**
   * Load stored tokens into memory. Call once at app startup.
   */
  async initialize(): Promise<void> {
    const tokens = await this.config.loadTokens();
    if (tokens) {
      this.accessToken = tokens.accessToken;
      this.refreshToken = tokens.refreshToken;
      this.accessExpiresAt = tokens.accessExpiresAt;
      this.authFailed = false;
    }
    this.initialized = true;
  }

  /**
   * Whether initialize() has been called.
   */
  isInitialized(): boolean {
    return this.initialized;
  }

  /**
   * Whether tokens are currently loaded in memory.
   */
  hasTokens(): boolean {
    return this.accessToken !== null && this.refreshToken !== null;
  }

  /**
   * Store new tokens in memory and persist via saveTokens callback.
   * Call after login, register, or any auth flow that returns tokens.
   */
  async setTokens(tokens: StoredTokens): Promise<void> {
    this.accessToken = tokens.accessToken;
    this.refreshToken = tokens.refreshToken;
    this.accessExpiresAt = tokens.accessExpiresAt;
    this.authFailed = false;
    await this.config.saveTokens(tokens);
  }

  /**
   * Set or replace the auth failure callback.
   * Useful when the callback depends on framework state or navigation
   * and must be wired after transport construction.
   */
  setOnAuthFailure(callback: () => void): void {
    this.config.onAuthFailure = callback;
  }

  /**
   * Clear tokens from memory and storage. Call on logout.
   */
  async clearAuth(): Promise<void> {
    this.accessToken = null;
    this.refreshToken = null;
    this.accessExpiresAt = null;
    await this.config.clearTokens();
  }

  /**
   * Scope requests to an approved REST root while sharing this transport's
   * in-memory tokens, refresh lock, retries, and auth-failure state.
   */
  derive(baseUrl: string): DerivableTransport {
    const normalizedBaseUrl = normalizeBaseUrl(baseUrl);
    if (!this.allowedBaseUrls.has(normalizedBaseUrl)) {
      throw new Error(`AuthFetchTransport: REST root is not approved: ${normalizedBaseUrl}`);
    }

    return {
      request: <T>(req: TransportRequest) => this.requestAt<T>(normalizedBaseUrl, req),
      derive: (nextBaseUrl: string) => this.derive(nextBaseUrl),
    };
  }

  /**
   * Execute an HTTP request with automatic auth handling.
   *
   * - Injects Bearer token if available
   * - Proactively refreshes before expiry
   * - Retries once on 401 after refreshing
   */
  async request<T>(req: TransportRequest): Promise<T> {
    return this.requestAt<T>(this.baseUrl, req);
  }

  private async requestAt<T>(baseUrl: string, req: TransportRequest): Promise<T> {
    // Proactive refresh before the request
    if (this.hasTokens() && this.isAccessExpiringSoon()) {
      const refreshed = await this.refreshAccessToken();
      if (!refreshed) {
        await this.handleAuthFailure();
        throw new ApiError('Session expired', 'session_expired', 401);
      }
    }

    const requestAccessToken = this.accessToken;

    try {
      return await this.executeRequest<T>(baseUrl, req, requestAccessToken);
    } catch (error) {
      // Retry once on 401 after refresh
      if (error instanceof ApiError && error.status === 401 && this.refreshToken) {
        if (requestAccessToken === this.accessToken) {
          const refreshed = await this.refreshAccessToken();
          if (!refreshed) {
            await this.handleAuthFailure();
            throw new ApiError('Session expired', 'session_expired', 401);
          }
        }
        return await this.executeRequest<T>(baseUrl, req, this.accessToken);
      }
      throw error;
    }
  }

  // ─── Private ───────────────────────────────────────────────────────────

  private async executeRequest<T>(
    baseUrl: string,
    req: TransportRequest,
    accessToken: string | null,
  ): Promise<T> {
    const url = `${baseUrl}/${req.path}`;

    const isFormData = typeof FormData !== 'undefined' && req.body instanceof FormData;

    const headers: Record<string, string> = {
      ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...(this.config.defaultHeaders ?? {}),
      ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
      ...req.headers,
    };

    const response = await fetch(url, {
      method: req.method,
      headers,
      redirect: 'error',
      body: req.body
        ? isFormData
          ? (req.body as BodyInit)
          : JSON.stringify(req.body)
        : null,
    });

    if (response.status === 401) {
      // Keep the WP_Error body (code, message, data) so callers can tell an
      // expired token from e.g. a rejected verification code.
      throw await readApiError(response, { message: 'Unauthorized', code: 'unauthorized' });
    }

    if (!response.ok) {
      throw await readApiError(response);
    }

    if (response.status === 204) {
      return undefined as T;
    }

    return (await response.json()) as T;
  }

  private isAccessExpiringSoon(): boolean {
    if (!this.accessExpiresAt) return true;
    const bufferMs = this.config.refreshBufferMs ?? 60_000;
    return Date.now() >= this.accessExpiresAt * 1000 - bufferMs;
  }

  /**
   * Refresh the access token. Uses a lock to deduplicate concurrent refreshes.
   */
  private async refreshAccessToken(): Promise<boolean> {
    if (this.refreshPromise) {
      return this.refreshPromise;
    }

    this.refreshPromise = this.executeRefresh();

    try {
      return await this.refreshPromise;
    } finally {
      this.refreshPromise = null;
    }
  }

  private async executeRefresh(): Promise<boolean> {
    if (!this.refreshToken) return false;

    try {
      const deviceId = await this.config.getDeviceId();
      const refreshPath = this.config.refreshPath ?? 'wp-native/v1/auth/refresh';
      const url = `${this.baseUrl}/${refreshPath}`;

      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        redirect: 'error',
        body: JSON.stringify({
          refresh_token: this.refreshToken,
          device_id: deviceId,
        }),
      });

      if (!response.ok) return false;

      const data = (await response.json()) as {
        access_token: string;
        access_expires_at: string;
        refresh_token: string;
      };

      const expiresAt = Math.floor(new Date(data.access_expires_at).getTime() / 1000);

      await this.setTokens({
        accessToken: data.access_token,
        refreshToken: data.refresh_token,
        accessExpiresAt: expiresAt,
      });

      return true;
    } catch {
      return false;
    }
  }

  private async handleAuthFailure(): Promise<void> {
    if (this.authFailed) return;
    this.authFailed = true;
    await this.clearAuth();
    this.config.onAuthFailure?.();
  }
}

function normalizeBaseUrl(baseUrl: string): string {
  let url: URL;
  try {
    url = new URL(baseUrl);
  } catch {
    throw new Error(`AuthFetchTransport: invalid REST root: ${baseUrl}`);
  }

  if (
    (url.protocol !== 'https:' && url.protocol !== 'http:') ||
    url.username !== '' ||
    url.password !== '' ||
    url.search !== '' ||
    url.hash !== ''
  ) {
    throw new Error(`AuthFetchTransport: invalid REST root: ${baseUrl}`);
  }

  url.pathname = url.pathname.replace(/\/+$/, '');
  return url.toString().replace(/\/$/, '');
}
