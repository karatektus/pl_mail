import { startStimulusApp } from '@symfony/stimulus-bundle';
import Sortable from '@stimulus-components/sortable';

const app = startStimulusApp();

// startStimulusApp() sets app.debug from a flag the Stimulus bundle bakes into
// the compiled asset at BUILD time. An asset compiled in a debug context keeps
// that flag on even once it is serving a production runtime, which is how ~1000
// Stimulus "lazy:loading"/"lazy:loaded" console lines ended up shipping to prod.
// The <html data-app-debug> attribute carries the debug flag of the kernel that
// actually rendered THIS request, so it is the runtime truth: false in prod,
// true in dev. Correct the baked-in value from it — dev keeps its debug output,
// prod goes quiet.
app.debug = document.documentElement.dataset.appDebug === 'true';

app.register('sortable', Sortable);
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
