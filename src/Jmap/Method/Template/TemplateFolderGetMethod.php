<?php

declare(strict_types=1);

namespace App\Jmap\Method\Template;

use App\Entity\Template\TemplateFolder;
use App\Jmap\Mapper\TemplateMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Template\TemplateFolderRepository;

/**
 * "TemplateFolder/get" — plMail extension, `urn:plmail:params:jmap:templates`.
 *
 * The folders the user MADE. The folder-per-mail-account that opens the tree
 * in the web UI is not among them and has no id: it is not stored (see
 * App\Entity\Template\TemplateFolder), and a client draws it the way the web
 * does — one node per account in the Session, holding every template and
 * folder whose `accountId` is that account and whose folder/parent is null.
 */
final class TemplateFolderGetMethod implements JmapMethod
{
    use TemplateMethodSupport;

    public function __construct(
        private readonly TemplateFolderRepository $folders,
        private readonly TemplateMapper           $mapper,
    ) {
    }

    public function name(): string
    {
        return 'TemplateFolder/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $this->refuseAccountId($arguments, 'TemplateFolder');

        $properties = $this->requestedProperties($arguments['properties'] ?? null, TemplateMapper::FOLDER_PROPERTIES, 'TemplateFolder');
        $ids        = $this->requestedIds($arguments['ids'] ?? null, $context);

        $objects = array_map(
            fn (TemplateFolder $folder): array => $this->mapper->folder($folder),
            $this->folders->findForUser($context->user),
        );

        return ['state' => $this->mapper->state($objects)] + $this->select($objects, $ids, $properties);
    }
}
