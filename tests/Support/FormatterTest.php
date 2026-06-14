<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use WPPoland\StorefrontKit\Support\Formatter;

/**
 * @covers \WPPoland\StorefrontKit\Support\Formatter
 */
final class FormatterTest extends TestCase
{
    public function testInterpolateReplacesNamedTokens(): void
    {
        self::assertSame(
            'Code SAVE10 saves 10%',
            Formatter::interpolate('Code {code} saves {percent}%', [
                'code' => 'SAVE10',
                'percent' => 10,
            ]),
        );
    }

    public function testInterpolateLeavesUnknownTokensUntouched(): void
    {
        self::assertSame(
            'Hello {name}',
            Formatter::interpolate('Hello {name}', ['other' => 'x']),
        );
    }

    public function testInterpolateCastsScalarsToString(): void
    {
        self::assertSame(
            'a=1 b= c=1',
            Formatter::interpolate('a={int} b={null} c={bool}', [
                'int' => 1,
                'null' => null,
                'bool' => true,
            ]),
        );
    }

    public function testInterpolateReplacesAllOccurrencesOfAToken(): void
    {
        self::assertSame(
            'X-X-X',
            Formatter::interpolate('{v}-{v}-{v}', ['v' => 'X']),
        );
    }

    public function testInterpolateWithNoValuesReturnsTemplateVerbatim(): void
    {
        self::assertSame('untouched {x}', Formatter::interpolate('untouched {x}', []));
    }
}
