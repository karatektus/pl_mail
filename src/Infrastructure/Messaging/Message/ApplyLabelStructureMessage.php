<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Push a label create/rename/delete out to the account's provider.
 *
 * Carries scalars only, never entities: the handler runs in a worker with its
 * own EntityManager, and a delete has no row left to load by the time it runs.
 * That is also why the remote id and full name are captured at dispatch rather
 * than looked up later.
 */
readonly class ApplyLabelStructureMessage
{
    public const string ACTION_CREATE = 'create';
    public const string ACTION_RENAME = 'rename';
    public const string ACTION_DELETE = 'delete';

    /**
     * @param string|null $remoteId         Gmail label id, or Exchange FOLDER id
     * @param string|null $categoryRemoteId Exchange master category id, which is
     *                                      a different thing in a different
     *                                      column — see LabelBinding
     * @param string|null $previousFullName what the label was called before a
     *                                      rename, and the only way to find an
     *                                      Exchange category whose id was never
     *                                      recorded: there, the display name IS
     *                                      the identity
     */
    public function __construct(
        public int $accountId,
        public string $action,
        public ?int $labelId,
        public string $fullName,
        public ?string $remoteId = null,
        public ?string $parentRemoteId = null,
        public ?string $categoryRemoteId = null,
        public ?string $previousFullName = null,
    ) {
    }
}
