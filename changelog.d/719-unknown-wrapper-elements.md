**A mail wrapped in an element plMail does not know is no longer shown blank.** The sanitizer
dropped any element missing from its allow-list together with everything inside it, and some
senders wrap their whole letter in a non-standard element such as `<message>`, so the body came
out empty. Unknown elements are now unwrapped and their content kept, as Gmail does; content that is
never meant to be read (`<xml>`, `<template>`, `<noscript>`, `<noembed>`, `<noframes>`, `<style>`,
`<title>`) is still removed. `app:backfill safe-html-unknown-elements` re-sanitizes the HTML bodies
already stored. No schema change.
[#719](https://github.com/karatektus/pl_mail/issues/719), by @james-e-morrison (written with Claude).
