<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Cegah RefreshDatabase menyentuh database operasional, termasuk ketika konfigurasi masih tercache. */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();
        if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'banksampah_testing') {
            throw new RuntimeException('Pengujian hanya diizinkan pada MySQL banksampah_testing. Periksa phpunit.xml dan jalankan php artisan config:clear.');
        }

        return $app;
    }
}
