// locale_display
import {Controller} from '@hotwired/stimulus';

// @todo, add days of week and month names: https://stackoverflow.com/questions/39972925/get-localized-month-name-using-native-js

/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://github.com/symfony/stimulus-bridge#lazy-controllers
*/
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['country', 'language']
    static values = {
        userLocale: String,
    }

    // ...
    initialize() {
        this.regionNames = new Intl.DisplayNames(
            [this.userLocaleValue], {type: 'region'}
        );
        this.languageNames = new Intl.DisplayNames(
            [this.userLocaleValue], {type: "language"});

    }

    connect() {
        // console.error('hello from ' + this.identifier + ' / ' + this.userLocaleValue);

        if (this.hasLanguageTargets) {
            this.languageTargets.foreach((t) => {
                console.error("we have targets at connect time");
                    let languageCode = t.dataset.lang;
                    console.error(cc);
                    t.innerHTML = 'testing...' + languageCode; //  +  this.regionNames.of('MX');
                }
            );

        } else {
            // console.error('no language targets in ' + this.identifier);
        }

        // this.element.innerHTML =
        //     // this.userLocaleValue
        // // + ' ' + this.userLocaleValue +
        // //     + this.countryValue + ' / '
        //     // + regionNames.of('MX');
        //     regionNames.of(this.countryValue.toUpperCase());
    }

    countryTargetConnected(element) {
        // let countryCode = element.innerText;
        let countryCode = element.dataset.cc;
        if (countryCode) {
            element.innerHTML = this.regionNames.of(countryCode.toUpperCase());
        }
    }

    languageTargetConnected(el) {
        // console.assert(this.regionNames);
        let languageCode = el.dataset.lang;
        if (languageCode === undefined) {
            console.error('missing data-lang in language target ', el.dataset.all);
            return;
        }

        var x = this.languageNames.of(languageCode);
            if (x === undefined) {
                console.error( "Invalid languageCode code " + languageCode);
            } else {
                el.innerHTML = x;
            }
        try {
        } catch (error) {
            console.error(error, languageCode);
            // Expected output: ReferenceError: nonExistentFunction is not defined
            // (Note: the exact output may be browser-dependent)
        }
    }


}
