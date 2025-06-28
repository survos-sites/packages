import {startStimulusApp} from '@symfony/stimulus-bundle';
import Dialog from '@stimulus-components/dialog'
import RevealController from '@stimulus-components/reveal'

const app = startStimulusApp();
// register any custom, 3rd party controllers here

app.register('dialog', Dialog)
app.register('reveal', RevealController)
app.debug = false;


