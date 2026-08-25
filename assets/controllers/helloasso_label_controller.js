import { Controller } from '@hotwired/stimulus';

/**
 * Toggles the HelloAsso config "label" field between a fixed dropdown
 * (Particuliers / Professionnels — the guardrail) and a free-text override,
 * driven by the "libellé personnalisé" checkbox. See
 * HelloAssoConfigType::LABEL_CHOICES for why the guardrail defaults on.
 */
export default class extends Controller {
    static targets = ['toggle', 'choice', 'custom'];

    connect() {
        this.sync();
    }

    sync() {
        const useCustom = this.toggleTarget.checked;
        this.choiceTarget.disabled = useCustom;
        this.customTarget.disabled = !useCustom;
        this.customTarget.closest('.form-row').classList.toggle('is-hidden', !useCustom);
        this.choiceTarget.closest('.form-row').classList.toggle('is-hidden', useCustom);
    }
}
