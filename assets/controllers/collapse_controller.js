import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
    static targets = ["text", "button"]
    static values  = {
        lines:   Number,
        moreText: String,
        lessText: String
    }

    connect() {
        this.isOpen = false
        this.update()
    }

    toggle() {
        this.isOpen = !this.isOpen
        this.update()
    }

    update() {
        // clamp or unclamp
        this.textTarget.style.webkitLineClamp = this.isOpen
            ? "none"
            : String(this.linesValue)

        // button label & aria
        this.buttonTarget.textContent = this.isOpen
            ? this.lessTextValue
            : this.moreTextValue
        this.buttonTarget.setAttribute("aria-expanded", String(this.isOpen))
    }
}
