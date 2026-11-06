(() => {
    const accountPage = document.querySelector('[data-account-page]');
    if (!accountPage) {
        return;
    }

    const forms = [...accountPage.querySelectorAll('.account-update-form')];

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitButton = form.querySelector('.account-submit');
            const status = form.querySelector('.account-form-status');
            const originalLabel = submitButton.textContent.trim();
            const formData = new FormData(form);
            formData.set('action', form.dataset.accountAction);
            status.textContent = 'Saving…';
            status.classList.remove('text-red-700', 'text-green-700');
            submitButton.disabled = true;
            submitButton.textContent = 'Saving…';

            try {
                const response = await fetch('pages/account.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });
                let result;
                try {
                    result = await response.json();
                } catch {
                    throw new Error(response.redirected
                        ? 'Your session has expired. Sign in again, then retry the change.'
                        : 'The server returned an unexpected response. Please try again.');
                }
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to save account changes.');
                }

                if (typeof result.csrfToken === 'string') {
                    forms.forEach((accountForm) => {
                        accountForm.querySelector('[name="csrf_token"]').value = result.csrfToken;
                    });
                }

                if (form.dataset.accountAction === 'update_username') {
                    form.elements.new_username.value = result.username;
                    form.elements.current_password.value = '';
                    accountPage.querySelector('#accountCurrentUsername').textContent = result.username;
                } else {
                    form.reset();
                }

                status.textContent = result.message;
                status.classList.add('text-green-700');
                window.AppToast.show(result.message);
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Unable to save account changes.';
                form.elements.current_password.value = '';
                status.textContent = message;
                status.classList.add('text-red-700');
                window.AppToast.show(message, 'error');
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = originalLabel;
            }
        });
    });
})();
