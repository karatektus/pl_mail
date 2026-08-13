import { startStimulusApp } from '@symfony/stimulus-bundle';

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

// No global 'sortable' controller any more. @stimulus-components/sortable was
// registered here and driven the accounts list; it builds its own request body
// and gives you nowhere to attach a CSRF token, which is precisely why the
// account reorder endpoint went without one. Both draggable lists — rules and
// accounts — now wrap sortablejs in a controller of their own that posts the
// whole order as JSON with a token, so the wrapper is gone rather than left
// registered for the next list to reach for.
//
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
