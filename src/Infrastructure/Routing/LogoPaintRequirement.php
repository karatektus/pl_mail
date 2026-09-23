<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use Symfony\Component\Routing\Requirement\EnumRequirement;

/**
 * The route requirement for a logo paint: `original`, or any colourway.
 *
 * A Stringable rather than a regex in the route attribute, because an attribute
 * argument must be a constant expression and the colourways are LogoStyle's
 * cases — written out there, they would be a second copy of the enum that
 * drifts the day a colourway is added, and a new colourway would 404 on its own
 * icon. Symfony's EnumRequirement is the same idea for a whole enum; this is
 * that, plus the one word of the paint vocabulary no enum case carries.
 */
final class LogoPaintRequirement implements \Stringable
{
    public function __toString(): string
    {
        return sprintf('%s|%s', preg_quote(LogoMotif::ORIGINAL), new EnumRequirement(LogoStyle::class));
    }
}
