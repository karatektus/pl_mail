<?php

declare(strict_types=1);

namespace App\Service\Demo;

use App\Entity\User\User;

/**
 * The queue of mail the "receive" button delivers, and the bookmark saying how
 * far a given visitor has walked through it.
 *
 * A queue rather than one canned message, because the button is the only thing
 * on a demo a visitor is invited to press repeatedly, and pressing it three
 * times to watch the same mail arrive three times teaches them the button is
 * fake. Each entry instead shows a different part of the product: a plain
 * thread, an attachment chip, a filed label, a rich HTML body, a long thread
 * to collapse. Walking the list is the tour.
 *
 * The bookmark lives in the user's settings and wraps at the end, so a visitor
 * who presses it eight times sees the list twice rather than a dead button —
 * a button that stops working is indistinguishable from one that broke.
 *
 * Deliberately mundane, for the same reason SeedDemoMailboxCommand's threads
 * are: a demo inbox full of exciting mail reads as a mock-up.
 */
final readonly class DemoScenarios
{
    public const string SETTING_CURSOR = 'demo.scenario_cursor';

    /**
     * @return list<DemoScenario>
     */
    public function all(): array
    {
        return [
            new DemoScenario(
                key: 'shelves',
                subject: 'Re: quote for the trim',
                fromName: 'Marek Nowak',
                fromAddress: 'marek@nowak-schreinerei.example',
                bodyText: "Timber is ordered and the yard says Thursday week.\n\n"
                    . "I will bring the trim with the shelves so there is only one delivery to be "
                    . "in for. If Thursday is bad, Friday morning works just as well.\n\nMarek",
            ),
            new DemoScenario(
                key: 'invoice',
                subject: 'Invoice 2026-0912',
                fromName: 'Steuerbüro Lang',
                fromAddress: 'buchhaltung@lang-steuer.example',
                bodyText: "Attached is this quarter's invoice, due in 14 days.\n\n"
                    . 'Nothing needs signing. Reply here if the reference is wrong.',
                label: 'Receipts',
                attachment: ['Rechnung-2026-0912.txt', "Steuerbüro Lang\nInvoice 2026-0912\n\n"
                    . "Quarterly bookkeeping .................. 240,00 EUR\nVAT 19% ................................. 45,60 EUR\n"
                    . "                                        ----------\nTotal ................................... 285,60 EUR\n\n"
                    . "Payable within 14 days.\nThis is demo data — no such invoice exists.\n"],
            ),
            new DemoScenario(
                key: 'delay',
                subject: 'Your train on the 14th is delayed',
                fromName: 'SBB Kundeninfo',
                fromAddress: 'info@sbb.example',
                bodyText: "The 07:19 will depart about 20 minutes late on the 14th because of "
                    . "engineering work near Olten.\n\nYour seat reservation still stands. No action needed.",
                label: 'Travel',
            ),
            new DemoScenario(
                key: 'newsletter',
                subject: 'Six things worth reading this month',
                fromName: 'The Long Field',
                fromAddress: 'post@longfield.example',
                bodyText: "This month: a piece on hedgerows, two on bread, and one that is mostly "
                    . "photographs of doors.\n\nUnsubscribe at the bottom, as ever.",
                bodyHtml: '<div style="font-family:Georgia,serif;max-width:520px">'
                    . '<h1 style="font-size:20px;margin:0 0 4px">The Long Field</h1>'
                    . '<p style="color:#666;margin:0 0 16px">Six things worth reading this month</p>'
                    . '<ol style="line-height:1.7"><li>The hedge that outlived the farm</li>'
                    . '<li>Bread, part one: the flour</li><li>Bread, part two: the wait</li>'
                    . '<li>What the doors of Lisbon are painted with</li>'
                    . '<li>An argument for the short walk</li><li>Letters, and one correction</li></ol>'
                    . '<p style="color:#999;font-size:12px">You are reading a demo. '
                    . 'Nobody is subscribed to anything.</p></div>',
            ),
            new DemoScenario(
                key: 'family',
                subject: 'Re: Sunday',
                fromName: 'Mum',
                fromAddress: 'ruth@example.org',
                bodyText: "Half past twelve then, and your father has been told twice.\n\n"
                    . "Bring the big dish back if you still have it. No hurry if not.\n\nMum",
                label: 'Family',
            ),
            new DemoScenario(
                key: 'meter',
                subject: 'We have your meter reading',
                fromName: 'Stadtwerke',
                fromAddress: 'ablesung@stadtwerke.example',
                bodyText: "Thank you — the reading is recorded and this quarter will be billed on "
                    . "it rather than an estimate.\n\nThe next one is due in January.",
            ),
        ];
    }

    /**
     * The next scenario for this visitor, and the cursor to store once it has
     * actually been delivered.
     *
     * Returns the pair rather than advancing here, because "which is next" and
     * "one more has arrived" are different facts and the second is only true
     * after the delivery succeeded. Advancing eagerly would silently skip a
     * scenario whenever a delivery failed.
     *
     * @return array{DemoScenario, int}
     */
    public function next(User $user): array
    {
        $scenarios = $this->all();
        $cursor    = $user->getSetting(self::SETTING_CURSOR, 0);
        $index     = is_int($cursor) ? $cursor % count($scenarios) : 0;

        return [$scenarios[$index], $index + 1];
    }
}
