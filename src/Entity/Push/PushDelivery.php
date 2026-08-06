<?php

declare(strict_types=1);

namespace App\Entity\Push;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Domain\Enum\PushTransport;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Push\PushDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One attempt to deliver one push payload to one device, and what came of it.
 *
 * Push is the only thing this server does that leaves no trace anywhere the
 * user or the admin can look. Mail that fails to send is a draft still sitting
 * in the folder; a sync that fails leaves a log line somebody grep'd for. A
 * notification that never arrived is indistinguishable from one the user did
 * not notice — which makes "it works on my phone but not on hers" unanswerable
 * without this table.
 *
 * **The payload's `@type` is recorded and NOTHING else of it.** A StateChange
 * carries the account ids and state tokens that moved, and keeping those would
 * turn this into a per-user index of when mail arrives, who is busy at 3am and
 * which accounts are live — a copy of everyone's mail activity, retained for
 * weeks, readable by any admin, in a table nobody would think to check before
 * handing over a database dump. The type alone answers the only question the
 * log is for: whether the verification handshake or ordinary traffic was being
 * carried. There is deliberately no column for the changed map, and adding one
 * would be a privacy regression rather than a feature.
 *
 * **Addressed by deviceClientId, not by a foreign key to the subscription.**
 * The most interesting record this table holds is the one written as a
 * subscription is destroyed — an FCM UNREGISTERED, a Web Push 410 — and a
 * relation would either take that row down with it (ON DELETE CASCADE, erasing
 * the evidence at the moment it is created) or refuse the delete. The device id
 * is client-chosen and stable across re-registration, so it also survives the
 * rotation that replaces the row, which is what makes a device's history
 * readable as one story rather than as a series of unrelated ids.
 *
 * Written by PushDeliveryRecorder from inside the senders, read by /admin/push
 * and by the user's own notification settings, pruned by `app:monitoring:prune`.
 */
#[ORM\Entity(repositoryClass: PushDeliveryRepository::class)]
#[ORM\Table(name: 'push_delivery')]
// The admin browser's read, and the prune's: newest first, optionally narrowed.
// created_at leads because every query is bounded on it and the filters
// (transport, outcome) are low-cardinality — an index leading on either would
// still have to sort the whole matching set by date.
#[ORM\Index(name: 'idx_push_delivery_created_at', columns: ['created_at'])]
// The settings pane's read: the newest row per device of one user. usr_id
// first because a user's own page never scans across users, device second so
// the per-device lookup is a range on the same index rather than a filter
// after it.
#[ORM\Index(name: 'idx_push_delivery_device', columns: ['usr_id', 'device_client_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class PushDelivery
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Whose device this was.
     *
     * CASCADE, unlike the subscription reference above: a deleted user takes
     * their delivery history with them, because the history is only ever read
     * as "this person's devices" and keeping it would leave a per-device
     * timeline of an account that no longer exists.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** Client-chosen and stable per device+app — see the class docblock. */
    #[ORM\Column(name: 'device_client_id', length: 255)]
    public private(set) string $deviceClientId;

    /** Which sender made the attempt, copied off the subscription. */
    #[ORM\Column(length: 16, enumType: PushTransport::class)]
    public private(set) PushTransport $transport;

    /**
     * The `@type` of what was being carried — `StateChange`, `PushVerification`,
     * or whatever a future payload calls itself.
     *
     * A plain string rather than an enum: the sender records what the payload
     * actually said, and a payload type this server does not know about is a
     * fact worth keeping rather than a value to reject. Null where the object
     * carried no `@type` at all, which is a malformed payload and reads as one.
     */
    #[ORM\Column(name: 'payload_type', length: 64, nullable: true)]
    public private(set) ?string $payloadType = null;

    #[ORM\Column(length: 24, enumType: PushDeliveryOutcome::class)]
    public private(set) PushDeliveryOutcome $outcome;

    /**
     * The transport's own word for what happened: `HTTP 410`, `UNREGISTERED`,
     * `QUOTA_EXCEEDED`, or the first line of an exception.
     *
     * The one field that makes a failure actionable, and the reason recording
     * happens inside the senders rather than around them — the bool they return
     * has already thrown this away. Truncated to the column width by the
     * recorder rather than by the database, so a long exception message shortens
     * instead of failing the INSERT and losing the record of the failure it was
     * describing.
     */
    #[ORM\Column(length: 128, nullable: true)]
    public private(set) ?string $detail = null;

    /**
     * Wall-clock milliseconds spent in the attempt.
     *
     * Includes the OAuth token fetch on the FCM path when that had to happen,
     * because the user waiting for a notification waits for that too. Zero for
     * a skip, which never made a request.
     */
    #[ORM\Column(name: 'latency_ms')]
    public private(set) int $latencyMs = 0;

    public function __construct(
        User $usr,
        string $deviceClientId,
        PushTransport $transport,
        ?string $payloadType,
        PushDeliveryOutcome $outcome,
        ?string $detail,
        int $latencyMs,
    ) {
        $this->usr            = $usr;
        $this->deviceClientId = $deviceClientId;
        $this->transport      = $transport;
        $this->payloadType    = $payloadType;
        $this->outcome        = $outcome;
        $this->detail         = $detail;
        $this->latencyMs      = $latencyMs;
    }
}
