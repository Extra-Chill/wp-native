/**
 * AuthProvider and useAuth hook for wp-native-shell.
 *
 * Manages the full auth lifecycle:
 *   - On mount: load tokens from storage → discover abilities → fetch /me
 *   - login(identifier, password): email/password auth via wp-native/auth-login
 *   - register(email, password, passwordConfirm): create account via wp-native/auth-register
 *   - logout(): revoke server session + clear local tokens
 *   - refreshSession(): force a token refresh via the transport
 *   - sessionExpired flag surfaces irrecoverable 401s to the consumer
 *
 * Lineage: generalized from extrachill-app/src/auth/context.tsx.
 * EC-specific endpoints, Google OAuth, onboarding, and expo-secure-store
 * are stripped. Auth uses wp-native/auth.* abilities via wp-native-client,
 * and storage is abstracted behind TokenStorageAdapter.
 */

import { createContext, useContext, useEffect, useState, useCallback, useRef } from 'react';
import { ApiError } from 'wp-native-client';
import type { AuthFetchTransport } from 'wp-native-client';
import type {
  AuthState,
  AuthActions,
  AuthMeUser,
  AuthProviderProps,
  AuthChallengeRequirement,
  AuthLoginSuccess,
  AuthLoginResult,
} from './types';
import { buildAuthStack } from './transport';
import type { AuthStack } from './transport';

// ─── Login response shape (from wp-native/auth-login output schema) ──────────

// ─── Me response shape (from wp-native/auth-me output schema) ────────────────

interface MeResponse {
  user: AuthMeUser;
}

// ─── Context ─────────────────────────────────────────────────────────────────

type AuthContextValue = AuthState & AuthActions;

const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Parse an ISO-8601 access_expires_at string into a Unix timestamp (seconds).
 */
function parseExpiresAt(expiresAt: string): number {
  return Math.floor(new Date(expiresAt).getTime() / 1000);
}

function isChallengeRequirement(result: AuthLoginResult): result is AuthChallengeRequirement {
  return 'challenge_required' in result && result.challenge_required === true;
}

// ─── Provider ────────────────────────────────────────────────────────────────

