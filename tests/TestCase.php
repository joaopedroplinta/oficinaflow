<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum só liga sessão/cookies para origens "stateful" (o SPA).
        $this->withHeader('Referer', 'http://localhost:8000');
    }
}
