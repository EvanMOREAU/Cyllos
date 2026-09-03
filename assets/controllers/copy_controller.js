import { Controller } from '@hotwired/stimulus';

/**
 * Copies the text of the `source` target to the clipboard, then briefly shows a
 * "Copié !" confirmation on the `button` target.
 *
 *   <div data-controller="copy">
 *     <code data-copy-target="source">…</code>
 *     <button data-copy-target="button" data-action="copy#copy">Copier</button>
 *   </div>
 */
export default class extends Controller {
    static targets = ['source', 'button'];
    static values = { label: { type: String, default: 'Copier' }, done: { type: String, default: 'Copié !' } };

    async copy() {
        const text = (this.sourceTarget.textContent || '').trim();
        if (!text) {
            return;
        }

        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // Fallback for non-secure contexts / older browsers.
            const range = document.createRange();
            range.selectNodeContents(this.sourceTarget);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            document.execCommand('copy');
            selection.removeAllRanges();
        }

        this.flashDone();
    }

    flashDone() {
        if (!this.hasButtonTarget) {
            return;
        }
        const btn = this.buttonTarget.closest('button') || this.buttonTarget;
        clearTimeout(this.resetTimer);
        btn.dataset.copied = 'true';
        this.buttonTarget.textContent = this.doneValue;
        this.resetTimer = setTimeout(() => {
            delete btn.dataset.copied;
            this.buttonTarget.textContent = this.labelValue;
        }, 1600);
    }

    disconnect() {
        clearTimeout(this.resetTimer);
    }
}
