import { startStimulusApp } from '@symfony/stimulus-bundle';
import Sortable from '@stimulus-components/sortable';

const app = startStimulusApp();
app.register('sortable', Sortable);
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
