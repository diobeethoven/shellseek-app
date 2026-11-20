<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No automated test may reach the network by accident (VM 2, Ollama,
        // anything). Tests fake what they need; the few real-socket tests on
        // 127.0.0.1 opt out explicitly.
        Http::preventStrayRequests();
    }
}
