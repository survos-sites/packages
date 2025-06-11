import {Controller} from '@hotwired/stimulus';


import Routing from "fos-routing";
import RoutingData from "/js/fos_js_routes.js";
import {prettyPrintJson} from 'pretty-print-json';
import Twig from 'twig';
import instantsearch from 'instantsearch.js'
import {instantMeiliSearch} from '@meilisearch/instant-meilisearch';
import {hits, pagination, refinementList, searchBox} from 'instantsearch.js/es/widgets'
import {stimulus_action, stimulus_controller, stimulus_target,} from "stimulus-attributes";

import 'pretty-print-json/dist/css/pretty-print-json.min.css';
// this import makes the pretty-json really ugly
// import '@meilisearch/instant-meilisearch/templates/basic_search.css';
import 'instantsearch.css/themes/algolia.min.css';

Routing.setData(RoutingData);

Twig.extend(function (Twig) {
    // Twig.setFilter('json_pretty', function(data, options={}) {
    //     return prettyPrintJson.toHtml(data, options);
    // });

    Twig._function.extend("json_pretty", (data, options={}) => {
        const html = prettyPrintJson.toHtml(data, options);
        return html;
    })

    Twig._function.extend("path", (route, routeParams = {}) => {
        // console.error(routeParams);
        if ("_keys" in routeParams) {
            // if(routeParams.hasOwnProperty('_keys')){
            delete routeParams._keys; // seems to be added by twigjs
        }
        let path = Routing.generate(route, routeParams);
        return path;
    });

    Twig._function.extend(
        "stimulus_controller",
        (
            controllerName,
            controllerValues = {},
            controllerClasses = {},
            controllerOutlets = ({} = {})
        ) =>
            stimulus_controller(
                controllerName,
                controllerValues,
                controllerClasses,
                controllerOutlets
            )
    );
    Twig._function.extend("stimulus_target", (controllerName, r = null) =>
        stimulus_target(controllerName, r)
    );
    Twig._function.extend(
        "stimulus_action",
        (controllerName, r, n = null, a = {}) =>
            stimulus_action(controllerName, r, n, a)
    );

});

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
        indexName: String,
        templateUrl: String,
        globalsJson: {
                type: String,
                default: '{}'
            },
    }

    initialize() {
        // Called once when the controller is first instantiated (per element)

        // Here you can initialize variables, create scoped callables for event
        // listeners, instantiate external libraries, etc.
        // this._fooBar = this.fooBar.bind(this)
        this.globals = JSON.parse(this.globalsJsonValue);
        console.log(this.globals);


    }

    connect() {
        const self = this; // or use: const that = this;

        // Called every time the controller is connected to the DOM
        // (on page load, when it's added to the DOM, moved in the DOM, etc.)

        // Here you can add event listeners on the element or target elements,
        // add or remove classes, attributes, dispatch custom events, etc.
        // this.fooTarget.addEventListener('click', this._fooBar)

        console.log(this.serverUrlValue);
        console.log(this.templateUrlValue);
        this.fetchFile().then(() => {
                try {
                    this.search();
                } catch (e) {
                    this.hitsTarget.innerHTML = "URL: " + this.serverUrlValue + " " + e.message;
                }
        }
        )

    }

    search() {
        const { searchClient, setMeiliSearchParams } = instantMeiliSearch(
        // const {searchClient} = instantMeiliSearch(
            this.serverUrlValue,
            this.serverApiKeyValue,
            {
                // placeholderSearch: false, // default: true.
                // primaryKey: 'id', // default: undefined
                keepZeroFacets: true,
                showRankingScore: true,
                showRankingScoreDetails: true
            }
        );
        setMeiliSearchParams({
            showRankingScore: true,
            showRankingScoreDetails: true
        });
        const search = instantsearch({
            indexName: this.indexNameValue,
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
                        if (hit.__position === 1)
                        {
                            console.log(hit);
                        }
                        return this.template.render({
                            hit: hit,
                            globals: this.globals
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
                    limit: 5,
                    showMoreLimit: 10,
                    showMore: true,
                    searchable: true,
                    attribute: attribute,
                    templates: {
                        showMoreText(data, { html }) {
                          return html`<span class="btn btn-sm btn-primary">${data.isShowingMore ? 'Show less' : 'Show more'}</span>`;
                        },
                      },
                    }
                )]);
            // console.log(`Found div with data-attribute="${attribute}"`, div);
            // console.log(x);

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

    async fetchFile() {
        try {
            const response = await fetch(this.templateUrlValue)
            if (!response.ok) {
                throw new Error(`HTTP ${response.status} – ${response.statusText}`)
            }

            // Decide how to read it (JSON vs. text)
            const contentType = response.headers.get("content-type") || ""
            let data
            if (contentType.includes("application/json")) {
                data = await response.json()
            } else {
                data = await response.text()
            }
            this.template = Twig.twig({data: data});

        } catch (error) {
            console.error("File fetch failed:", error)
            if (this.hasOutputTarget) {
                this.outputTarget.textContent = `Error loading file: ${error.message}`
            }
        }
    }
}
