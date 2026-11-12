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

namespace IQ2i\HerseBundle\TrustedDevice;

/**
 * Derives a short label from a User-Agent, for display only, null when it is not recognised. Deliberately minimal and language-neutral:
 * a full detection library is overkill for a label in an alert email.
 *
 * @internal
 */
final class UserAgentParser
{
    private const BROWSERS = [
        'Edge' => '#Edg/#',
        'Opera' => '#OPR/#',
        'Chrome' => '#Chrome/#',
        'Firefox' => '#Firefox/#',
        'Safari' => '#Version/.*Safari/#',
    ];

    // Order matters: an iOS User-Agent also says "like Mac OS X" and an Android one "Linux".
    private const SYSTEMS = [
        'iOS' => '#iPhone|iPad|iPod#',
        'Android' => '#Android#',
        'Windows' => '#Windows NT#',
        'macOS' => '#Mac OS X#',
        'Linux' => '#Linux#',
    ];

    public function parse(?string $userAgent): ?string
    {
        if (null === $userAgent || '' === trim($userAgent)) {
            return null;
        }

        $browser = $this->match(self::BROWSERS, $userAgent);
        $system = $this->match(self::SYSTEMS, $userAgent);

        return match (true) {
            null !== $browser && null !== $system => \sprintf('%s (%s)', $browser, $system),
            null !== $browser => $browser,
            null !== $system => $system,
            // Never the raw header: it is chosen by the client and ends up in an email sent to the account owner.
            default => null,
        };
    }

    /**
     * @param array<string, string> $patterns
     */
    private function match(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $label => $pattern) {
            if (1 === preg_match($pattern, $userAgent)) {
                return $label;
            }
        }

        return null;
    }
}
