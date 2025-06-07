import {Controller} from '@hotwired/stimulus';

import meiliSearch from 'meilisearch';
import instantsearch from 'instantsearch.js'
import { searchBox, hits, pagination, refinementList } from 'instantsearch.js/es/widgets'
import { instantMeiliSearch } from '@meilisearch/instant-meilisearch'
import '@meilisearch/instant-meilisearch/templates/basic_search.css';

import Twig from 'twig';
/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://symfony.com/bundles/StimulusBundle/current/index.html#lazy-stimulus-controllers
*/

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['searchBox', 'hits', 'template', 'pagination', 'genres']
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
        this.search();
        // console.log(this.templateTarget.innerHTML);
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
                    item: (hit) => {
                        console.log(hit);
                        return this.template.render({hit: hit});
                    },
                },
            }),
            pagination({
                container: this.paginationTarget
            }),
            // refinementList({
            //     container: this.genresTarget,
            //     attribute: 'genre',
            // }),
        ])

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

        disconnect()
        {
            // Called anytime its element is disconnected from the DOM
            // (on page change, when it's removed from or moved in the DOM, etc.)

            // Here you should remove all event listeners added in "connect()"
            // this.fooTarget.removeEventListener('click', this._fooBar)
        }
    }
