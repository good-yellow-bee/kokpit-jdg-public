// Panel (D-73): Stimulus z kontrolerami z `assets/controllers.json` i Turbo tylko dla ramek (`chat-turn`, D-61);
// Turbo Drive wyłączony, więc zwykłe linki i formularze, w tym passkey, działają jak bez JavaScriptu.
import { startStimulusApp } from '@symfony/stimulus-bundle';
import * as Turbo from '@hotwired/turbo';

Turbo.session.drive = false;
startStimulusApp();
