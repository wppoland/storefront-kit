<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the storefront-kit engine test suite.
 *
 * Loads Composer's autoloader and a small set of WooCommerce class doubles
 * (see wc-doubles.php) that stand in for the real WC_* classes the engines
 * type-hint against. WordPress/WooCommerce *functions* are stubbed per-test
 * with Brain\Monkey (see Tests\TestCase), so no real WP runtime is required.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wc-doubles.php';
