<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Mail\FetchedPicture;
use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Exception\SenderPictureUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One place on the internet a sender's picture can be looked up (#639).
 *
 * The three answers mean three different things to the caller, and the
 * signature keeps them apart: a picture; null, "asked, and there is none",
 * which is believed for a fortnight; and the exception, "nobody answered",
 * which is asked again tomorrow. See SenderPictureStatus for why the last two
 * must not be the same.
 *
 * Implementations live under Service/Mail/SenderPicture/Lookup and are found
 * by kind through the tagged iterator in SenderPictureResolver, so a fourth
 * source is a class, not a branch.
 */
#[AutoconfigureTag('app.sender_picture_lookup')]
interface SenderPictureLookupInterface
{
    public function kind(): SenderPictureKind;

    /**
     * @param string $subject the key SenderPictureSubject::of() made for this kind
     *
     * @throws SenderPictureUnavailableException when nobody answered usefully
     */
    public function lookup(string $subject): ?FetchedPicture;
}
