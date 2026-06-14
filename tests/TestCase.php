<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case wiring Brain Monkey (WP/WC function stubs) and Mockery
 * (WC_* class mocks) lifecycle around every test.
 *
 * The Mockery PHPUnit integration trait counts satisfied Mockery expectations
 * towards PHPUnit's assertion total, so behaviour-only tests (which assert via
 * `shouldReceive(...)->once()`) are not flagged as risky/assertion-less.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        // Mockery::close() is handled by MockeryPHPUnitIntegration's
        // post-condition hook (which also counts expectations as assertions).
        Monkey\tearDown();
        parent::tearDown();
    }
}
