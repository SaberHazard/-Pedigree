<?php

namespace Tests;

use App\Services\Social\SafeHttp;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // هیچ تستی نباید به اینترنت وصل شود
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        SafeHttp::fakeResolver(null);
        parent::tearDown();
    }
}
