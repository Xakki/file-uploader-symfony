<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Xakki\FileUploader\Clock\SystemClock;
use Xakki\FileUploader\FileManager;
use Xakki\FileUploader\FileUploader;
use Xakki\FileUploader\Storage\FlysystemStorage;
use Xakki\SymfonyFileUploader\Command\CleanupTrashCommand;
use Xakki\SymfonyFileUploader\Command\SyncMetadataCommand;
use Xakki\SymfonyFileUploader\Http\FileController;
use Xakki\SymfonyFileUploader\Http\UploadController;
use Xakki\SymfonyFileUploader\Security\SymfonyUserResolver;
use Xakki\SymfonyFileUploader\Service\FileWidgetRenderer;
use Xakki\SymfonyFileUploader\Storage\StorageFactory;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Thin Symfony binding over the framework-agnostic core. Wires the core
 * Storage/UserResolver/Logger/Clock seams to Symfony services; all upload logic
 * lives in xakki/file-uploader.
 */
final class FileUploaderBundle extends AbstractBundle
{
    protected string $extensionAlias = 'xakki_file_uploader';

    /**
     * @var array<int|string, string>
     */
    private const DEFAULT_EXTENSIONS = [
        'docx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'image/jpeg' => '*',
        'image/pjpeg' => '*',
        'image/gif' => 'gif',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/octet-stream' => '*',
        'application/x-rar-compressed' => 'rar',
        'application/vnd.rar' => 'rar',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
        'application/gzip' => 'gz',
        'application/x-gzip' => 'gz',
        'application/json' => 'json',
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->arrayNode('storage')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('operator')
            ->defaultNull()
            ->info('Service id of a League\Flysystem\FilesystemOperator (e.g. a flysystem-bundle storage). When null, a local adapter rooted at local_root is used.')
            ->end()
            ->scalarNode('local_root')->defaultValue('%kernel.project_dir%/var/uploads')->end()
            ->scalarNode('public_url_base')
            ->defaultNull()
            ->info('Base URL for stored files; url() returns base + "/" + path. Null means the storage has no public URL.')
            ->end()
            ->end()
            ->end()
            ->scalarNode('disk')->defaultValue('default')->end()
            ->scalarNode('directory')->defaultValue('/')->end()
            ->scalarNode('temporary_directory')->defaultValue('.chunks')->end()
            ->scalarNode('metadata_directory')->defaultValue('.meta')->end()
            ->scalarNode('trash_directory')->defaultValue('.trash')->end()
            ->integerNode('chunk_size')->defaultValue(1024 * 1024)->end()
            ->integerNode('max_size')->defaultValue(1024 * 1024 * 50)->end()
            ->integerNode('max_files')->defaultValue(0)->info('Max number of active (non-deleted) files; 0 = unlimited.')->end()
            ->variableNode('allowed_extensions')->defaultValue(self::DEFAULT_EXTENSIONS)->end()
            ->booleanNode('soft_delete')->defaultTrue()->end()
            ->integerNode('trash_ttl_days')->defaultValue(30)->end()
            ->scalarNode('route_prefix')->defaultValue('file-upload')->end()
            ->arrayNode('locales')->scalarPrototype()->end()->defaultValue(['en', 'ru', 'es', 'pt', 'zh', 'fr', 'de', 'sr'])->end()
            ->scalarNode('locale')->defaultValue('en')->end()
            ->booleanNode('allow_list')->defaultTrue()->end()
            ->booleanNode('allow_delete')->defaultTrue()->end()
            ->booleanNode('allow_delete_all_files')->defaultFalse()->end()
            ->booleanNode('allow_cleanup')->defaultTrue()->end()
            ->booleanNode('csrf')->defaultFalse()->info('Emit a CSRF token in the widget config (requires symfony/security-csrf).')->end()
            ->arrayNode('full_access')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('users')->scalarPrototype()->end()->defaultValue([])->end()
            ->arrayNode('roles')->scalarPrototype()->end()->defaultValue([])->end()
            ->end()
            ->end()
            ->scalarNode('clock_service')->defaultNull()->info('Service id of a Psr\Clock\ClockInterface. Defaults to symfony/clock when installed, else the core system clock.')->end()
            ->scalarNode('logger_service')->defaultNull()->info("Service id of a Psr\Log\LoggerInterface, e.g. 'logger'. Defaults to a null logger.")->end()
            ->end();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $coreConfig = [
            'disk' => $config['disk'],
            'directory' => $config['directory'],
            'temporary_directory' => $config['temporary_directory'],
            'metadata_directory' => $config['metadata_directory'],
            'trash_directory' => $config['trash_directory'],
            'chunk_size' => $config['chunk_size'],
            'max_size' => $config['max_size'],
            'max_files' => $config['max_files'],
            'allowed_extensions' => $config['allowed_extensions'],
            'soft_delete' => $config['soft_delete'],
            'trash_ttl_days' => $config['trash_ttl_days'],
            'route_prefix' => $config['route_prefix'],
            'locale' => $config['locale'],
            'locales' => $config['locales'],
            'allow_list' => $config['allow_list'],
            'allow_delete' => $config['allow_delete'],
            'allow_delete_all_files' => $config['allow_delete_all_files'],
            'allow_cleanup' => $config['allow_cleanup'],
            'csrf' => $config['csrf'],
            'full_access' => $config['full_access'],
        ];
        $builder->setParameter('xakki_file_uploader.config', $coreConfig);

        $services = $container->services();
        $services->defaults()->autowire(false)->autoconfigure(false);

        // Storage seam: a configured Flysystem operator service, or a local adapter.
        if ($config['storage']['operator'] !== null) {
            $operator = service($config['storage']['operator']);
        } else {
            $services->set('xakki_file_uploader.local_adapter', LocalFilesystemAdapter::class)
                ->args([$config['storage']['local_root']]);
            $services->set('xakki_file_uploader.local_filesystem', Filesystem::class)
                ->args([service('xakki_file_uploader.local_adapter')]);
            $operator = service('xakki_file_uploader.local_filesystem');
        }
        $services->set('xakki_file_uploader.storage', FlysystemStorage::class)
            ->factory([StorageFactory::class, 'create'])
            ->args([$operator, $config['storage']['public_url_base']]);

        // Identity seam: optional Symfony Security (null when SecurityBundle is absent).
        $services->set('xakki_file_uploader.user_resolver', SymfonyUserResolver::class)
            ->args([
                service('security.token_storage')->nullOnInvalid(),
                service('security.authorization_checker')->nullOnInvalid(),
            ]);

        // Clock seam.
        if ($config['clock_service'] !== null) {
            $services->alias('xakki_file_uploader.clock', $config['clock_service']);
        } elseif (class_exists(Clock::class)) {
            $services->alias('xakki_file_uploader.clock', 'Psr\Clock\ClockInterface');
        } else {
            $services->set('xakki_file_uploader.clock', SystemClock::class);
        }

        // Logger seam.
        if ($config['logger_service'] !== null) {
            $services->alias('xakki_file_uploader.logger', $config['logger_service']);
        } else {
            $services->set('xakki_file_uploader.logger', NullLogger::class);
        }

        // Core manager (handles upload + list/delete/restore/cleanup).
        $services->set('xakki_file_uploader.manager', FileManager::class)
            ->args([
                '%xakki_file_uploader.config%',
                service('xakki_file_uploader.storage'),
                service('xakki_file_uploader.user_resolver'),
                service('xakki_file_uploader.logger'),
                service('xakki_file_uploader.clock'),
            ])
            ->public();
        $services->alias(FileManager::class, 'xakki_file_uploader.manager')->public();
        $services->alias(FileUploader::class, 'xakki_file_uploader.manager');

        // Server-rendered widget bootstrap.
        $services->set('xakki_file_uploader.widget', FileWidgetRenderer::class)
            ->args([
                '%xakki_file_uploader.config%',
                service('router'),
                service('security.csrf.token_manager')->nullOnInvalid(),
            ])
            ->public();
        $services->alias(FileWidgetRenderer::class, 'xakki_file_uploader.widget')->public();

        // Controllers.
        $services->set(UploadController::class)
            ->args([
                '%xakki_file_uploader.config%',
                service('xakki_file_uploader.manager'),
                service('xakki_file_uploader.logger'),
            ])
            ->tag('controller.service_arguments')
            ->public();
        $services->set(FileController::class)
            ->args(['%xakki_file_uploader.config%', service('xakki_file_uploader.manager'), service('request_stack')])
            ->tag('controller.service_arguments')
            ->public();

        // Console commands.
        $services->set(CleanupTrashCommand::class)
            ->args([service('xakki_file_uploader.manager')])
            ->tag('console.command');
        $services->set(SyncMetadataCommand::class)
            ->args([service('xakki_file_uploader.manager')])
            ->tag('console.command');
    }
}