export function AuthProvider({ api, storage, onAuthFailure, children }: AuthProviderProps) {
  const [state, setState] = useState<AuthState>({
    user: null,
    isLoading: true,
    isAuthenticated: false,
    sessionExpired: false,
  });

  // Build the auth stack once and hold a stable reference.
  // api/storage/onAuthFailure are treated as mount-time config.
  const stackRef = useRef<AuthStack | null>(null);

  if (!stackRef.current) {
    stackRef.current = buildAuthStack(api, storage);
  }

  const stack = stackRef.current;

  // Wire the auth failure callback — needs access to setState, so it's
  // set here rather than in buildAuthStack.
  const handleAuthFailure = useCallback(() => {
    setState((prev) => ({
      ...prev,
      user: null,
      isLoading: false,
      isAuthenticated: false,
      sessionExpired: true,
    }));
    onAuthFailure?.();
  }, [onAuthFailure]);

  useEffect(() => {
    (stack.transport as AuthFetchTransport).setOnAuthFailure(handleAuthFailure);
  }, [stack.transport, handleAuthFailure]);

  // ── Init: load tokens → discover → /me ──────────────────────────────────

  const checkAuth = useCallback(async () => {
    const { transport, client } = stack;

    await transport.initialize();

    if (!transport.hasTokens()) {
      setState({
        user: null,
        isLoading: false,
        isAuthenticated: false,
        sessionExpired: false,
      });
      return;
    }

    try {
      // Discover abilities first so subsequent execute() calls validate.
      await client.discover();

      const meResponse = await client.execute<MeResponse>('wp-native/auth-me');
      setState({
        user: meResponse.user,
        isLoading: false,
        isAuthenticated: true,
        sessionExpired: false,
      });
    } catch (error: unknown) {
      // If the 401 retry in the transport already fired onAuthFailure,
      // sessionExpired is already true. Otherwise surface a clean logout.
      if (error instanceof ApiError && error.status === 401) {
        setState((prev) => ({
          ...prev,
          user: null,
          isLoading: false,
          isAuthenticated: false,
          sessionExpired: true,
        }));
      } else {
        setState({
          user: null,
          isLoading: false,
          isAuthenticated: false,
          sessionExpired: false,
        });
      }
    }
  }, [stack]);

  useEffect(() => {
    void checkAuth();
  }, [checkAuth]);

  // ── Actions ─────────────────────────────────────────────────────────────

  const login = useCallback(async (identifier: string, password: string) => {
	const { client, preAuthClient, getDeviceId } = stack;
    const deviceId = await getDeviceId();

    // Login is pre-discovery — use executeUnchecked.
	const response = await preAuthClient.executeUnchecked<AuthLoginResult>(
      'wp-native/auth-login',
      { identifier, password, device_id: deviceId },
    );

    if (isChallengeRequirement(response)) {
      return response;
    }

    // Persist tokens via the transport.
    await (stack.transport as AuthFetchTransport).setTokens({
      accessToken: response.access_token,
      refreshToken: response.refresh_token,
      accessExpiresAt: parseExpiresAt(response.access_expires_at),
    });

    // Discover abilities now that we're authenticated.
    await client.discover();

    setState({
      user: response.user,
      isLoading: false,
      isAuthenticated: true,
      sessionExpired: false,
    });

    return undefined;
  }, [stack]);

  const continueLogin = useCallback(async (
    continuationToken: string,
    challengeResponse: Record<string, unknown>,
  ) => {
	const { client, preAuthClient, getDeviceId } = stack;
    const deviceId = await getDeviceId();
	const response = await preAuthClient.executeUnchecked<AuthLoginResult>(
      'wp-native/auth-continue-login',
      {
        continuation_token: continuationToken,
        device_id: deviceId,
        challenge_response: challengeResponse,
      },
    );

    if (isChallengeRequirement(response)) {
      return response;
    }

    await (stack.transport as AuthFetchTransport).setTokens({
      accessToken: response.access_token,
      refreshToken: response.refresh_token,
      accessExpiresAt: parseExpiresAt(response.access_expires_at),
    });
    await client.discover();
    setState({
      user: response.user,
      isLoading: false,
      isAuthenticated: true,
      sessionExpired: false,
    });

    return undefined;
  }, [stack]);

  const register = useCallback(async (email: string, password: string, passwordConfirm: string) => {
	const { client, preAuthClient, getDeviceId } = stack;
    const deviceId = await getDeviceId();

    // Register is pre-discovery — use executeUnchecked (same as login).
	const response = await preAuthClient.executeUnchecked<AuthLoginSuccess>(
      'wp-native/auth-register',
      { email, password, password_confirm: passwordConfirm, device_id: deviceId },
    );

    // Persist tokens via the transport.
    await (stack.transport as AuthFetchTransport).setTokens({
      accessToken: response.access_token,
      refreshToken: response.refresh_token,
      accessExpiresAt: parseExpiresAt(response.access_expires_at),
    });

    // Discover abilities now that we're authenticated.
    await client.discover();

    setState({
      user: response.user,
      isLoading: false,
      isAuthenticated: true,
      sessionExpired: false,
    });
  }, [stack]);

  const logout = useCallback(async () => {
    const { client, transport, getDeviceId } = stack;

    try {
      if (transport.hasTokens()) {
        const deviceId = await getDeviceId();
        await client.execute<{ revoked: boolean }>(
          'wp-native/auth-logout',
          { device_id: deviceId },
        );
      }
    } catch {
      // Server-side logout failure is non-fatal — clear local tokens anyway.
    }

    await transport.clearAuth();

    setState({
      user: null,
      isLoading: false,
      isAuthenticated: false,
      sessionExpired: false,
    });
  }, [stack]);

  const refreshSession = useCallback(async () => {
    const { transport, client } = stack;

    if (!transport.hasTokens()) return;

    // Force a refresh by making a request — the transport's proactive
    // refresh logic handles the actual token rotation.
    try {
      const meResponse = await client.execute<MeResponse>('wp-native/auth-me');
      setState((prev) => ({
        ...prev,
        user: meResponse.user,
        isAuthenticated: true,
        sessionExpired: false,
      }));
    } catch (error: unknown) {
      if (error instanceof ApiError && error.status === 401) {
        setState((prev) => ({
          ...prev,
          user: null,
          isAuthenticated: false,
          sessionExpired: true,
        }));
      }
      throw error;
    }
  }, [stack]);

  const clearSessionExpired = useCallback(() => {
    setState((prev) => ({ ...prev, sessionExpired: false }));
  }, []);

  // ── Render ──────────────────────────────────────────────────────────────

  const value: AuthContextValue = {
    ...state,
    login,
    continueLogin,
    register,
    logout,
    refreshSession,
    clearSessionExpired,
    client: stack.client,
  };

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
}

// ─── Hook ────────────────────────────────────────────────────────────────────

/**
 * Access auth state and actions from within an AuthProvider.
 *
 * Returns `AuthState & AuthActions` — user, isLoading, isAuthenticated,
 * sessionExpired, login, continueLogin, register, logout, refreshSession,
 * clearSessionExpired, and the underlying WPNativeClient via `client`.
 */
export function useAuth(): AuthState & AuthActions {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used within an <AuthProvider>.');
  }

  return context;
}
