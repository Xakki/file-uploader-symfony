# Changelog

All notable changes to `xakki/file-uploader-symfony` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.0] - 2026-06-22

### Removed

- **Dropped Symfony 6.4 support.** The bundle now requires Symfony 7 or 8;
  every Symfony dependency constraint moved from `^6.4|^7.0` to `^7.0|^8.0`.

### Changed

- Widened the `phpunit/phpunit` constraint to include 12/13; dependencies updated
  to their latest stable versions.

### Fixed

- Test suite compatibility with Symfony 8.1 (`KernelTestCase::runCommand()` became
  static); the `CommandTest` private helper was renamed to `runUploaderCommand()`.

[0.4.0]: https://github.com/Xakki/file-uploader-symfony/releases/tag/v0.4.0

## [0.3.3] - 2026-06-15

### Changed

- Bump core dependency `xakki/file-uploader` to `^0.3.3` (Upload Protocol v1 i18n runtime).
- **Server-produced messages are now localized via the shared core catalog**
  (`protocol/i18n/<locale>.json`, 8 locales: `en ru es pt zh fr de sr`) using
  `Protocol\MessageCatalog::resolve()`. Stop hand-concatenating English
  (`'Attention: '.$e->getMessage()`, `sprintf('File "%s"...')`); the text now comes
  from the core catalog, identical across every binding and the JS client.
- The response envelope now carries the stable `code` (and `params`) that produced the
  message, on both the success and error branches.
- Upload/management messages emit `message.*` codes; `AttentionException` /
  `AuthorizationException` resolve through their `code()` / `params()`. The
  `ChunkValidator::validate()` call now receives the resolved `$locale` so per-field
  validation errors (including the manual `fileChunk` required error) are localized.
- Locale resolution follows Upload Protocol §5.1 (request `locale` field ∈ `locales`
  allow-list → config `locale` default → `en`), via a small `ResolvesLocale` trait.
  `FileController` now receives `request_stack` (added to its service args) so the
  management endpoints, which have no `Request` argument, can read the locale.

[0.3.3]: https://github.com/Xakki/file-uploader-symfony/releases/tag/v0.3.3

## [0.3.2] - 2026-06-14

### Added

- `max_files` config key (int, `0` = unlimited) — caps the number of active
  (non-deleted) files; enforced by the core `FileUploader::guardFileCount()`,
  which throws `Maximum number of files reached.` on a new upload's first chunk
  once the limit is hit. The value also flows to the JS widget config (`maxFiles`).

### Changed

- Bump core dependency `xakki/file-uploader` to `^0.3.2`.
- Refreshed the vendored UMD widget asset (`public/file-uploader.umd.js`) with the
  0.3.2 front-end (widget theming + i18n).

[0.3.2]: https://github.com/Xakki/file-uploader-symfony/releases/tag/v0.3.2
