/**
 * Session primitives shared by AuthProvider's login actions.
 *
 * Kept free of React so they can be tested directly. Every path that ends
 * with wp-native-auth tokens (password login, continued login, register, and
 * logins obtained outside the shell such as social sign-in routes) installs
 * the session through installSession(), so tokens always land in the
 * transport the shell reads.
 */

import type { AuthStack } from './transport';
import type {
  AuthChallengeRequirement,
  AuthLoginResult,
  AuthLoginSuccess,
  AuthMeUser,
} from './types';

/** Parse an ISO-8601 access_expires_at string into a Unix timestamp (seconds). */
export function parseExpiresAt(expiresAt: string): number {
  return Math.floor(new Date(expiresAt).getTime() / 1000);
}

export function isChallengeRequirement(result: unknown): result is AuthChallengeRequirement {
  return Boolean(
    result
      && typeof result === 'object'
      && (result as { challenge_required?: unknown }).challenge_required === true,
  );
}

function isLoginSuccess(result: unknown): result is AuthLoginSuccess {
  if (!result || typeof result !== 'object') return false;
  const value = result as Partial<AuthLoginSuccess>;
  return typeof value.access_token === 'string'
    && value.access_token !== ''
    && typeof value.refresh_token === 'string'
    && value.refresh_token !== ''
    && typeof value.access_expires_at === 'string'
    && !Number.isNaN(parseExpiresAt(value.access_expires_at))
    && Boolean(value.user && typeof value.user === 'object');
}

/**
 * Persist a login success in the stack's transport and load the catalog.
 *
 * Resolves with the signed-in user. Throws (without touching stored tokens)
 * when given a challenge or a malformed result.
 */
export async function installSession(stack: AuthStack, result: AuthLoginSuccess): Promise<AuthMeUser> {
  if (isChallengeRequirement(result)) {
    throw new Error('wp-native-shell: a login challenge must be completed with continueLogin().');
  }
  if (!isLoginSuccess(result)) {
    throw new Error('wp-native-shell: login result is missing access_token, refresh_token, access_expires_at or user.');
  }

  await stack.transport.setTokens({
    accessToken: result.access_token,
    refreshToken: result.refresh_token,
    accessExpiresAt: parseExpiresAt(result.access_expires_at),
  });

  // Discover abilities now that we're authenticated.
  await stack.client.discover();

  return result.user;
}

/**
 * Call `wp-native/auth-continue-login` for a pending challenge.
 *
 * Errors are the client's ApiError, so WP_Error `code`, `status` and `data`
 * (e.g. `retry_after`, a rotated `continuation_token`) reach the caller.
 */
export async function requestContinueLogin(
  stack: AuthStack,
  continuationToken: string,
  challengeResponse: Record<string, unknown>,
): Promise<AuthLoginResult> {
  const deviceId = await stack.getDeviceId();
  return stack.preAuthClient.executeUnchecked<AuthLoginResult>(
    'wp-native/auth-continue-login',
    {
      continuation_token: continuationToken,
      device_id: deviceId,
      challenge_response: challengeResponse,
    },
  );
}
