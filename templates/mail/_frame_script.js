(function () {
    "use strict";
    // This script is the ONLY script that can run in here. `script-src
    // 'nonce-…'` in the CSP above means markup arriving in an email cannot
    // execute even if it somehow survived the sanitizer — it has no nonce, and
    // it cannot read this one, because reading it would require running.
    var send = function (message) { parent.postMessage(message, "*"); };

    var lastHeight = -1;
    var reportHeight = function (force) {
        var height = Math.max(
            document.body.scrollHeight,
            document.documentElement.scrollHeight
        );
        if (force || height !== lastHeight) { lastHeight = height; send({ plmail: "height", height: height }); }
    };

    window.addEventListener("load", reportHeight);
    document.addEventListener("DOMContentLoaded", reportHeight);
    // Images settle after layout, and a mail is mostly images.
    window.addEventListener("resize", reportHeight);
    if (window.ResizeObserver) { new ResizeObserver(reportHeight).observe(document.documentElement); }

    // Link preview. Detected in here because this is where the links are;
    // DRAWN by the parent, because a status bar the message could paint over
    // is a status bar that can lie about where a link goes.
    document.addEventListener("mouseover", function (event) {
        var anchor = event.target && event.target.closest ? event.target.closest("a[href]") : null;
        if (anchor) { send({ plmail: "link", href: anchor.href }); }
    });
    document.addEventListener("mouseout", function (event) {
        var anchor = event.target && event.target.closest ? event.target.closest("a[href]") : null;
        if (anchor) { send({ plmail: "link", href: null }); }
    });

    // "Show quoted text". The wrapper and its toggle are server-rendered by
    // QuoteCollapser (a [data-plmail-quote] hidden by default, its button the
    // immediately-preceding sibling). Flipping [hidden] here — inside the frame,
    // under this nonce — keeps the toggle within the one script that may run:
    // the button is inert markup an email could forge, but forging it only
    // reveals a quote, and the height is re-reported forcibly so the frame grows
    // to fit what was just shown.
    document.addEventListener("click", function (event) {
        var toggle = event.target && event.target.closest
            ? event.target.closest("[data-plmail-quote-toggle]") : null;
        if (!toggle) { return; }

        var quote = toggle.nextElementSibling;
        if (!quote || !quote.hasAttribute("data-plmail-quote")) {
            quote = document.querySelector("[data-plmail-quote]");
        }
        if (!quote) { return; }

        var nowHidden = !quote.hasAttribute("hidden");
        if (nowHidden) { quote.setAttribute("hidden", ""); } else { quote.removeAttribute("hidden"); }

        toggle.setAttribute("aria-expanded", nowHidden ? "false" : "true");
        var label = nowHidden
            ? toggle.getAttribute("data-label-show")
            : toggle.getAttribute("data-label-hide");
        if (label) { toggle.setAttribute("aria-label", label); toggle.setAttribute("title", label); }

        reportHeight(true);
    });

    window.addEventListener("message", function (event) {
        // The parent is the only window that can reach us.
        if (event.source !== parent || !event.data || typeof event.data !== "object") { return; }

        // A message from the parent is proof its listener is live NOW. This
        // frame's first height report is parsed and sent synchronously, before
        // the parent's Stimulus controller connects — on a full page load the
        // app's module script is deferred — so that first report lands with no
        // listener and is dropped, and the dedupe above then never re-sends the
        // unchanged height. The frame stays at its 80px minimum with the whole
        // message scrolling inside it. The parent asks for a measurement once
        // it is listening; answer it unconditionally.
        if (event.data.plmail === "measure") { reportHeight(true); }

        if (event.data.plmail === "show-images") {
            document.querySelectorAll("img[data-plmail-src]").forEach(function (img) {
                // The stored value is already a proxy URL on our own origin —
                // built server-side. There is no path from here back to the
                // sender's host, whatever this code does.
                img.src = img.getAttribute("data-plmail-src");
                img.removeAttribute("data-plmail-src");
                img.removeAttribute("data-plmail-blocked");
                // The shaped placeholder has done its job; the real image
                // brings its own ratio. Left behind, this attribute is inert —
                // every rule that reads it is scoped to data-plmail-blocked,
                // which has just gone — but a stale claim about a box is the
                // kind of thing that is true until somebody writes one rule.
                img.removeAttribute("data-plmail-box");
            });
            document.querySelectorAll("[data-plmail-style]").forEach(function (element) {
                element.setAttribute("style", element.getAttribute("data-plmail-style"));
                element.removeAttribute("data-plmail-style");
                element.removeAttribute("data-plmail-blocked");
            });
            reportHeight();
        }

        if (event.data.plmail === "theme" && event.data.vars) {
            var vars = event.data.vars;
            document.body.style.background = vars.sheet || "";
            document.body.style.color = vars.ink || "";
            var rule = document.createElement("style");
            rule.textContent = "a{color:" + (vars.link || "#1d4ed8").replace(/[^a-zA-Z0-9(),.%# ]/g, "") + "}";
            document.head.appendChild(rule);
        }
    });

    reportHeight();
})();
