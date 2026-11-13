<?php

declare(strict_types=1);

namespace App\Domain\Enum\MessageList;

/**
 * When a list row becomes one line (#608).
 *
 * The row has three shapes and the LIST's width picks one: three stacked lines
 * on a narrow list, sender and subject over the preview on a middling one, and
 * one line on a wide one. What this decides is where the last step happens.
 *
 * Fit is the default because it is what the default preview setting already
 * did before this was a choice of its own: with one preview line the row went
 * to one line from 48rem, the way Gmail's list reads. Wide is the 75rem line
 * measured for real French and English subjects (see app.css), which leaves a
 * usable preview beside the subject. Stacked never goes to one line at all.
 *
 * It used to be implied by the preview-lines count, which is why "one line of
 * preview" also meant "a denser list". The two are separate now: the preview
 * says what a stacked row shows, and this says when a row stops stacking.
 */
enum RowLayout: string
{
    case Wide    = 'wide';
    case Fit     = 'fit';
    case Stacked = 'stacked';
}
