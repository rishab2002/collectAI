<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the test environment itself.
 *
 * This exists because the suite once ran against the *development* database and
 * truncated it: docker-compose was injecting .env into the container's process
 * environment, PHP exposed those values via $_SERVER, and Laravel's Dotenv reads
 * $_SERVER ahead of everything PHPUnit sets — so phpunit.xml was ignored even
 * with force="true".
 *
 * RefreshDatabase is destructive by design, so "which database am I pointed at"
 * is worth an explicit assertion rather than an assumption.
 */
class EnvironmentTest extends TestCase
{
    public function test_the_suite_runs_against_the_dedicated_test_database(): void
    {
        $this->assertSame('diecast_testing', config('database.connections.pgsql.database'));
        $this->assertNotSame('diecast', config('database.connections.pgsql.database'));
    }

    public function test_the_application_is_in_the_testing_environment(): void
    {
        $this->assertSame('testing', config('app.env'));
        $this->assertTrue(app()->environment('testing'));
    }

    public function test_the_schema_runs_on_postgres(): void
    {
        // SQLite would silently skip pg_trgm, the COALESCE expression index and
        // the CHECK constraints, leaving the suite testing a different schema.
        $this->assertSame('pgsql', config('database.default'));
    }
}
