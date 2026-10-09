<?php

declare(strict_types=1);

namespace App\Jmap\Method\Template;

use App\Entity\Template\MailTemplate;
use App\Jmap\Mapper\TemplateMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Template\MailTemplateRepository;

/**
 * "Template/get" — plMail extension, `urn:plmail:params:jmap:templates`.
 *
 * The user's templates as they are stored: name, subject and body, with the
 * variables still in them as `{{tokens}}`. This is the object a client lists
 * and edits. To put one into a message, call `Template/render` — it is the
 * only place the tokens are turned into values, and doing that client-side
 * would mean a second reading of the grammar and a third of the signature
 * rules.
 *
 * `ids: null` returns all of them. There is no Template/query: a user has a
 * few dozen, the client shows them as a tree it builds from `accountId` and
 * `folderId`, and filtering a list that size is not a server's job.
 */
final class TemplateGetMethod implements JmapMethod
{
    use TemplateMethodSupport;

    public function __construct(
        private readonly MailTemplateRepository $templates,
        private readonly TemplateMapper         $mapper,
    ) {
    }

    public function name(): string
    {
        return 'Template/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $this->refuseAccountId($arguments, 'Template');

        $properties = $this->requestedProperties($arguments['properties'] ?? null, TemplateMapper::TEMPLATE_PROPERTIES, 'Template');
        $ids        = $this->requestedIds($arguments['ids'] ?? null, $context);

        $objects = array_map(
            fn (MailTemplate $template): array => $this->mapper->template($template),
            $this->templates->findForUser($context->user),
        );

        return ['state' => $this->mapper->state($objects)] + $this->select($objects, $ids, $properties);
    }
}
