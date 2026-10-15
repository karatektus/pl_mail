<?php

declare(strict_types=1);

namespace App\Service\System\WhatsNew;

use App\Domain\DTO\System\ChangelogRelease;
use App\Entity\User\User;
use App\Service\System\AppVersion;

/**
 * Which release notes a person has not seen, and whether to show them (#165).
 *
 * ── The version that counts ──────────────────────────────────────────────────
 * Only a release: AppVersion's label when it is `vX.Y.Z`, which is what the
 * image build stamps from the tag. A `main` or `sha-…` image, and a checkout
 * whose label is a `git describe`, answer null, and with no running release
 * nothing opens by itself and the dot never shows. What those builds hold is
 * either a release's notes plus unreleased work, or not plMail's history at
 * all, and "New in 0.3.6" over code that is not 0.3.6 would be wrong in both
 * directions.
 *
 * ── Where somebody starts ────────────────────────────────────────────────────
 * The release they last saw, kept in the user's settings bag. After an update,
 * never on an install: Version20261016090000 gave everybody who was already
 * here 0.3.1 — the owner's starting point, so nobody is handed a hundred and
 * fifty releases — and everybody created since starts at the release that was
 * running when they first came in (WhatsNewFirstSighting). A person with
 * nothing recorded yet is read as standing on the running release, so the
 * request that records it cannot open the dialog first.
 *
 * ── Seen means drawn ─────────────────────────────────────────────────────────
 * markSeen() is called when the dialog is drawn, not when it is closed. A
 * dialog can go away without anyone choosing it — a navigation, a reload, a
 * phone locking — and "closed" would then never arrive and the notes would
 * come back on every page until it did. Somebody who saw the list and
 * navigated away saw it.
 */
final readonly class WhatsNew
{
    public function __construct(
        private ChangelogReader $changelog,
        private AppVersion      $appVersion,
    ) {
    }

    /** The running release as `0.3.6`, or null for anything that is not one. */
    public function running(): ?string
    {
        if (1 !== preg_match('/^v?(\d+\.\d+\.\d+)$/', trim($this->appVersion->label()), $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * The last release this person was shown. Nothing recorded reads as the
     * running release — somebody new, see the class docblock — and, with no
     * running release either, as nothing at all ('0.0.0').
     */
    public function seenBy(User $user): string
    {
        return $user->whatsNewSeenVersion ?? $this->running() ?? '0.0.0';
    }

    /**
     * Give somebody with nothing recorded the running release as their
     * starting point. True when it wrote, so the caller knows to flush.
     */
    public function startIfNew(User $user): bool
    {
        $running = $this->running();

        if (null === $running || null !== $user->whatsNewSeenVersion) {
            return false;
        }

        $user->whatsNewSeenVersion = $running;

        return true;
    }

    /**
     * Releases after $since and up to the running one, newest first. Empty
     * when no release is running.
     *
     * @return list<ChangelogRelease>
     */
    public function since(string $since): array
    {
        $running = $this->running();

        if (null === $running) {
            return [];
        }

        return array_values(array_filter(
            $this->changelog->releases(),
            static fn (ChangelogRelease $release): bool => version_compare($release->version, $since, '>')
                && version_compare($release->version, $running, '<='),
        ));
    }

    /**
     * Releases at or below the running one — every release a running install
     * has — newest first. With no running release, all of them: a checkout is
     * where the notes get written and read back.
     *
     * @return list<ChangelogRelease>
     */
    public function history(): array
    {
        $running = $this->running();

        if (null === $running) {
            return $this->changelog->releases();
        }

        return array_values(array_filter(
            $this->changelog->releases(),
            static fn (ChangelogRelease $release): bool => version_compare($release->version, $running, '<='),
        ));
    }

    /** Whether there is anything this person has not been shown. */
    public function hasUnseen(User $user): bool
    {
        return [] !== $this->since($this->seenBy($user));
    }

    /**
     * Whether the dialog should open by itself, and the ? carry a dot. One
     * question: somebody who asked for quiet gets neither.
     */
    public function shouldAnnounce(User $user): bool
    {
        return false === $user->whatsNewQuiet && $this->hasUnseen($user);
    }

    /**
     * Record the running release as seen. Never moves backwards: rolling the
     * image back to an older release must not make its notes "new" again on
     * the way forward.
     */
    public function markSeen(User $user): void
    {
        $running = $this->running();

        if (null === $running || version_compare($running, $this->seenBy($user), '<=')) {
            return;
        }

        $user->whatsNewSeenVersion = $running;
    }
}
