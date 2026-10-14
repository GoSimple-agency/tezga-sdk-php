# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- CI on PHP 8.1 to 8.5; any notice or deprecation fails the build.
- Test of the real curl transport, without a stub.

## [0.1.0] - 2026-10-14

### Added
- Client for all 12 endpoints of the Tezga Fiscal API, written against the official
  [OpenAPI contract](https://tezgafiskal.com/api/openapi.json) (1.6.0).
- Webhook signature verification (`Webhooks::verifySignature`, `Webhooks::parse`).
- `TezgaException` with the API's `error` message, `getFields()` and `isRetryable()`.
- Request timeout, 30 seconds by default.

[Unreleased]: https://github.com/GoSimple-agency/tezga-sdk-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/GoSimple-agency/tezga-sdk-php/releases/tag/v0.1.0
