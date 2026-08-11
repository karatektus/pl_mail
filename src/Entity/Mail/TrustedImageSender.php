<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Helper\AddressHelper;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Mail\TrustedImageSenderRepository;
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

    /**
     * The canonical sender address. Normalised on write the way Contact does,
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

            if (false === AddressHelper::isValidEmail($value)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid email address.', $value));
            }

            $this->address = AddressHelper::email($value);
        }
    }
}
