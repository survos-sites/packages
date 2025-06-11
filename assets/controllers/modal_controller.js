import { Controller } from '@hotwired/stimulus';
import {prettyPrintJson} from 'pretty-print-json';
import 'pretty-print-json/dist/css/pretty-print-json.min.css';

// this break the simple dropdown
// import { Modal } from 'bootstrap';
/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://symfony.com/bundles/StimulusBundle/current/index.html#lazy-stimulus-controllers
*/

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['modal', 'body', 'title']
    static values = {
        globalsJson: {
            type: String,
            default: '{}'
        },
    }

    initialize() {
        console.log('hello from ' + this.identifier);
        this.globals = JSON.parse(this.globalsJsonValue);
        console.log(this.globals);
        // Called once when the controller is first instantiated (per element)

        // Here you can initialize variables, create scoped callables for event
        // listeners, instantiate external libraries, etc.
        // this._fooBar = this.fooBar.bind(this)
    }

    connect() {
        // Called every time the controller is connected to the DOM
        // (on page load, when it's added to the DOM, moved in the DOM, etc.)

        // Here you can add event listeners on the element or target elements,
        // add or remove classes, attributes, dispatch custom events, etc.
        // this.fooTarget.addEventListener('click', this._fooBar)
    }

    // Add custom controller actions here
    // fooBar() { this.fooTarget.classList.toggle(this.bazClass) }

    disconnect() {
        // Called anytime its element is disconnected from the DOM
        // (on page change, when it's removed from or moved in the DOM, etc.)

        // Here you should remove all event listeners added in "connect()"
        // this.fooTarget.removeEventListener('click', this._fooBar)
    }

    openModal(e) {
        const modal = new Modal(this.modalTarget);
        modal.show();
    }

    debug(e) {
        console.log(e.target.dataset.hitId);
        // console.log(e.target.dataset.hitJson);
        const html = prettyPrintJson.toHtml(JSON.parse(e.target.dataset.hitJson));
        // this.modalTarget.innerHTML = '<pre>' + html + '</pre>';
        this.bodyTarget.innerHTML = '<pre>' + html + '</pre>';
        this.openModal(e);
    }
}
