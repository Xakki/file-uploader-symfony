<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Xakki\FileUploader\FileUploader;

/**
 * Server-rendered widget bootstrap: emits the mount point, the JS config (route
 * URLs + flags), and the vendored UMD bundle. Mirrors the Laravel widget so the
 * same front-end runs unchanged. Run `bin/console assets:install` to publish the
 * bundle's public/ assets.
 */
final class FileWidgetRenderer
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly UrlGeneratorInterface $router,
        private readonly ?CsrfTokenManagerInterface $csrf = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config  Extra config merged into window.FileUploadConfig.
     */
    public function render(array $config = []): string
    {
        $placeholder = FileUploader::ROUTE_PARAM_PLACEHOLDER;

        // Respect the app's base path (sub-dir installs) — the same prefix the router
        // bakes into the generated route URLs below.
        $base = rtrim($this->router->getContext()->getBaseUrl(), '/');

        $config['endpointBase'] = $base.'/'.trim((string) $this->config['route_prefix'], '/');
        $config['chunkSize'] = $this->config['chunk_size'];
        $config['allowList'] = (bool) $this->config['allow_list'];
        $config['allowDelete'] = (bool) $this->config['allow_delete'];
        $config['allowDeleteAllFiles'] = (bool) $this->config['allow_delete_all_files'];
        $config['allowCleanup'] = (bool) $this->config['allow_cleanup'];
        $config['locale'] = $this->config['locale'];
        $config['maxFiles'] = (int) $this->config['max_files'];
        $config['token'] = ($this->config['csrf'] && $this->csrf)
            ? $this->csrf->getToken('file-uploader')->getValue()
            : null;
        $config['routePlaceholder'] = $placeholder;
        $config['routes'] = [
            'upload' => $this->router->generate('xakki_file_uploader_chunks_store'),
            'list' => $this->router->generate('xakki_file_uploader_files_index'),
            'delete' => $this->router->generate('xakki_file_uploader_files_destroy', ['id' => $placeholder]),
            'restore' => $this->router->generate('xakki_file_uploader_files_restore', ['id' => $placeholder]),
            'cleanup' => $this->router->generate('xakki_file_uploader_trash_cleanup'),
        ];

        return '
            <div id="file-upload-widget"></div>
            <script>
              window.FileUploadConfig = '.json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).';
            </script>
            <script src="'.$base.'/bundles/fileuploader/file-uploader.umd.js" defer></script>
            ';
    }
}
