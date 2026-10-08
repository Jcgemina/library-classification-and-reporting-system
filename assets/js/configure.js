(() => {
    const page = document.querySelector('[data-configure-page]');
    const form = document.getElementById('configureForm');
    if (!page || !form) {
        return;
    }

    const button = document.getElementById('saveConfigureButton');
    const message = document.getElementById('configureMessage');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        message.textContent = '';
        message.className = 'text-sm text-slate-600';

        const ranges = [1, 2, 3].map((index) => Number(form.elements[`copyright_range_${index}`].value));
        if (ranges.some((value) => !Number.isInteger(value) || value < 1 || value > 1000)
            || ranges[0] >= ranges[1]
            || ranges[1] >= ranges[2]) {
            message.textContent = 'Enter three unique copyright ranges in ascending order, from 1 to 1,000 years.';
            message.className = 'text-sm text-rose-700';
            form.elements.copyright_range_1.focus();
            return;
        }

        if (!form.reportValidity()) {
            return;
        }

        const formData = new FormData(form);
        formData.set('csrf_token', page.dataset.csrfToken);
        button.disabled = true;
        button.textContent = 'Saving...';

        try {
            const response = await fetch('pages/configure.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(formData)
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Settings could not be saved.');
            }

            message.textContent = result.message;
            message.className = 'text-sm font-semibold text-emerald-700';
        } catch (error) {
            message.textContent = error.message || 'Settings could not be saved. Try again.';
            message.className = 'text-sm font-semibold text-rose-700';
        } finally {
            button.disabled = false;
            button.textContent = 'Save settings';
        }
    });
})();
