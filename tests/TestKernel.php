<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Tests;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Xakki\SymfonyFileUploader\FileUploaderBundle;

/**
 * Minimal app kernel: FrameworkBundle + the bundle under test, storage on a local
 * temp dir. Just enough to prove the seams are wired end to end.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    private string $varDir;

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);
        $this->varDir = sys_get_temp_dir().'/xakki_fu_symfony_test';
    }

    public function uploadRoot(): string
    {
        return $this->varDir.'/uploads';
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle, new FileUploaderBundle];
    }

    public function getCacheDir(): string
    {
        return $this->varDir.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDir.'/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $container->extension('xakki_file_uploader', [
            'storage' => ['local_root' => $this->uploadRoot()],
            'allowed_extensions' => [],          // allow any extension in tests
            'allow_delete_all_files' => true,    // delete as guest (no SecurityBundle here)
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/../config/routes.php')->prefix('/file-upload');
    }
}
