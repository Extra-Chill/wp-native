/**
 * Native fetch transport.
 *
 * Works in:
 * - React Native (built-in fetch)
 * - Modern browsers (without @wordpress/api-fetch)
 * - Node 18+ (built-in fetch)
 *
 * Auth is handled via a configurable header function.
 * Mobile apps pass Bearer tokens, Node scripts pass Basic auth, etc.
 */

import type { Transport, TransportRequest } from './types';

export interface FetchTransportConfig {
  /** Base URL for the REST API, e.g. "https://example.com/wp-json" */
  baseUrl: string;

  /**
   * Return auth headers for each request.
   * Return empty object for public endpoints.
   *
   * Examples:
   *   Bearer:  () => ({ Authorization: `Bearer ${token}` })
   *   Basic:   () => ({ Authorization: `Basic ${btoa('user:pass')}` })
   *   Nonce:   () => ({ 'X-WP-Nonce': nonce })
   */
  getAuthHeaders?: () => Record<string, string> | Promise<Record<string, string>>;

  /**
   * Called when a request returns 401.
   * Use this to trigger token refresh or logout.
   */
  onUnauthorized?: () => void | Promise<void>;

  /** Extra headers included on every request. */
  defaultHeaders?: Record<string, string>;
}

export class FetchTransport implements Transport {
  private config: FetchTransportConfig;

  constructor(config: FetchTransportConfig) {
    this.config = config;
  }

  async request<T>(req: TransportRequest): Promise<T> {
    const url = `${this.config.baseUrl}/${req.path}`;

    const authHeaders = this.config.getAuthHeaders
      ? await this.config.getAuthHeaders()
      : {};

    const isFormData = typeof FormData !== 'undefined' && req.body instanceof FormData;

    const headers: Record<string, string> = {
      ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...(this.config.defaultHeaders ?? {}),
      ...authHeaders,
      ...req.headers,
    };

    const response = await fetch(url, {
      method: req.method,
      headers,
      body: req.body
        ? isFormData
          ? (req.body as BodyInit)
          : JSON.stringify(req.body)
        : null,
    });

    if (!response.ok) {
      const error = await readApiError(response);
      if (response.status === 401) {
        await this.config.onUnauthorized?.();
      }
      throw error;
    }

    // Handle 204 No Content
    if (response.status === 204) {
      return undefined as T;
    }

    return (await response.json()) as T;
  }
}

/**
 * WordPress error details carried by an ApiError.
 *
 * This is the `data` member of a WP_Error REST response, untouched: e.g.
 * `{ status: 429, retry_after: 30 }`, a rotated `continuation_token`, or a
 * `revalidation` descriptor. Empty when the server sent none.
 */
export type ApiErrorData = Record<string, unknown>;

/**
 * Error thrown for a non-2xx REST response.
 *
 * `message` and `code` are WordPress's error message and code (with generic
 * fallbacks when the body is not a WP_Error), `status` is the HTTP status,
 * and `data` is the WP_Error `data` object.
 */
export class ApiError extends Error {
  code: string;
  status: number;
  data: ApiErrorData;

  constructor(message: string, code: string, status: number, data: ApiErrorData = {}) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.data = data;
  }
}

/**
 * Whether a value is an ApiError.
 *
 * Also matches errors created by another copy of this package (duplicate
 * installs in an app bundle), where `instanceof` would fail.
 */
export function isApiError(value: unknown): value is ApiError {
  if (value instanceof ApiError) return true;
  if (!(value instanceof Error) || value.name !== 'ApiError') return false;
  const candidate = value as Partial<ApiError>;
  return typeof candidate.code === 'string' && typeof candidate.status === 'number';
}

/**
 * Build an ApiError from a failed response, keeping the WP_Error body.
 *
 * @internal Shared by the fetch-based transports.
 */
export async function readApiError(
  response: Response,
  fallback: { message?: string; code?: string } = {},
): Promise<ApiError> {
  let body: unknown = null;
  try {
    body = await response.json();
  } catch {
    // Response wasn't JSON
  }
  const errorBody = isPlainObject(body) ? body : {};
  const message = typeof errorBody.message === 'string' && errorBody.message !== ''
    ? errorBody.message
    : fallback.message ?? `Request failed with status ${response.status}`;
  const code = typeof errorBody.code === 'string' && errorBody.code !== ''
    ? errorBody.code
    : fallback.code ?? 'request_failed';
  const data = isPlainObject(errorBody.data) ? errorBody.data : {};
  return new ApiError(message, code, response.status, data);
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
