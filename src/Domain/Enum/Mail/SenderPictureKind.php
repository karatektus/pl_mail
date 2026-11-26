<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * Where a sender's picture can come from (#639), in the order a row tries them.
 *
 * Two families, and the difference decides almost everything else about them.
 * `LocalUser` and `Contact` are the reader's own data — a colleague's profile
 * picture on the same server, a photo in the address book — so they are read
 * when the page is drawn, need no permission and never leave the server. The
 * other three are LOOKUPS: somebody on the internet is asked, in the
 * background, and what they answered is stored. Only those three have rows in
 * `sender_picture`, an admin switch and a per-user choice.
 *
 * The lookups are keyed by different things. A Libravatar/Gravatar picture
 * belongs to an ADDRESS. A BIMI logo belongs to the From line's DOMAIN, exactly
 * as written, because the record is published per domain and a subdomain may
 * publish its own. A website icon belongs to the REGISTRABLE domain, because
 * `news.shop.example` has no website of its own and `shop.example` does.
 *
 * The case order is the rank: the first source that has a picture wins, and
 * the live update (SenderPictureNotifier) replaces a picture only with a
 * better-ranked one — see rank().
 */
enum SenderPictureKind: string
{
    /** Another plMail user on this server: their profile picture. */
    case LocalUser = 'user';

    /** A photo on one of the reader's own contacts. */
    case Contact = 'contact';

    /**
     * The domain's BIMI logo — only on a message the receiving server said was
     * authentic, because a logo is a claim about who sent it and a forged From
     * line must not borrow it. See SenderPictures::for().
     */
    case Bimi = 'bimi';

    /** Libravatar (federated, if the domain runs its own) or Gravatar, by address hash. */
    case Hashed = 'hashed';

    /** The icon the sender's website declares for itself. */
    case Website = 'website';

    /**
     * Whether a server on the internet is asked. The three that are have rows,
     * a background job and a switch; the other two are read in place.
     */
    public function isLookup(): bool
    {
        return match ($this) {
            self::LocalUser, self::Contact            => false,
            self::Bimi, self::Hashed, self::Website  => true,
        };
    }

    /** Lower wins. The numbers are what the page and the live update compare. */
    public function rank(): int
    {
        return match ($this) {
            self::LocalUser => 0,
            self::Contact   => 1,
            self::Bimi      => 2,
            self::Hashed    => 3,
            self::Website   => 4,
        };
    }

    /**
     * Whether a reader who has said nothing gets this one.
     *
     * Gravatar is OFF until asked for, and it is the only one: every address a
     * reader receives mail from would be sent, as a hash, to a company that is
     * neither the reader nor the sender, and a hash of an address is not much
     * of a disguise. A BIMI logo and a website icon are fetched from the
     * sender's own domain, which already knows it sent the mail.
     */
    public function onByDefault(): bool
    {
        return match ($this) {
            self::LocalUser, self::Contact, self::Bimi, self::Website => true,
            self::Hashed                                              => false,
        };
    }

    /** @return list<self> the three that are looked up, in rank order */
    public static function lookups(): array
    {
        return [self::Bimi, self::Hashed, self::Website];
    }
}
