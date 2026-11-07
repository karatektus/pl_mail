**Profile pictures no longer slow down every page load.** An uploaded photo, or one picked from a
connected account, was stored and served at full size, and it was never cached: a phone photo of
1.7 MB was downloaded again on every page load. Pictures are now scaled to at most 256 px, turned
the right way up and stored as WebP, with the photo's metadata (such as its location) removed.
Pictures stored before get a small copy the first time they are shown. The browser now keeps the
picture instead of asking for it again.
