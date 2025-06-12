import { Controller } from '@hotwired/stimulus';
import {prettyPrintJson} from 'pretty-print-json';
import 'pretty-print-json/dist/css/pretty-print-json.min.css';

/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://symfony.com/bundles/StimulusBundle/current/index.html#lazy-stimulus-controllers
*/

/* stimulusFetch: 'lazy' */

import Dialog from "@stimulus-components/dialog"
import { Meilisearch } from "meilisearch";

export default class extends Dialog {
    static targets = ['content', 'title']
    static values = {
        serverUrl: String,
        serverApiKey: String,

        indexName: String,
        id: String,
        data: {
            type: String,
            default: '{}'
        }
    }


    connect() {
        super.connect()
        // console.log(this.serverUrlValue, this.serverApiKeyValue);
        // this.data = JSON.parse(this.dataValue);
        // console.log(this.data);
        try {
            const client = new Meilisearch({
                host: this.serverUrlValue,
                apiKey: this.serverApiKeyValue,
            });
            this.index = client.index(this.indexNameValue);
        } catch (e) {
            console.error(this.serverUrlValue, this.serverApiKeyValue);
            console.error(e);
        }

    }


    initialize() {
        super.initialize()
        // Called once when the controller is first instantiated (per element)

        // Here you can initialize variables, create scoped callables for event
        // listeners, instantiate external libraries, etc.
        // this._fooBar = this.fooBar.bind(this)
    }


    // Add custom controller actions here
    // fooBar() { this.fooTarget.classList.toggle(this.bazClass) }

    disconnect() {
        super.disconnect();
        // Called anytime its element is disconnected from the DOM
        // (on page change, when it's removed from or moved in the DOM, etc.)

        // Here you should remove all event listeners added in "connect()"
        // this.fooTarget.removeEventListener('click', this._fooBar)
    }


    // Function to override on open.
    open() {
        // this.contentTarget.innerHTML = this.idValue;

        this.index.getDocument(this.idValue).then(
            hit => {
                // const obj = { a: null, b: 2, c: { d: null, e: 5 } };
                // this.titleTarget.innerHTML = hit.name; // @todo: generalize!

                const clean = this.cleanObject(hit);
                console.log(clean);
// → { b: 2, c: { e: 5 } }
                const html = prettyPrintJson.toHtml(clean);
                // this.modalTarget.innerHTML = '<pre>' + html + '</pre>';
                this.contentTarget.innerHTML = '<pre class="json-container">' + html + '</pre>';
                // this.contentTarget.innerHTML = html;
                // this.openModal();
                super.open();
            }
        )

    }

    // Function to override on close.
    close() {
        super.close();
    }

    // Function to override on backdropClose.
    backdropClose() {
        super.backdropClose();
    }

    cleanObject(obj) {
        Object.entries(obj).forEach(([key, value]) => {
            if (value && typeof value === 'object') {
                // Recurse into objects and arrays
                this.cleanObject(value);
            }

            const isNull = value === null;
            const isEmptyArray = Array.isArray(value) && value.length === 0;
            const isEmptyObject =
                value &&
                typeof value === 'object' &&
                !Array.isArray(value) &&
                Object.keys(value).length === 0;

            if (isNull || isEmptyArray || isEmptyObject) {
                delete obj[key];
            }
        });
        return obj;
    }

}
