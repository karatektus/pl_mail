<!--
Thanks for the pull request. CONTRIBUTING.md, "How work gets in", explains
the flow; the short version is below.
-->

Closes #

<!--
A feature needs an issue labelled `accepted` before it is built. A bug fix may
come without one if this description says what the bug was.
-->

## What changes

## How it was checked

## Checklist

- [ ] Linked to an issue above, or describes the bug it fixes
- [ ] Tests for the change (PHPUnit, and a browser spec for anything a browser has to prove)
- [ ] PHPStan and the suites pass (`npm run test:unit:docker`, `npm run test:e2e:docker`)
- [ ] New or changed copy is in all five catalogues: `messages.en`, `.de`, `.fr`, `.zh_CN`, `.en_PI`
- [ ] Handbook updated if behaviour changed, with the German page and its `translated-from` hash
- [ ] A changelog entry in `changelog.d/<issue>-<words>.md` for anything a user would notice (not in CHANGELOG.md itself; see `changelog.d/README.md`)
- [ ] A setting that changes how plMail behaves for everybody is off by default, or says why not
