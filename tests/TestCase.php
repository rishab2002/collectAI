<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stubs out the @vite directive so rendering a view doesn't require
        // public/build/manifest.json. Tests assert on markup and behaviour, and
        // making them depend on a frontend build would mean a clean checkout
        // fails its own suite until `npm run build` has been run.
        $this->withoutVite();
    }
}
