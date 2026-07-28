<?php

namespace App\Domain\Enum;

enum ThreadingMethod: string
{
    /** Grouped by the provider's own conversation id (Gmail threadId, Graph conversationId). */
    case Provider = 'provider';
    case References = 'references';
    case SubjectFallback = 'subject_fallback';
}
