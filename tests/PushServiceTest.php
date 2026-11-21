<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use Minishlink\WebPush\WebPush;

final class PushServiceTest extends TestCase
{
    /**
     * web-push 11 no longer ships its own HTTP client but discovers a PSR-18 client at runtime.
     * Without one installed, sending push notifications fails although everything else works.
     */
    public function testWebPushFindsAnHttpClient(): void
    {
        self::assertInstanceOf(WebPush::class, new WebPush([], ['TTL' => 60]));
    }
}
