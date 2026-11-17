/**
 * Auth types for wp-native-shell.
 *
 * Defines the public type surface consumed by AuthProvider and useAuth.
 * All shapes match the SHELL.md M5.1 contract.
 */

import type { WPNativeClient, WPNativeRequest } from 'wp-native-client';
import type { ReactNode } from 'react';

// ─── User ────────────────────────────────────────────────────────────────────

/**
 * The user payload returned by `wp-native/auth-me`.
 *
 * Matches the `User` object from wp-native-auth SCHEMAS.md.
 * No EC-specific fields — those are layered via the
 * `wp_native_auth_user_payload` filter on the server side.
 */
export interface AuthMeUser {
  id: number;
  username: string;
  display_name: string;
  email: string;
  avatar_url: string;
  roles: string[];
  registered_at: string;
}

/** Public descriptor and opaque bearer for a pending login challenge. */
export interface AuthChallengeRequirement {
  challenge_required: true;
  challenge_policy: string;
  challenge: { type: string; [key: string]: unknown };
  continuation_token: string;
  continuation_expires_at: string;
}

/** Successful password-only or continued login result. */
export interface AuthLoginSuccess {
  challenge_required?: never;
  access_token: string;
  access_expires_at: string;
  refresh_token: string;
  refresh_expires_at: string;
  user: AuthMeUser;
}

/** Authoritative discriminated result from `wp-native/auth-login`. */
export type AuthLoginResult = AuthLoginSuccess | AuthChallengeRequirement;

// ─── Storage ─────────────────────────────────────────────────────────────────

/**
 * Platform-agnostic token storage adapter.
 *
 * The shell never imports expo-secure-store or any concrete storage.
 * Consumers wire in their preferred implementation (expo-secure-store,
 * AsyncStorage, Keychain, etc.) by satisfying this interface.
 */
export interface TokenStorageAdapter {
  /** Read a value by key. Return null if not found. */
  getItem: (key: string) => Promise<string | null>;

  /** Write a value by key. */
  setItem: (key: string, value: string) => Promise<void>;

  /** Delete a value by key. */
  removeItem: (key: string) => Promise<void>;
}

// ─── Config ──────────────────────────────────────────────────────────────────

/**
 * API connection config passed to AuthProvider.
 *
 * Matches the `api` shape from the consumer config in ROADMAP.md.
 */
export interface WPNativeApiConfig {
  /** Base URL for the REST API, e.g. "https://example.com/wp-json" */
  baseUrl: string;

  /**
   * Exact alternate WordPress REST roots approved to receive this session's
   * bearer token. Consumers derive site-scoped clients with client.derive().
   */
  allowedBaseUrls?: readonly string[];

  /**
   * Client identifier sent as a default header.
   * Maps to e.g. "extrachill-app", "my-app", etc.
   */
  clientId: string;
}

// ─── State + Actions ─────────────────────────────────────────────────────────

/** Auth state exposed via useAuth(). */
export interface AuthState {
  /** The authenticated user, or null if not logged in. */
  user: AuthMeUser | null;

  /** True while the initial token load + /me check is in progress. */
  isLoading: boolean;

  /** True when a valid user session exists. */
  isAuthenticated: boolean;

  /**
   * True when a 401 could not be recovered by the transport's retry.
   * The consumer should show a "session expired" UI and prompt re-login.
   */
  sessionExpired: boolean;
}

/** Auth actions exposed via useAuth(). */
export interface AuthActions {
  /**
   * Log in with email/username and password.
   * Calls `wp-native/auth-login`, stores tokens, runs discover(), sets user.
   */
  login: (identifier: string, password: string) => Promise<AuthChallengeRequirement | void>;

  /**
   * Verify a pending challenge and complete its device-bound login.
   * Resolves with a fresh challenge when the policy re-challenges (for
   * example after re-sending a code); the continuation stays redeemable.
   */
  continueLogin: (
    continuationToken: string,
    challengeResponse: Record<string, unknown>,
  ) => Promise<AuthChallengeRequirement | void>;

  /**
   * Install a session from a login success obtained outside the shell, e.g.
   * a social sign-in REST route that returns wp-native-auth tokens. Stores the
   * tokens in the shell's transport, runs discover(), and sets the user —
   * exactly what login() and continueLogin() do on success. Rejects a
   * challenge (complete it with continueLogin()) or a malformed result.
   *
   * The tokens must be issued for getDeviceId() (refresh is device-bound).
   */
  completeLogin: (result: AuthLoginSuccess) => Promise<void>;

  /**
   * The persistent device id the shell sends with login, refresh and logout.
   * Send it to any external login route whose tokens go to completeLogin().
   */
  getDeviceId: () => Promise<string>;

  /**
   * Register a new user account with email and password.
   * Calls `wp-native/auth-register`, stores tokens, runs discover(), sets user.
   * Same return contract as login — Promise<void>, throws on failure.
   */
  register: (email: string, password: string, passwordConfirm: string) => Promise<void>;

  /**
   * Log out. Calls `wp-native/auth-logout`, then always clears local tokens
   * regardless of server response.
   */
  logout: () => Promise<void>;

  /**
   * Force a token refresh via the transport.
   * Useful when the app returns from background and wants a fresh session.
   */
  refreshSession: () => Promise<void>;

  /**
   * Clear the sessionExpired flag without re-authenticating.
   * Call this after showing a "session expired" dialog and navigating to login.
   */
  clearSessionExpired: () => void;

  /**
   * Authenticated request to a plain REST route on the API root (e.g. a
   * plugin namespace that is not an ability). Same bearer auth, proactive
   * refresh, refresh-and-retry on 401, and ApiError (`code`, `status`,
   * `data`) as ability calls. Shorthand for `client.request()`.
   */
  request: <T = unknown>(req: WPNativeRequest) => Promise<T>;

  /** The client for direct calls; use client.derive() for approved REST roots. */
  client: WPNativeClient;
}

// ─── Provider Props ──────────────────────────────────────────────────────────

/** Props for <AuthProvider>. */
export interface AuthProviderProps {
  /** API connection config. */
  api: WPNativeApiConfig;

  /** Platform-specific token storage. */
  storage: TokenStorageAdapter;

  /**
   * Called when authentication fails irrecoverably (refresh exhausted).
   * Optional — consumers can also react via the sessionExpired flag.
   */
  onAuthFailure?: () => void;

  children: ReactNode;
}
