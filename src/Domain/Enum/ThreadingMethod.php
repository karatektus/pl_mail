<?php

namespace App\Domain\Enum;

enum ThreadingMethod: string
{
    case References = 'references';
    case SubjectFallback = 'subject_fallback';
}
