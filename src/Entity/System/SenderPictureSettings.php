<?php

declare(strict_types=1);

namespace App\Entity\System;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admin → Sender pictures: whether this server may look pictures up on the
 * internet at all (#639).
 *
 * OFF until an administrator turns it on, and that is the whole point of it.
 * With it on, the server makes requests nobody asked it to make before — to
 * Gravatar, to the sender's domain — on a schedule set by whoever mails its
 * users. Some installs sit behind a firewall that would rightly ask why; some
 * administrators would rather their server talk to nobody it does not have to.
 * That is a decision about the server, so it is the administrator's, and each
 * user's own switches (User::allowsSenderPicture()) only choose among what it
 * allows.
 *
 * Contact photos and other users' profile pictures do not depend on it: they
 * are already on the server.
 *
 * One row, like WorkerSettings and LogSettings, for the reason they give.
 */
#[ORM\Entity(repositoryClass: SenderPictureSettingsRepository::class)]
#[ORM\Table(name: 'sender_picture_settings')]
#[ORM\UniqueConstraint(name: 'uniq_sender_picture_settings_singleton', columns: ['singleton'])]
#[ORM\HasLifecycleCallbacks]
class SenderPictureSettings
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /** Always 1; exists so the unique index above has a column to be unique over. */
    #[ORM\Column(options: ['default' => 1])]
    public private(set) int $singleton = 1;

    #[ORM\Column(options: ['default' => false])]
    public bool $lookupsEnabled = false;
}
