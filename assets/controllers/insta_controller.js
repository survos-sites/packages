import {Controller} from '@hotwired/stimulus';


import Routing from "fos-routing";
import RoutingData from "/js/fos_js_routes.js";

Routing.setData(RoutingData);

import Twig from 'twig';

Twig.extend(function (Twig) {
    Twig._function.extend("path", (route, routeParams = {}) => {
        // console.error(routeParams);
        if ("_keys" in routeParams) {
            // if(routeParams.hasOwnProperty('_keys')){
            delete routeParams._keys; // seems to be added by twigjs
        }
        let path = Routing.generate(route, routeParams);
        return path;
    });
});

import instantsearch from 'instantsearch.js'
import {instantMeiliSearch} from '@meilisearch/instant-meilisearch';
import {searchBox, hits, pagination, refinementList} from 'instantsearch.js/es/widgets'

import '@meilisearch/instant-meilisearch/templates/basic_search.css';

/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://symfony.com/bundles/StimulusBundle/current/index.html#lazy-stimulus-controllers
*/

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['searchBox', 'hits', 'template', 'pagination', 'refinementList', 'marking']
    static values = {
        serverUrl: String,
        serverApiKey: String,
    }

    initialize() {
        // Called once when the controller is first instantiated (per element)

        // Here you can initialize variables, create scoped callables for event
        // listeners, instantiate external libraries, etc.
        // this._fooBar = this.fooBar.bind(this)


    }

    connect() {
        const self = this; // or use: const that = this;
        this.template = Twig.twig({data: this.templateTarget.innerHTML});

        // Called every time the controller is connected to the DOM
        // (on page load, when it's added to the DOM, moved in the DOM, etc.)

        // Here you can add event listeners on the element or target elements,
        // add or remove classes, attributes, dispatch custom events, etc.
        // this.fooTarget.addEventListener('click', this._fooBar)

        console.log(this.serverUrlValue);
        try {
            this.search();
        } catch (e) {
            this.hitsTarget.innerHTML = "URL: " + this.serverUrlValue + " " + e.message;
        }

    }

    search() {
        const {searchClient} = instantMeiliSearch(
            this.serverUrlValue,
            this.serverApiKeyValue,
        );

        const search = instantsearch({
            indexName: 'packagesPackage',
            searchClient,
        })


        search.addWidgets([
            searchBox({
                container: this.searchBoxTarget,
                placeholder: 'Search...',
            }),
            hits({
                container: this.hitsTarget,
                templates: {
                    // banner: (b) => { console.log(b); return '' },
                    item: (hit, html, index) => {
                        //     <div class="hit-name">
                        //       {{#helpers.highlight}}{ "attribute": "name" }{{/helpers.highlight}}
                        //     </div>
                        return this.template.render({
                            hit: hit
                        });
                    },
                },
            }),
            pagination({
                container: this.paginationTarget
            }),
        ]);

        const attributeDivs = this.refinementListTarget.querySelectorAll('[data-attribute]')

        attributeDivs.forEach(div => {
            const attribute = div.getAttribute("data-attribute")
            let x = search.addWidgets([
                refinementList({
                    container: div,
                    attribute: attribute
                    }
                )]);
            console.log(`Found div with data-attribute="${attribute}"`, div);
            console.log(x);

            // You can now do something with each div individually
            // e.g., populate, modify, attach event listeners, etc.
        })


        // @todo: get the list of refinements.

        //   search.addWidgets([
        //       instantsearch.widgets.searchBox({
        //           container: '#searchbox',
        //       }),
        //       instantsearch.widgets.hits({
        //           container: '#hits',
        //           templates: {
        //               item: `
        //   <div>
        //     <div class="hit-name">
        //       {{#helpers.highlight}}{ "attribute": "name" }{{/helpers.highlight}}
        //     </div>
        //   </div>
        // `,
        //           },
        //       }),
        //   ])

        search.start()

    }

    // Add custom controller actions here
    // fooBar() { this.fooTarget.classList.toggle(this.bazClass) }

    disconnect() {
        // Called anytime its element is disconnected from the DOM
        // (on page change, when it's removed from or moved in the DOM, etc.)

        // Here you should remove all event listeners added in "connect()"
        // this.fooTarget.removeEventListener('click', this._fooBar)
    }
}
