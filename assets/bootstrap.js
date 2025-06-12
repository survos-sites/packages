import { startStimulusApp } from '@symfony/stimulus-bundle';
import Dialog from '@stimulus-components/dialog'

const app = startStimulusApp();
// register any custom, 3rd party controllers here

app.register('dialog', Dialog)
app.debug = false;
