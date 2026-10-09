<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Entity\Template\MailTemplate;
use App\Entity\Template\TemplateFolder;

/**
 * MailTemplate and TemplateFolder as JMAP objects, and their state tokens.
 *
 * One class for both, like the two Template methods' shared vocabulary: the
 * two objects name the same places (`accountId`, and a folder id) and a client
 * joins them, so one spelling per property has to survive in one file.
 *
 * `accountId` is a JMAP account id — the same string the Session lists — or
 * null for the top level. It is a PROPERTY here, not the method argument: the
 * objects belong to the user, and which mail account one is filed under is a
 * fact about it.
 *
 * `htmlBody` is the stored body: sanitised HTML with the variables in it as
 * `{{tokens}}`. That is what a client edits and writes back. What a client
 * INSERTS is `Template/render`'s answer, never this.
 */
final class TemplateMapper
{
    /** Every property of a Template, in the order they are documented. */
    public const array TEMPLATE_PROPERTIES = ['id', 'name', 'subject', 'htmlBody', 'accountId', 'folderId'];

    /** Every property of a TemplateFolder. */
    public const array FOLDER_PROPERTIES = ['id', 'name', 'accountId', 'parentId'];

    /**
     * @return array<string, mixed>
     */
    public function template(MailTemplate $template): array
    {
        return [
            'id'        => (string) $template->id,
            'name'      => $template->name,
            'subject'   => $template->subject,
            'htmlBody'  => $template->body,
            'accountId' => null === $template->account ? null : (string) $template->account->id,
            'folderId'  => null === $template->folder ? null : (string) $template->folder->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function folder(TemplateFolder $folder): array
    {
        return [
            'id'        => (string) $folder->id,
            'name'      => $folder->name,
            'accountId' => null === $folder->account ? null : (string) $folder->account->id,
            'parentId'  => null === $folder->parent ? null : (string) $folder->parent->id,
        ];
    }

    /**
     * A state token for one of the two lists, derived from the objects.
     *
     * Not from the change log, for the reason AppearanceMapper::state() gives:
     * the log is keyed by mail account and these belong to the user. A hash of
     * the whole list differs exactly when something in it differs, which is
     * all a client needs to know whether to fetch again — and the list is a
     * few dozen short objects, so fetching again is one small call. It is not
     * monotonic, so there is no Template/changes.
     *
     * Sorted by id first, so the token does not move when only the order the
     * rows came back in did.
     *
     * @param list<array<string, mixed>> $objects already mapped
     */
    public function state(array $objects): string
    {
        usort($objects, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return substr(hash('xxh128', json_encode($objects, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
