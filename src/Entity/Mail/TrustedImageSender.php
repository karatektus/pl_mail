<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Helper\AddressHelper;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Mail\TrustedImageSender\TrustedImageSenderRepository;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * "Always show images from this sender", per user.
 *
 * Its own table rather than a key in User::$settings, which is where the free-
 * form preferences live. Two reasons, and the second is the real one:
 *
 *  - It grows without bound. The settings bag is documented as the home for the
 *    long tail of UI state, each entry a fixed key; an allowlist that gains a
 *    row every time someone clicks a button is a collection, and collections
 *    belong in tables.
 *  - It is a security decision. A unique constraint is what makes "trusted"
 *    idempotent — a double-clicked button, or two tabs on the same message,
 *    must not be able to produce two rows or a lost update. A JSON array
 *    read-modify-written under concurrency has no such guarantee.
 *
 * Scoped to the user, not to the account: the reader is the person whose IP
 * leaks, and they do not become a different person by reading the same
 * newsletter in a second mailbox.
 *
 * An entry is one of three things (#807):
 *
 *  - an address, `news@example.com`;
 *  - a whole domain, `*@example.com`;
 *  - a pattern, `invoice+*@stripe.com`: a `*` anywhere before the `@` stands
 *    for any run of characters.
 *
 * The two wildcard forms are for senders who never send from the same address
 * twice. Stripe's receipts come from `invoice+statements+acct_…@stripe.com`,
 * so an entry for one of them never matched the next; the pattern trusts the
 * receipts without trusting everything else Stripe sends. The domain part is
 * always exact, never a wildcard and never its subdomains, because a sender
 * cannot pick the domain its mail comes from the way it picks a local part,
 * and that is the part worth being strict about.
 *
 * All three in the one column: the unique constraint then covers every form.
 * A `*` is a legal character in a local part, but nobody's real address has
 * one, and a pattern still matches the literal address it spells.
 */
#[ORM\Entity(repositoryClass: TrustedImageSenderRepository::class)]
#[ORM\Table(name: 'trusted_image_sender')]
#[ORM\UniqueConstraint(name: 'uniq_trusted_image_sender', columns: ['usr_id', 'address'])]
#[ORM\HasLifecycleCallbacks]
class TrustedImageSender
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Not nullable, the way Message::$account is not nullable: the column is
     * NOT NULL, so a null here would be a state the database cannot hold and
     * every reader would be made to check for it anyway.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /** What a domain entry starts with, in front of the domain. */
    public const string DOMAIN_PREFIX = '*@';

    /**
     * The canonical sender address, or `*@domain`. Normalised on write the way Contact does,
     * so that `Sender@Example.COM` and `sender@example.com` cannot become two
     * rows with one of them silently never matching.
     *
     * Doctrine hydrates through `RawValuePropertyAccessor`, which skips hooks,
     * so this runs on application writes only and never re-checks a stored row.
     */
    #[ORM\Column(length: 320)]
    public string $address {
        set (string $value) {
            if ('' === trim($value)) {
                throw new InvalidArgumentException('A trusted image sender needs an address.');
            }

            $entry = self::entryFor($value);

            if (null === $entry) {
                throw new InvalidArgumentException(sprintf('"%s" is neither an email address nor *@domain.', $value));
            }

            $this->address = $entry;
        }
    }

    /** Whether this entry covers a whole domain rather than one address. */
    public bool $isDomain {
        get => str_starts_with($this->address, self::DOMAIN_PREFIX);
    }

    /** Whether this entry is a pattern: a `*` in the local part, and not a whole domain. */
    public bool $isPattern {
        get => self::isPatternEntry($this->address);
    }

    public static function isPatternEntry(string $entry): bool
    {
        return false === str_starts_with($entry, self::DOMAIN_PREFIX) && str_contains($entry, '*');
    }

    /**
     * Whether a pattern entry matches a normalised address. The `*` is the only
     * special character; everything else, dots and pluses included, is itself.
     */
    public static function patternMatches(string $pattern, string $address): bool
    {
        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/u';

        return 1 === preg_match($regex, $address);
    }

    /**
     * What someone typed into the settings card, as the entry it means, or null
     * when it means none.
     *
     * Generous about the domain form, because people write it three ways:
     * `*@stripe.com`, `@stripe.com` and plain `stripe.com`. A `*` in the domain
     * part is refused (`*.stripe.com`, `foo@*.de`): it reads as "and every
     * subdomain", which no entry does. A pattern is checked as the address it
     * would be with each `*` filled in, so it cannot smuggle in anything an
     * address could not hold.
     */
    public static function entryFor(?string $input): ?string
    {
        $input = AddressHelper::email($input);

        if ('' === $input) {
            return null;
        }

        if (false === str_contains($input, '@') || str_starts_with($input, '@') || str_starts_with($input, self::DOMAIN_PREFIX)) {
            $domain = ltrim(substr($input, (int) strrpos($input, '@')), '@');

            return '' !== $domain && false === str_contains($domain, '*') && true === AddressHelper::isValidEmail('x@' . $domain)
                ? self::DOMAIN_PREFIX . $domain
                : null;
        }

        $at = (int) strrpos($input, '@');

        if (true === str_contains(substr($input, $at), '*')) {
            return null;
        }

        return true === AddressHelper::isValidEmail(str_replace('*', 'x', $input)) ? $input : null;
    }

    /** The domain entry that would cover an address, or null for no address. */
    public static function domainEntryFor(?string $address): ?string
    {
        $address = AddressHelper::email($address);
        $at      = strrpos($address, '@');

        if (false === $at || false === AddressHelper::isValidEmail($address)) {
            return null;
        }

        return self::DOMAIN_PREFIX . substr($address, $at + 1);
    }
}
