<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Xakki\SymfonyFileUploader\Http\FileController;
use Xakki\SymfonyFileUploader\Http\UploadController;

/*
 * Import these from your app, choosing the URL prefix:
 *
 *   # config/routes/file_uploader.yaml
 *   file_uploader:
 *       resource: '@FileUploaderBundle/config/routes.php'
 *       prefix: /file-upload
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('xakki_file_uploader_chunks_store', '/chunks')
        ->controller([UploadController::class, 'store'])
        ->methods(['POST']);

    $routes->add('xakki_file_uploader_files_index', '/files')
        ->controller([FileController::class, 'index'])
        ->methods(['GET']);

    $routes->add('xakki_file_uploader_files_destroy', '/files/{id}')
        ->controller([FileController::class, 'destroy'])
        ->methods(['DELETE']);

    $routes->add('xakki_file_uploader_files_restore', '/files/{id}/restore')
        ->controller([FileController::class, 'restore'])
        ->methods(['POST']);

    $routes->add('xakki_file_uploader_trash_cleanup', '/trash/cleanup')
        ->controller([FileController::class, 'cleanup'])
        ->methods(['DELETE']);
};
