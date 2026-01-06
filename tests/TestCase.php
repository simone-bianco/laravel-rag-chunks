<?php

namespace SimoneBianco\LaravelRagChunks\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SimoneBianco\LaravelRagChunks\LaravelRagChunksServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelRagChunksServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
    }
}
