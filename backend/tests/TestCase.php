<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost:8000'); // cocokin ke salah satu SANCTUM_STATEFUL_DOMAINS
    }
}
