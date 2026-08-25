import { Controller } from '@hotwired/stimulus';

/**
 * Minimal custom dropdown: a styled trigger button that toggles a floating
 * menu, used to replace plain native elements with something that actually
 * matches the app's design system. Two modes, both optional and combinable:
 *
 * - Navigation menu (e.g. the payment list's client/status filters): menu
 *   items are plain links, markup is fully author-supplied in Twig.
 * - Select replacement (the `select` target): menu items are generated from
 *   the bound <select>'s own <option>s, so it stays the single source of
 *   truth for the current value and for form submission — the styled UI is
 *   just a skin on top, kept hidden but present and functional.
 */
export default class extends Controller {
    static targets = ['trigger', 'menu', 'select', 'label'];

    connect() {
        if (this.hasSelectTarget) {
            this.buildMenuFromSelect();
        }
    }

    toggle(event) {
        event.stopPropagation();
        const isOpen = this.menuTarget.classList.toggle('is-open');
        this.triggerTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    close() {
        this.menuTarget.classList.remove('is-open');
        this.triggerTarget.setAttribute('aria-expanded', 'false');
    }

    closeOnClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    closeOnEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }

    buildMenuFromSelect() {
        this.menuTarget.innerHTML = '';
        Array.from(this.selectTarget.options).forEach((option) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'dropdown__item' + (option.selected ? ' is-selected' : '');
            item.textContent = option.text;
            item.dataset.value = option.value;
            item.dataset.action = 'dropdown#pick';
            this.menuTarget.appendChild(item);
        });
        this.syncLabelFromSelect();
    }

    /** Click handler for a select-mode menu item: mirrors it onto the bound <select>. */
    pick(event) {
        const value = event.currentTarget.dataset.value;
        this.selectTarget.value = value;
        this.selectTarget.dispatchEvent(new Event('change', { bubbles: true }));

        this.menuTarget.querySelectorAll('.dropdown__item').forEach((item) => {
            item.classList.toggle('is-selected', item.dataset.value === value);
        });
        this.syncLabelFromSelect();
        this.close();
    }

    syncLabelFromSelect() {
        if (!this.hasLabelTarget) {
            return;
        }
        const selected = this.selectTarget.options[this.selectTarget.selectedIndex];
        this.labelTarget.textContent = selected ? selected.text : '';
    }
}
