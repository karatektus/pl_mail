<?php

declare(strict_types=1);

namespace App\Domain\DTO;

/**
 * Value object produced by SearchQueryParser.
 * All fields are nullable / false by default — only set when the operator
 * was explicitly present in the query string.
 */
final class ParsedSearchQuery
{
    public string $freeText    = '';
    public ?string $label       = null;
    public ?string $from       = null;
    public ?string $to         = null;
    public ?string $cc         = null;
    public ?string $subject    = null;
    public ?bool $hasAttachment = null;
    public bool $isUnread      = false;
    public bool $isRead        = false;
    public bool $isStarred     = false;
    /** A label role, already resolved from what was typed — see SearchQueryParser::ROLES. */
    public ?string $mailboxRole = null;
    public ?\DateTimeImmutable $after  = null;
    public ?\DateTimeImmutable $before = null;

    public function isEmpty(): bool
    {
        return $this->freeText === ''
            && $this->label === null
            && $this->from === null
            && $this->to === null
            && $this->cc === null
            && $this->subject === null
            && $this->hasAttachment === null
            && !$this->isUnread
            && !$this->isRead
            && !$this->isStarred
            && $this->mailboxRole === null
            && $this->after === null
            && $this->before === null;
    }
}
