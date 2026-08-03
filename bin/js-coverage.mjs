#!/usr/bin/env node

/**
 * Aggregates the raw V8 coverage the e2e suite leaves in var/js-coverage.
 *
 * Playwright reports coverage per page, as byte ranges over each script it
 * loaded. That is per test, and a controller exercised by three specs is not
 * three separate coverages of it — so the ranges are unioned per script first
 * and only then turned into a percentage.
 *
 * Only this application's own code is counted. Stimulus, Turbo and Tom Select
 * are in the same importmap and would otherwise dominate the number with code
 * nobody here can write a test for.
 *
 *   E2E_JS_COVERAGE=1 npm run test:e2e:docker
 *   node bin/js-coverage.mjs
 */

import { readdirSync, readFileSync, existsSync } from "node:fs";

const DIR = "var/js-coverage";

/** Compiled asset paths are hashed; the source file is what anyone cares about. */
const OURS = /\/assets\/(controllers|app|bootstrap|styles)/;

if (false === existsSync(DIR)) {
    console.error(`No coverage in ${DIR}. Run: E2E_JS_COVERAGE=1 npm run test:e2e:docker`);
    process.exit(1);
}

/** Overlapping ranges merged, since V8 nests them and summing double-counts. */
const merge = (ranges) => {
    if (0 === ranges.length) {
        return [];
    }

    const sorted = [...ranges].sort((a, b) => a[0] - b[0]);
    const merged = [sorted[0].slice()];

    for (const [start, end] of sorted.slice(1)) {
        const last = merged[merged.length - 1];

        if (start > last[1]) {
            merged.push([start, end]);

            continue;
        }

        last[1] = Math.max(last[1], end);
    }

    return merged;
};

const length = (ranges) => ranges.reduce((sum, [start, end]) => sum + (end - start), 0);

/** What is left of `ranges` once every span in `holes` is taken out of it. */
const subtract = (ranges, holes) => {
    const out = [];

    for (const [start, end] of ranges) {
        let cursor = start;

        for (const [holeStart, holeEnd] of holes) {
            if (holeEnd <= cursor || holeStart >= end) {
                continue;
            }

            if (holeStart > cursor) {
                out.push([cursor, holeStart]);
            }

            cursor = Math.max(cursor, holeEnd);
        }

        if (cursor < end) {
            out.push([cursor, end]);
        }
    }

    return out;
};

/** url => { total, ranges: [start, end][] } */
const scripts = new Map();

for (const file of readdirSync(DIR).filter((name) => name.endsWith(".json"))) {
    for (const entry of JSON.parse(readFileSync(`${DIR}/${file}`, "utf8"))) {
        if (false === OURS.test(entry.url)) {
            continue;
        }

        const key = entry.url.replace(/^https?:\/\/[^/]+/, "").replace(/-[A-Za-z0-9_-]{7,}\.js$/, ".js");
        const script = scripts.get(key) ?? { total: entry.source?.length ?? 0, covered: [] };

        script.total = Math.max(script.total, entry.source?.length ?? 0);

        // V8 nests: a function that ran reports one range over its whole body
        // with count > 0, and the branches inside it that did not run as
        // count === 0 ranges *within* that span. Collecting only the positive
        // ones therefore marks every loaded module fully covered, since
        // importing it executes its top level.
        //
        // Resolved per entry, before anything is combined. Pooling the dead
        // ranges across tests and subtracting them at the end reads "this
        // block did not run in test A" as "this block never runs", so a branch
        // one spec covers is cancelled by every spec that merely loaded the
        // module — which every page does, since the importmap preloads them
        // all. That silently pinned the number: three new specs moved it by
        // nothing at all.
        const ran = [];
        const dead = [];

        for (const fn of entry.functions ?? []) {
            for (const range of fn.ranges) {
                (range.count > 0 ? ran : dead).push([range.startOffset, range.endOffset]);
            }
        }

        script.covered.push(...subtract(merge(ran), merge(dead)));

        scripts.set(key, script);
    }
}

const rows = [...scripts.entries()]
    .map(([url, { total, covered: ranges }]) => {
        // Unioned across tests only now: a line is covered if ANY test ran it.
        const covered = Math.min(length(merge(ranges)), total);

        return { url, total, covered, pct: total > 0 ? (covered / total) * 100 : 0 };
    })
    .sort((a, b) => a.pct - b.pct);

const total = rows.reduce((sum, row) => sum + row.total, 0);
const covered = rows.reduce((sum, row) => sum + row.covered, 0);

const name = (url) => url.replace("/assets/", "").replace(/\.js$/, "");

console.log("JavaScript coverage — application code exercised by the e2e suite\n");
console.log("   %  covered/total  file");

for (const row of rows) {
    console.log(
        `${row.pct.toFixed(1).padStart(5)}  ${String(row.covered).padStart(6)}/${String(row.total).padEnd(6)} ${name(row.url)}`,
    );
}

console.log(
    `\n${((covered / total) * 100).toFixed(1)}% of ${rows.length} application scripts (${covered}/${total} bytes)`,
);
