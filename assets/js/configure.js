(() => {
    const page = document.querySelector('[data-configure-page]');
    const form = document.getElementById('configureForm');
    if (!page || !form) {
        return;
    }

    const button = document.getElementById('saveConfigureButton');
    const discardButton = document.getElementById('discardConfigureButton');
    const message = document.getElementById('configureMessage');
    const isReadOnly = button.dataset.readonly === 'true';
    let isSaving = false;
    let savedReferencePolicyDirection = page.dataset.savedReferencePolicyDirection || '';
    let savedReferencePolicyYears = page.dataset.savedReferencePolicyYears || '';

    function getSettingsState() {
        const formData = new FormData(form);
        formData.delete('reference_policy_confirmed');
        return new URLSearchParams(formData).toString();
    }

    let savedState = getSettingsState();

    function setMessage(text, type = 'status') {
        message.textContent = text;
        message.className = `text-sm leading-6 ${type === 'error' ? 'text-rose-700' : type === 'success' ? 'text-green-700' : type === 'warning' ? 'text-amber-800' : 'text-slate-600'}`;
    }

    function showSuccessToast(text) {
        window.AppToast.show(text, 'success');
    }

    function hasConfirmedPolicyChange() {
        const direction = form.elements.reference_policy_direction.value;
        const years = form.elements.reference_policy_years.value.trim();
        const policyChanged = direction !== savedReferencePolicyDirection
            || years !== savedReferencePolicyYears;

        return !policyChanged || (['within', 'at_least'].includes(direction)
            && /^(?:[1-9]\d{0,2}|1000)$/.test(years)
            && form.elements.reference_policy_confirmed.checked);
    }

    function updateFormState(statusText, statusType = 'status') {
        const policyChanged = form.elements.reference_policy_direction.value !== savedReferencePolicyDirection
            || form.elements.reference_policy_years.value.trim() !== savedReferencePolicyYears;
        const isDirty = getSettingsState() !== savedState;
        const policyConfirmed = hasConfirmedPolicyChange();

        form.elements.reference_policy_direction.required = policyChanged;
        form.elements.reference_policy_years.required = policyChanged;
        form.elements.reference_policy_confirmed.required = policyChanged;
        button.disabled = isReadOnly || isSaving || !isDirty || !policyConfirmed;
        discardButton.disabled = isReadOnly || isSaving || !isDirty;

        if (isReadOnly) {
            return;
        }

        if (statusText !== undefined) {
            setMessage(statusText, statusType);
        } else if (!isDirty) {
            setMessage(savedReferencePolicyDirection
                ? 'No unsaved changes.'
                : 'Reference-age policy is not configured. You can save other settings or configure it when the approved policy is available.');
        } else if (policyChanged && !['within', 'at_least'].includes(form.elements.reference_policy_direction.value)) {
            setMessage('Choose an approved reference-age rule before changing the policy.', 'warning');
        } else if (policyChanged && !form.elements.reference_policy_years.value.trim()) {
            setMessage('Enter the threshold from the approved rule before changing the policy.', 'warning');
        } else if (policyChanged && !form.elements.reference_policy_confirmed.checked) {
            setMessage('Confirm the new or changed policy to enable saving.', 'warning');
        } else {
            setMessage('Unsaved changes. Save or discard them.', 'warning');
        }
    }

    function clearCustomValidity() {
        Array.from(form.elements).forEach((field) => {
            field.setCustomValidity('');
            field.removeAttribute('aria-invalid');
        });
    }

    function showFieldError(fieldName, text) {
        const field = form.elements.namedItem(fieldName);
        if (!field) {
            setMessage(text, 'error');
            return;
        }

        field.setCustomValidity(text);
        field.setAttribute('aria-invalid', 'true');
        field.focus();
        field.reportValidity();
        setMessage(text, 'error');
    }

    form.addEventListener('input', (event) => {
        if (event.target && typeof event.target.setCustomValidity === 'function') {
            event.target.setCustomValidity('');
            event.target.removeAttribute('aria-invalid');
        }
        updateFormState();
    });
    form.addEventListener('change', (event) => {
        if (event.target && typeof event.target.setCustomValidity === 'function') {
            event.target.setCustomValidity('');
            event.target.removeAttribute('aria-invalid');
        }
        updateFormState();
    });

    discardButton.addEventListener('click', () => {
        form.reset();
        clearCustomValidity();
        updateFormState('Unsaved changes discarded. Saved values have been restored.');
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearCustomValidity();

        const ranges = [1, 2, 3].map((index) => ({
            name: `copyright_range_${index}`,
            value: Number(form.elements[`copyright_range_${index}`].value)
        }));
        const invalidRange = ranges.find((range) => !Number.isInteger(range.value) || range.value < 1 || range.value > 1000);
        if (invalidRange) {
            showFieldError(invalidRange.name, 'Enter a whole-number copyright threshold from 1 to 1,000 years.');
            return;
        }
        if (ranges[0].value >= ranges[1].value) {
            showFieldError(ranges[1].name, 'This threshold must be greater than the preceding window.');
            return;
        }
        if (ranges[1].value >= ranges[2].value) {
            showFieldError(ranges[2].name, 'This threshold must be greater than the preceding window.');
            return;
        }

        const direction = form.elements.reference_policy_direction.value;
        const years = form.elements.reference_policy_years.value.trim();
        const policyChanged = direction !== savedReferencePolicyDirection
            || years !== savedReferencePolicyYears;
        if (policyChanged) {
            if (!['within', 'at_least'].includes(direction)) {
                showFieldError('reference_policy_direction', 'Choose an approved reference-age rule before changing the policy.');
                return;
            }
            if (!/^(?:[1-9]\d{0,2}|1000)$/.test(years)) {
                showFieldError('reference_policy_years', 'Enter the threshold from the approved rule, from 1 to 1,000 years.');
                return;
            }
            if (!form.elements.reference_policy_confirmed.checked) {
                showFieldError('reference_policy_confirmed', 'Confirm that the new or changed rule matches an approved institutional policy.');
                return;
            }
        }

        if (!form.reportValidity()) {
            setMessage('Review the highlighted fields and correct the values before saving.', 'error');
            return;
        }

        const formData = new FormData(form);
        formData.set('csrf_token', page.dataset.csrfToken);
        isSaving = true;
        button.textContent = 'Saving...';
        form.setAttribute('aria-busy', 'true');
        updateFormState('Saving library settings...');

        try {
            const response = await fetch('pages/configure.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(formData)
            });

            let result;
            try {
                result = await response.json();
            } catch {
                throw new Error('The server response could not be read. Your changes are still here; try again.');
            }

            if (!response.ok || !result.success) {
                if (typeof result.field === 'string') {
                    showFieldError(result.field, result.message || 'Check this value and try again.');
                    return;
                }
                throw new Error(result.message || 'Settings could not be saved. Your changes are still here; try again.');
            }

            savedState = getSettingsState();
            savedReferencePolicyDirection = direction;
            savedReferencePolicyYears = years;
            showSuccessToast(result.message || 'Settings saved.');
            updateFormState();
        } catch (error) {
            setMessage(error instanceof Error
                ? error.message
                : 'Settings could not be saved. Your changes are still here; try again.', 'error');
        } finally {
            isSaving = false;
            button.textContent = 'Save settings';
            form.removeAttribute('aria-busy');
            updateFormState(message.textContent, message.className.includes('text-rose-700')
                ? 'error'
                : message.className.includes('text-green-700')
                    ? 'success'
                    : 'status');
        }
    });

    updateFormState();
})();
