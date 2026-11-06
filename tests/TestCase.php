<?php

namespace Tests;

use App\Ai\Contracts\StructuredModel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\FakeStructuredModel;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test gets the fake AI gateway, so no test can reach the Anthropic API.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(StructuredModel::class, FakeStructuredModel::class);
    }
}
