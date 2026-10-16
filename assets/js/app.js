document.addEventListener('submit', (event) => {
    const form = event.target;
    const message = form instanceof HTMLFormElement ? form.dataset.confirm : '';
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-print]')) {
        window.print();
    }
});

const passwordChecks = {
    length: (value) => value.length >= 8 && value.length <= 200,
    lowercase: (value) => /[a-z]/.test(value),
    uppercase: (value) => /[A-Z]/.test(value),
    special: (value) => /[^A-Za-z0-9\s]/.test(value),
};

document.querySelectorAll('input[type="password"]').forEach((input, index) => {
    if (!(input instanceof HTMLInputElement) || !input.parentElement) return;
    const wrapper = document.createElement('div');
    wrapper.className = 'password-input-wrap';
    input.parentElement.insertBefore(wrapper, input);
    wrapper.append(input);

    const toggle = document.createElement('button');
    toggle.className = 'password-visibility-toggle';
    toggle.type = 'button';
    toggle.textContent = 'Show';
    toggle.setAttribute('aria-pressed', 'false');
    if (!input.id) input.id = `password-field-${index + 1}`;
    toggle.setAttribute('aria-controls', input.id);
    toggle.setAttribute('aria-label', `Show ${input.labels?.[0]?.textContent?.trim() || 'password'}`);
    toggle.addEventListener('click', () => {
        const showing = input.type === 'password';
        input.type = showing ? 'text' : 'password';
        toggle.textContent = showing ? 'Hide' : 'Show';
        toggle.setAttribute('aria-pressed', showing ? 'true' : 'false');
        toggle.setAttribute('aria-label', `${showing ? 'Hide' : 'Show'} ${input.labels?.[0]?.textContent?.trim() || 'password'}`);
        input.focus();
    });
    wrapper.append(toggle);
});

document.querySelectorAll('[data-password-rules]').forEach((input) => {
    const updatePasswordRules = () => {
        const value = input.value;
        const results = Object.entries(passwordChecks).map(([name, check]) => [name, check(value)]);
        const complete = results.every(([, passed]) => passed);

        results.forEach(([name, passed]) => {
            const rule = input.closest('.form-field')?.querySelector(`[data-password-rule="${name}"]`);
            if (!rule) return;
            rule.classList.toggle('is-valid', passed);
            const marker = rule.querySelector('span');
            if (marker) marker.textContent = passed ? '✓' : '○';
        });

        input.setCustomValidity(complete || value === '' ? '' : 'Use 8–200 characters with a lowercase letter, an uppercase letter and a special character.');
    };

    input.addEventListener('input', updatePasswordRules);
    updatePasswordRules();
});
