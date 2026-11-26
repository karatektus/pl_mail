import type { Page } from "@playwright/test";

/**
 * Put the signed-in user in a workspace of one's choosing (#755).
 *
 * Through the endpoint the page itself saves to, with the token the shell
 * renders for its controller — so a spec that needs the calendar beside the
 * list, or the message in a pane of its own, says so in one line instead of
 * driving menus it is not about. Before #755 each spec posted to its own
 * pane's state route (the calendar switch's, the address book's, the tasks');
 * those are gone with the switches.
 *
 * The device cookie is cleared too, so a divider dragged or a pane put at full
 * width by an earlier spec in this worker does not carry over.
 *
 * Leaves the page on the Inbox, reloaded, drawn from the new workspace.
 *
 *     await useWorkspace(page, row("list", "message"));
 *     await useWorkspace(page, row("list", col("calendar", "tasks")));
 */
export async function useWorkspace(page: Page, layout: Layout): Promise<void> {
    await useWorkspaces(page, [{ name: "Mail", layout }], 0);
}

/** Several workspaces, `active` the one drawn; otherwise as useWorkspace(). */
export async function useWorkspaces(page: Page, workspaces: Array<{ name: string; layout: Layout }>, active: number): Promise<void> {
    await page.context().clearCookies({ name: "plmail_layout" });
    await page.goto("/mail/inbox");

    const status = await page.evaluate(async ({ workspaces, active }) => {
        const shell = document.querySelector("[data-controller~='layout--workspace']");

        if (null === shell) {
            return 0;
        }

        const response = await fetch(shell.getAttribute("data-layout--workspace-save-url-value") ?? "", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": shell.getAttribute("data-layout--workspace-token-value") ?? "",
                "X-Requested-With": "fetch",
            },
            body: JSON.stringify({ workspaces: workspaces.map((workspace, index) => ({ id: `e2e${Date.now()}${index}`, ...workspace })), active }),
        });

        return response.status;
    }, { workspaces, active });

    if (200 !== status) {
        throw new Error(`saving the workspace answered ${status}`);
    }

    await page.goto("/mail/inbox");
}

export type Layout =
    | { t: "pane"; kind: Kind; id?: string }
    | { t: "split"; id: string; dir: "row" | "col"; sizes: number[]; children: Layout[] };

export type Kind = "list" | "message" | "calendar" | "contacts" | "tasks";

let splits = 0;

function node(part: Kind | Layout): Layout {
    return "string" === typeof part ? { t: "pane", kind: part } : part;
}

/** Panes side by side, in equal shares. */
export function row(...parts: Array<Kind | Layout>): Layout {
    return { t: "split", id: `s${++splits}`, dir: "row", sizes: parts.map(() => 1), children: parts.map(node) };
}

/** Panes above and below each other, in equal shares. */
export function col(...parts: Array<Kind | Layout>): Layout {
    return { t: "split", id: `s${++splits}`, dir: "col", sizes: parts.map(() => 1), children: parts.map(node) };
}

/** A workspace of one pane. */
/** A calendar after the first: a workspace can show several, each with an id of its own. */
export function calendar(id: string): Layout {
    return { t: "pane", kind: "calendar", id };
}

export function only(kind: Kind): Layout {
    return { t: "pane", kind };
}

/** A pane, found by what it shows. */
/** A pane by its key: its kind, or `calendar~<id>` for a further calendar. */
export const pane = (page: Page, kind: Kind | `calendar~${string}`) => page.locator(`[data-layout-pane="${kind}"]`);
