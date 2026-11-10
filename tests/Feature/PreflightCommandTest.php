<?php

namespace Tests\Feature;

use Tests\TestCase;

class PreflightCommandTest extends TestCase
{
    public function test_it_fails_when_debug_is_enabled_in_production(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', true);

        $this->artisan('app:preflight')->assertExitCode(1);
    }

    public function test_it_passes_for_a_sane_production_configuration(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', false);

        $this->artisan('app:preflight')->assertExitCode(0);
    }

    public function test_it_warns_about_disabled_session_encryption(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('session.encrypt', false);

        $this->artisan('app:preflight')
            ->expectsOutputToContain('SESSION_ENCRYPT=false')
            ->assertExitCode(0);
    }
}
