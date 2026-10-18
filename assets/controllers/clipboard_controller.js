import { Controller } from '@hotwired/stimulus';

// Kopiowanie danych przelewu z panelu `/costs` (D-83): wartość pola o id z parametru `source` trafia do schowka.
// Pola są `readonly`, więc bez JavaScriptu albo bez dostępu do schowka zaznaczasz je ręcznie.
export default class extends Controller {
    static targets = ['status'];

    async copy({ params: { source } }) {
        const field = document.getElementById(source);
        if (!field) {
            return;
        }
        try {
            await navigator.clipboard.writeText(field.value);
            this.show('Skopiowano.');
        } catch {
            field.select();
            this.show('Schowek niedostępny; pole jest zaznaczone, skopiuj je ręcznie.');
        }
    }

    show(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
        }
    }
}
