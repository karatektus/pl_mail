**A mail server that closes an IDLE session early no longer ends the IDLE process after a few hours.**
A server that hangs up a little before plMail's own 29-minute re-issue (FranklyMail does) made the
closing `DONE` fail, which counted as a failed connection that never reset. Ten of them in a row,
about five hours, stopped the process with exit code 1 and an ERROR in the log. That end is now
treated as the ordinary reconnect it is. No schema change, by @phdelodder.
