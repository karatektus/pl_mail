**A Gmail import no longer stops for good on one attachment with a long name (#674).** An
attachment name longer than 255 characters was refused by the database, and the 49 other messages
fetched with it were lost too. The same batch was then fetched again every hour, so the import
stayed one batch short of finished. Attachment names, types and content IDs now have no length
limit. A long name that Gmail passes on in pieces is joined back into one name. If the database
refuses a batch for any other reason, its messages are retried one at a time. A message that is
still refused on its own is listed by `app:gmail:skipped`, and the rest are stored.
