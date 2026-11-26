(() => {
    const accountPage = document.querySelector('[data-account-page]');
    if (!accountPage) {
        return;
    }

    const forms = [...accountPage.querySelectorAll('.account-update-form')];
    const passwordForm = accountPage.querySelector('[data-account-action="update_password"]');
    const newPasswordInput = passwordForm?.querySelector('#accountNewPassword');
    const passwordConfirmationInput = passwordForm?.querySelector('#accountPasswordConfirmation');
    const passwordRequirements = passwordForm?.querySelector('#accountPasswordRequirements');
    const passwordStatus = passwordForm?.querySelector('#accountPasswordStatus');
    const passwordConfirmationStatus = passwordForm?.querySelector('#accountPasswordConfirmationStatus');
    const passwordLabels = {
        accountPasswordCurrent: 'current password',
        accountNewPassword: 'new password',
        accountPasswordConfirmation: 'password confirmation',
    };

    function updatePasswordRequirements() {
        if (!newPasswordInput || !passwordStatus) {
            return false;
        }

        const value = newPasswordInput.value;
        const rules = [
            {
                valid: value.length >= newPasswordInput.minLength && value.length <= newPasswordInput.maxLength,
                requirement: `${newPasswordInput.minLength}-${newPasswordInput.maxLength} characters`,
            },
            { valid: /[A-Za-z]/.test(value), requirement: 'at least one letter' },
            { valid: /[0-9]/.test(value), requirement: 'at least one number' },
            { valid: /[^A-Za-z0-9]/.test(value), requirement: 'at least one special character' },
        ];

        const allValid = rules.every(({ valid }) => valid);
        const isFocused = document.activeElement === newPasswordInput;
        const unmetRequirements = rules
            .filter(({ valid }) => !valid)
            .map(({ requirement }) => requirement);

        passwordRequirements.hidden = !isFocused;
        passwordStatus.textContent = allValid
            ? 'Password meets all requirements.'
            : `Still needed: ${unmetRequirements.join(', ')}.`;
        passwordStatus.classList.toggle('is-success', isFocused && allValid);
        passwordStatus.classList.toggle('is-error', isFocused && !allValid);

        if (value.length > 0) {
            newPasswordInput.setAttribute('aria-invalid', String(!allValid));
        } else {
            newPasswordInput.removeAttribute('aria-invalid');
        }

        return allValid;
    }

    function updatePasswordConfirmation() {
        if (!newPasswordInput || !passwordConfirmationInput || !passwordConfirmationStatus) {
            return false;
        }

        const hasValue = passwordConfirmationInput.value.length > 0;
        const matches = hasValue && passwordConfirmationInput.value === newPasswordInput.value;
        passwordConfirmationStatus.hidden = !hasValue;
        passwordConfirmationStatus.textContent = hasValue
            ? matches ? 'Passwords match.' : 'Passwords do not match.'
            : '';
        passwordConfirmationStatus.classList.toggle('is-success', matches);
        passwordConfirmationStatus.classList.toggle('is-error', hasValue && !matches);

        if (hasValue) {
            passwordConfirmationInput.setAttribute('aria-invalid', String(!matches));
        } else {
            passwordConfirmationInput.removeAttribute('aria-invalid');
        }

        return matches;
    }

    function validatePasswordForm() {
        const passwordValid = updatePasswordRequirements();
        const confirmationMatches = updatePasswordConfirmation();
        if (passwordValid && confirmationMatches) {
            return true;
        }

        const status = passwordForm.querySelector('.account-form-status');
        status.textContent = 'Check the password requirements and confirmation.';
        status.classList.remove('is-success');
        status.classList.add('is-error');
        (passwordValid ? passwordConfirmationInput : newPasswordInput).focus();
        return false;
    }

    if (newPasswordInput && passwordConfirmationInput) {
        newPasswordInput.addEventListener('input', () => {
            updatePasswordRequirements();
            updatePasswordConfirmation();
        });
        newPasswordInput.addEventListener('focus', updatePasswordRequirements);
        newPasswordInput.addEventListener('blur', () => {
            passwordRequirements.hidden = true;
        });
        passwordConfirmationInput.addEventListener('input', updatePasswordConfirmation);

        passwordForm.querySelectorAll('[data-account-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = passwordForm.querySelector(`#${button.dataset.accountPasswordToggle}`);
                if (!input) {
                    return;
                }

                const showPassword = input.type === 'password';
                input.type = showPassword ? 'text' : 'password';
                button.textContent = showPassword ? 'Hide' : 'Show';
                button.setAttribute('aria-pressed', String(showPassword));
                button.setAttribute(
                    'aria-label',
                    `${showPassword ? 'Hide' : 'Show'} ${passwordLabels[input.id]}`
                );
            });
        });
    }

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (form === passwordForm && !validatePasswordForm()) {
                return;
            }

            const submitButton = form.querySelector('.account-submit');
            const status = form.querySelector('.account-form-status');
            const originalLabel = submitButton.textContent.trim();
            const formData = new FormData(form);
            formData.set('action', form.dataset.accountAction);
            status.textContent = 'Saving…';
            status.classList.remove('is-success', 'is-error');
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
                    updatePasswordRequirements();
                    updatePasswordConfirmation();
                    passwordForm.querySelectorAll('[data-account-password-toggle]').forEach((button) => {
                        const input = passwordForm.querySelector(`#${button.dataset.accountPasswordToggle}`);
                        if (!input) {
                            return;
                        }
                        input.type = 'password';
                        button.textContent = 'Show';
                        button.setAttribute('aria-pressed', 'false');
                        button.setAttribute(
                            'aria-label',
                            `Show ${passwordLabels[input.id]}`
                        );
                    });
                }

                status.textContent = result.message;
                status.classList.add('is-success');
                window.AppToast.show(result.message);
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Unable to save account changes.';
                form.elements.current_password.value = '';
                status.textContent = message;
                status.classList.add('is-error');
                window.AppToast.show(message, 'error');
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = originalLabel;
            }
        });
    });
})();
