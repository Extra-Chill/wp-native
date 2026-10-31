/**
 * wp-native-client — universal WordPress client built on the Abilities API.
 *
 * One client. Discovery + execution. No per-site wrappers.
 */

// Client
export { WPNativeClient } from './client';
export type { WPNativeClientConfig, WPNativeRequest } from './client';

// Abilities subsystem
export { AbilityCatalog } from './abilities/catalog';
export {
  discoverAbilities,
  fetchAbilitiesPage,
  fetchAbility,
  fetchAbilityCategories,
} from './abilities/discovery';
export type {
  AbilityDescriptor,
  AbilityCategory,
  AbilityExecutionResponse,
  AbilityListPage,
  AbilityListParams,
} from './abilities/types';

// Transports
export type {
  DerivableTransport,
  Transport,
  TransportRequest,
  TransportResponse,
} from './transports/types';
export { FetchTransport, ApiError } from './transports/fetch';
export type { FetchTransportConfig } from './transports/fetch';
export { AuthFetchTransport } from './transports/auth-fetch';
export type { AuthFetchTransportConfig, StoredTokens } from './transports/auth-fetch';
