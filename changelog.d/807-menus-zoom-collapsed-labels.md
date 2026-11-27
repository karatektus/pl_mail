**A message's ⋮ menu closes when you tap or click anywhere else (#807).** A click inside the
message itself left the menu open, because the message is drawn in a frame of its own. On an
iPhone, tapping the page next to the menu didn't close it either. Both now close it, and so does
Escape. The same goes for the other small menus in the app.

**The web app on an iPhone no longer zooms in when you tap a text field (#807).** iOS zooms the
whole page into any field with text smaller than 16px and doesn't zoom back out. On iPhones and
iPads, text fields now use 16px text. Pinch-to-zoom still works.

**A collapsed parent label shows the unread mail in the labels under it (#807).** With the tree
collapsed, the number counts the label and every label nested under it, each conversation once.
With the tree open, each label shows its own number as before.

**Enter in the compose editor starts one new line, without a blank line above it (#807).** Enter
used to start a new paragraph. Mail clients put a blank line's worth of space around every
paragraph, so each Enter showed up as a gap, in the editor and in the sent mail. Enter now starts a
plain line, the way Gmail and Outlook do. Paragraphs you paste, and those in templates and
signatures, keep their spacing.
