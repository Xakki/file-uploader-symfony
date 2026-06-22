# xakki/file-uploader-symfony

Chunked file uploader **bundle for Symfony 7 / 8**, speaking
**[Upload Protocol v1](https://github.com/Xakki/file-uploader/blob/main/protocol/SPEC.md)**. A thin binding over the
framework-agnostic [`xakki/file-uploader`](https://github.com/Xakki/file-uploader)
([Packagist](https://packagist.org/packages/xakki/file-uploader)) core — all upload logic
(chunk assembly, hashing, metadata, trash) lives in the core; this package only
wires Symfony's storage, security, routing and console to it.

A sibling [Laravel binding](https://github.com/Xakki/file-uploader-laravel)
([`xakki/file-uploader-laravel`](https://packagist.org/packages/xakki/file-uploader-laravel))
speaks the same [Upload Protocol v1](https://github.com/Xakki/file-uploader/blob/main/protocol/SPEC.md).

## Install

```bash
composer require xakki/file-uploader-symfony
```

Register the bundle (Symfony Flex does this automatically; otherwise add to
`config/bundles.php`):

```php
return [
    // ...
    Xakki\SymfonyFileUploader\FileUploaderBundle::class => ['all' => true],
];
```

Mount the routes, choosing a URL prefix:

```yaml
# config/routes/file_uploader.yaml
file_uploader:
    resource: '@FileUploaderBundle/config/routes.php'
    prefix: /file-upload
```

Publish the widget asset:

```bash
php bin/console assets:install public
```

## Configure

All keys are optional; defaults shown.

```yaml
# config/packages/xakki_file_uploader.yaml
xakki_file_uploader:
    storage:
        operator: ~              # service id of a League\Flysystem\FilesystemOperator
                                 # (e.g. a flysystem-bundle storage). When null, a local
                                 # adapter rooted at local_root is used.
        local_root: '%kernel.project_dir%/var/uploads'
        public_url_base: ~       # base URL for stored files; url() = base + '/' + path
    directory: '/'
    chunk_size: 1048576          # 1 MiB
    max_size: 52428800           # 50 MiB
    max_files: 0                 # max active (non-deleted) files; 0 = unlimited
    allowed_extensions: { ... }  # MIME=>ext map / ext list; empty = allow all
    soft_delete: true
    trash_ttl_days: 30
    route_prefix: file-upload    # must match the routes prefix above
    locales: ['en', 'ru', 'es', 'pt', 'zh', 'fr', 'de', 'sr']   # permitted request locales
    locale: en
    allow_list: true
    allow_delete: true
    allow_delete_all_files: false
    allow_cleanup: true
    csrf: false                  # emit a CSRF token in the widget (needs symfony/security-csrf)
    full_access:
        users: []                # user identifiers with full management access
        roles: []                # roles with full management access
    clock_service: ~             # Psr\Clock\ClockInterface service id; defaults to
                                 # symfony/clock when installed, else the core system clock
    logger_service: ~            # Psr\Log\LoggerInterface service id, e.g. 'logger'
```

### Storage

Out of the box, files go to a **local** directory (`storage.local_root`). To use
S3, GCS, etc., point `storage.operator` at any
[`league/flysystem-bundle`](https://github.com/thephpleague/flysystem-bundle)
storage service and set `storage.public_url_base` if it serves public URLs.

### Security

Set `full_access.users` / `full_access.roles` to grant management rights. Roles
are checked through Symfony Security (`isGranted`). With no SecurityBundle every
request is an anonymous guest; set `allow_delete_all_files: true` to let guests
manage files.

## Widget

Render the upload widget in any Twig template:

```twig
{# inject Xakki\SymfonyFileUploader\Service\FileWidgetRenderer $widget #}
{{ widget.render()|raw }}
```

It emits the mount point, the JS config (route URLs + flags) and the vendored
UMD bundle from `/bundles/fileuploader/file-uploader.umd.js`. The same front-end
ships with every binding (built from [`@xakki/file-uploader`](https://github.com/Xakki/file-uploader/tree/main/js)).

The bundled widget supports **theming** and **i18n** out of the box: the `locale`
config key flows through to the front-end and the JS widget exposes theme/string
overrides. (`max_files` is enforced server-side by the core — new uploads beyond
the cap are rejected.)

**Server-produced messages** are also localized: upload/chunk/trash/cleanup results
and the `error.*` / `validation.*` codes are rendered from the **shared core catalog**
(`xakki/file-uploader` `protocol/i18n/<locale>.json`, 8 locales: `en ru es pt zh fr de sr`)
— identical text across every binding and the JS client. The response envelope now
carries the stable `code` (and `params`) alongside the human `message`. The locale is
resolved per Upload Protocol §5.1: the request `locale` field (only when in the `locales`
allow-list) → the `locale` config default → `en`.

See the
[`@xakki/file-uploader` JS docs](https://github.com/Xakki/file-uploader/tree/main/js)
for the full widget config (themes, custom strings, templates).

## Console

```bash
php bin/console file-uploader:cleanup          # purge expired trash
php bin/console file-uploader:sync-metadata    # rebuild metadata from stored files
```

## HTTP API

| Method & path | Action |
|---|---|
| `POST {prefix}/chunks` | upload a chunk |
| `GET {prefix}/files` | list files |
| `DELETE {prefix}/files/{id}` | delete (soft by default) |
| `POST {prefix}/files/{id}/restore` | restore from trash |
| `DELETE {prefix}/trash/cleanup` | purge expired trash |

The wire shape is the shared [Upload Protocol v1](https://github.com/Xakki/file-uploader/blob/main/protocol/SPEC.md)
envelope, identical to the [Laravel binding](https://github.com/Xakki/file-uploader-laravel) and the standalone demo.

## Test

```bash
composer install
composer phpunit
```
