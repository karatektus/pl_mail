import { test, expect } from "@playwright/test";
import {
    DIRECTION_LOCK_PX,
    HOLD_MS,
    HOLD_SLOP_PX,
    Intent,
    claimSequence,
    createSequence,
    registerSurface,
    releaseSequence,
    unregisterSurface,
} from "../../assets/touch_gesture.js";

/**
 * The arbiter's decisions, on their own.
 *
 * No browser: the module is pure decisions plus two pieces of module-level state
 * (the open surface, and the sequence's claim), and this file imports it directly,
 * so a failure names the row rather than a screenshot. The browser specs prove
 * the controllers ask it; this proves what it answers.
 *
 * Every expected value below is derived from the three constants, never fitted to
 * what the module returns:
 *   - DIRECTION_LOCK_PX = 10: a sequence is decided once max(|dx|, |dy|) >= 10.
 *     Ties (|dx| == |dy|) count as vertical.
 *   - HOLD_MS = 450: a hold needs elapsed >= 450 with no movement past HOLD_SLOP_PX.
 *   - HOLD_SLOP_PX = 8: movement is "past 8px" when max(|dx|, |dy|) > 8, so 8 is not
 *     past it. A hold cancelled by movement stays cancelled for the sequence.
 * Rows that are not in the plan's own table are marked "added" with the reason.
 */

const { UNDECIDED, HOLD, VERTICAL, LEFT, RIGHT } = Intent;

test.describe("touch gesture arbiter: decisions", () => {
    test("the constants are the owner-confirmed defaults", () => {
        // Pinned so a changed constant is a deliberate edit to this file as well.
        expect(DIRECTION_LOCK_PX).toBe(10);
        expect(HOLD_MS).toBe(450);
        expect(HOLD_SLOP_PX).toBe(8);
    });

    test("(6,0,100) is undecided: 6 is under the 10px lock", () => {
        expect(createSequence().move(6, 0, 100)).toBe(UNDECIDED);
    });

    test("(11,3,100) is horizontal to the right: 11 >= 10 and |dx| > |dy|", () => {
        expect(createSequence().move(11, 3, 100)).toBe(RIGHT);
    });

    test("(3,11,100) is vertical: 11 >= 10 and |dy| > |dx|", () => {
        expect(createSequence().move(3, 11, 100)).toBe(VERTICAL);
    });

    test("(-11,2,100) is horizontal to the left: the sign of dx decides the side", () => {
        expect(createSequence().move(-11, 2, 100)).toBe(LEFT);
    });

    test("(8,8,100) is undecided: max is 8, under the 10px lock (Euclidean would be 11.3)", () => {
        expect(createSequence().move(8, 8, 100)).toBe(UNDECIDED);
    });

    test("(10,10,100) is vertical: the tie rule, and 10 >= 10 locks", () => {
        expect(createSequence().move(10, 10, 100)).toBe(VERTICAL);
    });

    test("(0,0,500) is a hold: 500 >= 450 with no movement", () => {
        expect(createSequence().move(0, 0, 500)).toBe(HOLD);
    });

    test("(0,0,450) is a hold: the boundary, 450 >= 450", () => {
        expect(createSequence().move(0, 0, 450)).toBe(HOLD);
    });

    test("(0,0,449) is undecided: one millisecond short of the hold", () => {
        expect(createSequence().move(0, 0, 449)).toBe(UNDECIDED);
    });

    test("(7,0,500) is a hold: 7 <= 8, so still within the slop", () => {
        expect(createSequence().move(7, 0, 500)).toBe(HOLD);
    });

    test("(8,0,500) is a hold: added, the slop boundary, 8 is not past 8", () => {
        expect(createSequence().move(8, 0, 500)).toBe(HOLD);
    });

    test("(6,6,500) is a hold: added, max is 6 and Euclidean would be 8.5, which is past 8", () => {
        expect(createSequence().move(6, 6, 500)).toBe(HOLD);
    });

    test("(9,0,500) is not a hold and still undecided: 8 < 9 < 10, the band", () => {
        expect(createSequence().move(9, 0, 500)).toBe(UNDECIDED);
    });

    test("(9,0,200) then (9,0,500): the hold is cancelled for the rest of the sequence", () => {
        const sequence = createSequence();

        expect(sequence.move(9, 0, 200)).toBe(UNDECIDED);
        expect(sequence.move(9, 0, 500)).toBe(UNDECIDED);
    });

    test("a decided hold stays a hold: a later (20,0) is not a late swipe", () => {
        const sequence = createSequence();

        expect(sequence.move(0, 0, 500)).toBe(HOLD);
        expect(sequence.move(20, 0, 600)).toBe(HOLD);
    });

    test("a decided vertical stays vertical: a later horizontal move does not change it", () => {
        const sequence = createSequence();

        expect(sequence.move(0, 11, 100)).toBe(VERTICAL);
        expect(sequence.move(30, 0, 200)).toBe(VERTICAL);
    });
});

test.describe("touch gesture arbiter: claims and open surfaces", () => {
    test.afterEach(() => {
        releaseSequence("pull");
        releaseSequence("swipe");
        unregisterSurface("test-surface");
    });

    test("a claimed sequence rejects a second claimant; releasing it lets the next one claim", () => {
        expect(claimSequence("pull")).toBe(true);
        expect(claimSequence("swipe")).toBe(false);

        expect(releaseSequence("pull")).toBe(true);

        // Presence companion: the rejection above is only meaningful if a released
        // sequence is claimable again.
        expect(claimSequence("swipe")).toBe(true);
    });

    test("a release by someone who does not hold the claim releases nothing", () => {
        expect(claimSequence("pull")).toBe(true);

        // A touchend from a sequence that never claimed must not free another consumer's claim.
        expect(releaseSequence("swipe")).toBe(false);
        expect(claimSequence("swipe")).toBe(false);

        // Presence companion: the owner's own release does free it.
        expect(releaseSequence("pull")).toBe(true);
        expect(claimSequence("swipe")).toBe(true);
    });

    test("an open surface blocks a pull claim; closing it lets the pull claim", () => {
        let open = true;

        registerSurface("test-surface", () => open);

        expect(claimSequence("pull")).toBe(false);

        // Presence companion: the same claim succeeds once the surface reports closed.
        open = false;

        expect(claimSequence("pull")).toBe(true);
    });
});
