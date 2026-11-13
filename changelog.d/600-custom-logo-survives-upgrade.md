**A logo of your own survives an upgrade (#600).** On a Docker Compose install the uploaded logo was
kept inside the container rather than on a volume, so the next upgrade recreated the container and
the logo went back to the motif underneath. It is now kept with the other uploads, which every
install keeps. A logo already lost this way has to be uploaded once more; one that is still there
is moved across on its own.
