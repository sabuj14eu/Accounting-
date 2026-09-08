# overlay/

Files copied verbatim into the installed Liberu foundation by
`bin/install-foundation.sh` (after `composer install`, before
`package:discover`). They exist only because Filament panels discover pages
from the application's own directories; each file is a one-line subclass of a
class that lives in `modules/poland`.

Nothing here is edited by hand on a server. Re-running the installer
re-copies it.
