<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\Isbn;
use PHPUnit\Framework\TestCase;

final class IsbnTest extends TestCase
{
    public function testCleansHyphensAndSpaces(): void
    {
        self::assertSame('9783404139873', Isbn::clean(' 978-3-404-13987-3 '));
        self::assertSame('344249723X', Isbn::clean('3-442-49723-x'));
        self::assertSame('9783404139873', Isbn::clean('="9783404139873"'));
    }

    public function testValidation(): void
    {
        self::assertTrue(Isbn::isValid13('9783404139873'));
        self::assertFalse(Isbn::isValid13('9783404139874'));
        self::assertTrue(Isbn::isValid10('344249723X'));
        self::assertTrue(Isbn::isValid10('3404139879'));
        self::assertFalse(Isbn::isValid10('B004WPHWKU'));
    }

    public function testConversion(): void
    {
        self::assertSame('9783442497232', Isbn::to13('344249723X'));
        self::assertSame('344249723X', Isbn::to10('9783442497232'));
        self::assertNull(Isbn::to10('9791032305690') ?? null);
    }

    public function testNormalize(): void
    {
        self::assertSame(['9783404139873', '3404139879'], Isbn::normalize('978-3-404-13987-3'));
        self::assertSame(['9783404139873', '3404139879'], Isbn::normalize('3404139879'));
        self::assertSame([null, null], Isbn::normalize('B004WPHWKU'));
        self::assertSame([null, null], Isbn::normalize(''));
    }
}
