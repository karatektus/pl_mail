<?php

declare(strict_types=1);

namespace App\Domain\Enum\System;

/**
 * Which published builds count as an update for this installation.
 *
 * One image tag per channel, because the tag is what an installation pulls: a
 * channel that followed anything else (a branch, a commit feed) would announce
 * builds nobody can install. The docs-only commits on main are the proof: they
 * build no image, so a checker reading the branch instead of the tag would say
 * "update available" and the pull would bring back the same container.
 *
 * The values are stored.
 */
enum UpdateChannel: string
{
    /** Every tagged release: the image tagged `latest`. */
    case Releases = 'releases';

    /** Every build of the main branch, released or not: the image tagged `main`. */
    case Main = 'main';

    /** No checking at all. Nothing leaves the installation for it. */
    case Off = 'off';

    /** The image tag this channel follows, or null when it follows none. */
    public function imageTag(): ?string
    {
        return match ($this) {
            self::Releases => 'latest',
            self::Main     => 'main',
            self::Off      => null,
        };
    }

    /**
     * The channel an installation follows until an administrator picks one:
     * the one its own build came from.
     *
     * A release image was pulled by a release tag and a main image by `main`,
     * so each is told about what its own tag will deliver next. APP_VERSION is
     * the tag the image was built for (`v0.2.44`, `main`); anything else is a
     * checkout nobody built, which has no commit to compare and does not check.
     */
    public static function forBuild(string $version): self
    {
        return match (true) {
            'main' === $version                                 => self::Main,
            1 === preg_match('/^v?\d+\.\d+\.\d+$/', $version) => self::Releases,
            default                                             => self::Off,
        };
    }
}
