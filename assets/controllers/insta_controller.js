import {Controller} from '@hotwired/stimulus';


import Routing from "fos-routing";
import RoutingData from "/js/fos_js_routes.js";
import {prettyPrintJson} from 'pretty-print-json';
import Twig from 'twig';
import instantsearch from 'instantsearch.js'
import {instantMeiliSearch} from '@meilisearch/instant-meilisearch';
import {hits, pagination, refinementList, rangeSlider, rangeInput, searchBox} from 'instantsearch.js/es/widgets'
import {stimulus_action, stimulus_controller, stimulus_target,} from "stimulus-attributes";
import {Meilisearch} from "meilisearch";

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
        return prettyPrintJson.toHtml(data, options);
    })

    Twig._function.extend("path", (route, routeParams = {}) => {
        // console.error(routeParams);
        if ("_keys" in routeParams) {
            // if(routeParams.hasOwnProperty('_keys')){
            delete routeParams._keys; // seems to be added by twigjs
        }
        return Routing.generate(route, routeParams);
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

const defaults = {
    base: '/templates',    // ← folder where .twig files live
};

// 2) Load a template file via AJAX
const tpl = Twig.twig({
    ...defaults,
    // base: '/templatesXX',
    href: '/index/detail.twig',  // ← path relative to `base`
    load: true,                 // ← fetch it via XHR
    async: false                // ← block until loaded
});

// 3) Render it immediately
// const html = tpl.render({ title: 'Loaded via Twig.js!' });
// console.error(html);
// document.body.innerHTML = html;

/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://symfony.com/bundles/StimulusBundle/current/index.html#lazy-stimulus-controllers
*/

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['searchBox', 'hits',
        'template', 'pagination',
        'refinementList', 'marking']
    static values = {
        serverUrl: String,
        serverApiKey: String,
        indexName: String,
        templateUrl: String,
        globalsJson: {type: String, default: '{}'},
        iconsJson: {type: String, default: '{}'},
    }

    initialize() {
        // Called once when the controller is first instantiated (per element)

        // Here you can initialize variables, create scoped callables for event
        // listeners, instantiate external libraries, etc.
        // this._fooBar = this.fooBar.bind(this)
        this.globals = JSON.parse(this.globalsJsonValue);
        this.icons = JSON.parse(this.iconsJsonValue);

    }

    connect() {
        const self = this; // or use: const that = this;

        // Called every time the controller is connected to the DOM
        // (on page load, when it's added to the DOM, moved in the DOM, etc.)

        // Here you can add event listeners on the element or target elements,
        // add or remove classes, attributes, dispatch custom events, etc.
        // this.fooTarget.addEventListener('click', this._fooBar)

        this._self = this;
        this.fetchFile().then(() => {
                try {
                    this.search();
                } catch (e) {
                    this.hitsTarget.innerHTML = "URL: " + this.serverUrlValue + " " + e.message;
                }
        }
        )

    }

    async search() {
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

        this.searchClient = searchClient;
        setMeiliSearchParams({
            showRankingScore: true,
            showRankingScoreDetails: true
        });

        const search = instantsearch({
            indexName: this.indexNameValue,
            searchClient,
        })


        // An index is where the documents are stored.
        // let client = searchClient.index;
        this.rawMeiliSearch = new Meilisearch( {
                host: this.serverUrlValue,
                apiKey: this.serverApiKeyValue
            });


        // searchClient.search = async function(query, params) {
        //     console.log(query);
        //     console.log(params);
        //     return await searchClient.search(query, params);
        // }
        // let results = s('doll');
        // console.log(results);


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
                        // idea: extend the language to have a
                        // generic debug: https://github.com/twigjs/twig.js/wiki/Extending-twig.js-With-Custom-Tags
                        // this _does_ work, with includes!
                        let x= tpl.render({hit: hit, title: 'const tpl'});
                        return this.template.render({
                            x: x,
                            hit: hit,
                            icons: this.icons,
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
            const lookup = JSON.parse(div.getAttribute('data-lookup'));
            if (["rating", "price", "stock", "year", "value", "show", "starsXX", "airDate"].includes(attribute)) {
                search.addWidgets([
                    rangeSlider({
                        container: div,
                        attribute: attribute,
                        tooltips: value =>
                            attribute === 'price'
                                ? '$' + new Intl.NumberFormat().format(value)
                                : value,
                    }),
                ]);
                return;
            }
            let x = search.addWidgets([
                refinementList({
                    container: div,
                    limit: 5,
                    showMoreLimit: 10,
                    showMore: true,
                    searchable: !['gender','house','currentParty','marking'].includes(attribute),
                    attribute: attribute,
                    transformItems: (items, { results }) => {
                        if (Object.keys(lookup).length === 0) {
                            return items;
                        }
                        // let related = this.indexNameValue.replace(/obj$/, attribute);
                        // let related = 'm_px_victoria_type';
                        // let index = this.rawMeiliSearch.index(related);
                        // let index = this.searchClient.index(related);
                        // let yy = index.search('');
                        // yy.then(x => {
                        //     // console.log(attribute, related, x);
                        // })

                        // The 'results' parameter contains the full results data
                        return items.map(item => {
                            item.label = lookup[item.value] || item.value;
                            // item.value = lookup[item.value];
                            return {
                                ...item,
                                highlighted: lookup[item.value] || item.value
                            };
                        });
                    },
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

    /**
     *
     * Get the template specific to this index.
     *
     * @returns {Promise<void>}
     */
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
            // this is inline loading
            this.template = Twig.twig({data: data});
            // this.template = tpl;

        } catch (error) {
            console.error("File fetch failed:", error)
            if (this.hasOutputTarget) {
                this.outputTarget.textContent = `Error loading file: ${error.message}`
            }
        }
    }
}
