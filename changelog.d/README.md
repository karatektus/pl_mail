# Changelog entries waiting for a release

A pull request that changes something a user would notice adds its CHANGELOG entry here, as a file
of its own, instead of editing `CHANGELOG.md`. The `Release vX.Y.Z` pull request collects them
into the release's section with `bin/changelog-release.sh` and deletes them (see
[CONTRIBUTING.md → Releasing](../CONTRIBUTING.md#releasing)).

Why: every pull request used to add its paragraph at the same place in `CHANGELOG.md`, so every
second pull request of a batch conflicted with the first, and each fix of that conflict re-ran the
whole suite. Files of their own never conflict.

## The file

- **Named after the issue, then a few words:** `266-jmap-read-state.md`. The number keeps the
  entries of a release in roughly the order they were asked for; the words keep two pull requests
  for one issue apart.
- **The entry, written as it would be in `CHANGELOG.md`:** a paragraph that opens with a bold
  sentence, wrapped at a hundred columns. More than one entry in a file is fine. A highlight is
  marked by `<!-- highlight -->` or `<!-- highlight screenshot=name -->` on the line above it, and a
  `**Migration:**` paragraph is for administrators, both as described in
  [CONTRIBUTING.md → Release notes](../CONTRIBUTING.md#release-notes).
- **No headings.** The files are put one after the other, and a `### ` heading in one would claim
  every entry after it — under `### Before you upgrade`, hiding them from everybody but
  administrators. A release that needs a `### Before you upgrade` section writes it under
  `## Unreleased` in `CHANGELOG.md`, which the script puts after the fragments.

```markdown
**A message marked read or unread in the phone app is shown that way on the web too.** Marking a
message read or unread over JMAP changed the message but not its conversation's unread count …
No schema change. [#266](https://github.com/karatektus/pl_mail/issues/266), by @phdelodder.
```

`ChangelogReaderTest` reads these files too: it fails on a heading in one, on an entry it cannot
read, and on a screenshot a highlight names that is not in `public/whats-new/`.

Entries here are not shown in **What's new** until they are released: the dialog reads tagged
releases only (see
[What everyone sees after an update](../docs/install/upgrading.md#what-everyone-sees-after-an-update)).
