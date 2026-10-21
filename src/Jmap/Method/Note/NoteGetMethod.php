<?php

declare(strict_types=1);

namespace App\Jmap\Method\Note;

use App\Entity\Note\Note;
use App\Jmap\Mapper\NoteMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Method\Template\TemplateMethodSupport;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Note\NoteRepository;

/**
 * "Note/get" — plMail extension, `urn:plmail:params:jmap:notes`.
 *
 * The user's notes. `ids: null` returns all of them, pinned first. There is no Note/query and no
 * Note/changes, for the reason Template/get gives: a user has a few hundred short objects and a
 * client filters and searches that list itself — including "which notes link to this event", which
 * is a lookup over the `links` it already holds. `state` is a hash of the list; when it differs
 * from the one a client holds, it fetches again.
 */
final class NoteGetMethod implements JmapMethod
{
    use TemplateMethodSupport;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly NoteMapper     $mapper,
    ) {
    }

    public function name(): string
    {
        return 'Note/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $this->refuseAccountId($arguments, 'Note');

        $properties = $this->requestedProperties($arguments['properties'] ?? null, NoteMapper::PROPERTIES, 'Note');
        $ids        = $this->requestedIds($arguments['ids'] ?? null, $context);

        $objects = array_map(
            fn (Note $note): array => $this->mapper->note($note),
            $this->notes->findForUser($context->user),
        );

        return ['state' => $this->mapper->state($objects)] + $this->select($objects, $ids, $properties);
    }
}
