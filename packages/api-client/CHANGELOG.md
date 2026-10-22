# Changelog

<!-- Managed by homeboy. Do not hand-edit. -->

## [0.1.1] - 2026-10-22

### Fixed
- send empty object input when an object-schema ability is executed without input

## [0.1.0] - 2026-08-15

### Added
- derive clients from shared auth transport
- WPNativeClient with abilities discovery and execute
- port WpApiFetchTransport for Gutenberg block contexts
- port AuthFetchTransport with token refresh lifecycle
- port FetchTransport and Transport types from extrachill-api-client

### Changed
- wp-native-client v0.0.2
- TypeScript baseline across all packages

### Fixed
- make package release tests self-contained
- harden authentication challenge continuations
- honor ability execution contracts
- use kebab-case ability names + load all module files
