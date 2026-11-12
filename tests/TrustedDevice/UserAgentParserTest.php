<?php

declare(strict_types=1);

/*
 * This file is part of the Herse Bundle.
 *
 * (c) Loïc Sapone <loic@sapone.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace IQ2i\HerseBundle\Tests\TrustedDevice;

use IQ2i\HerseBundle\TrustedDevice\UserAgentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAgentParserTest extends TestCase
{
    #[DataProvider('provideUserAgents')]
    public function testTheLabelIsDerivedFromTheUserAgent(?string $userAgent, ?string $label): void
    {
        self::assertSame($label, (new UserAgentParser())->parse($userAgent));
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function provideUserAgents(): iterable
    {
        yield 'firefox on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox (macOS)'];
        yield 'chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'Chrome (Windows)'];
        yield 'edge, which also says Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0', 'Edge (Windows)'];
        yield 'opera, which also says Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36 OPR/113.0.0.0', 'Opera (Linux)'];
        yield 'safari on iOS, which also says Mac OS X' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari (iOS)'];
        yield 'chrome on Android, which also says Linux' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/128.0.0.0 Mobile Safari/537.36', 'Chrome (Android)'];
        yield 'a browser without a known system' => ['Firefox/130.0', 'Firefox'];
        yield 'a system without a known browser' => ['Custom client (Windows NT 10.0)', 'Windows'];
        yield 'an unknown client' => ['curl/8.4.0', null];
        yield 'a text chosen by the client' => ['Your account is locked, call +33 1 23 45 67 89', null];
        yield 'no user agent' => [null, null];
        yield 'a blank user agent' => ['  ', null];
    }
}
