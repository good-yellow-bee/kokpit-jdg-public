const toBuffer = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
const toBase64Url = value => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const paths = {enrol: ['/passkey/enrol/options', '/passkey/enrol/result'], login: ['/passkey/login/options', '/passkey/login'], 'step-up': ['/passkey/step-up/options', '/passkey/step-up/verify']};
for (const mode of Object.keys(paths)) {
    const button = document.getElementById(`passkey-${mode}`);
    button?.addEventListener('click', async () => {
        const result = document.getElementById('passkey-result');
        const headers = {'Content-Type': 'application/json', 'X-CSRF-Token': button.dataset.csrf};
        try {
            const [optionsPath, resultPath] = mode === 'enrol' && button.dataset.options ? [button.dataset.options, button.dataset.result] : paths[mode];
            const optionsResponse = await fetch(optionsPath, {method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify({username: 'owner'})});
            if (!optionsResponse.ok) throw new Error('Nie można rozpocząć uwierzytelnienia.');
            const options = await optionsResponse.json();
            options.challenge = toBuffer(options.challenge);
            if (options.user) options.user.id = toBuffer(options.user.id);
            for (const key of ['excludeCredentials', 'allowCredentials']) if (options[key]) options[key] = options[key].map(item => ({...item, id: toBuffer(item.id)}));
            const credential = mode === 'enrol' ? await navigator.credentials.create({publicKey: options}) : await navigator.credentials.get({publicKey: options});
            const payload = {id: credential.id, rawId: toBase64Url(credential.rawId), type: credential.type, response: {clientDataJSON: toBase64Url(credential.response.clientDataJSON)}};
            if (mode === 'enrol') {
                payload.response.attestationObject = toBase64Url(credential.response.attestationObject);
                payload.response.transports = credential.response.getTransports?.() ?? [];
            } else {
                payload.response.authenticatorData = toBase64Url(credential.response.authenticatorData);
                payload.response.signature = toBase64Url(credential.response.signature);
                payload.response.userHandle = credential.response.userHandle ? toBase64Url(credential.response.userHandle) : null;
            }
            const saved = await fetch(resultPath, {method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(payload)});
            if (!saved.ok) throw new Error('Uwierzytelnienie nie powiodło się.');
            if (mode === 'step-up') { result.textContent = 'Potwierdzono. Masz 5 minut na akcję.'; return; }
            location.href = mode === 'enrol' ? (button.dataset.next || '/login') : '/';
        } catch { result.textContent = 'Nie udało się użyć passkeya. Spróbuj ponownie.'; }
    });
}
