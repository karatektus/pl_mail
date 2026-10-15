/**
 * Types for assets/touch_gesture.js, so the specs that import it are checked.
 *
 * A declaration file rather than `allowJs`, for the reason pdf_geometry.d.ts
 * gives: the controllers are plain JavaScript and are exercised in the browser.
 */

/** The decision a sequence has reached. */
export type IntentValue = "undecided" | "hold" | "vertical" | "left" | "right";

export const DIRECTION_LOCK_PX: number;
export const HOLD_MS: number;
export const HOLD_SLOP_PX: number;

export const Intent: {
    readonly UNDECIDED: "undecided";
    readonly HOLD: "hold";
    readonly VERTICAL: "vertical";
    readonly LEFT: "left";
    readonly RIGHT: "right";
};

export interface Sequence {
    move(dx: number, dy: number, elapsedMs: number): IntentValue;
}

export function createSequence(): Sequence;
export function registerSurface(name: string, isOpen: () => boolean): void;
export function unregisterSurface(name: string): void;
export function openSurface(): string | null;
export function claimSequence(kind: string): boolean;
export function releaseSequence(kind: string): boolean;
