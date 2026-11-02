# Changelog

<!-- Managed by homeboy. Do not hand-edit. -->

## [0.2.2] - 2026-11-02

### Fixed
- declare public access and provenance in publishConfig

## [0.2.1] - 2026-11-02

### Fixed
- clean rebuild before publish so stale tsbuildinfo can't drop .d.ts files

## [0.2.0] - 2026-08-15

### Added
- derive clients from shared auth transport
- add register() to useAuth (Slice B)
- SectionScreen + drawer integration for M6 (M6.3)
- AbilityList screen + adapter (M6.1)
- AbilityDetail screen + adapter (M6.2)
- WPNativeApp composition + AuthGate + BrandProvider (M5.4)
- DrawerShell + BrowserHandoff (M5.3)
- AuthProvider + useAuth hook (M5.1)
- ThemeProvider + useTheme + default tokens (M5.2)

### Changed
- Refresh docs for current architecture and adoption
- remove WPNativeOnboardingConfig — platform-specific
- remove BrandProvider — pure consumer concern
- expo-router rebase — Slice D screens
- expo-router rebase — Slice C app
- expo-router rebase — Slice B navigation
- TypeScript baseline across all packages

### Fixed
- make package release tests self-contained
- harden authentication challenge continuations
- use kebab-case ability names + load all module files
