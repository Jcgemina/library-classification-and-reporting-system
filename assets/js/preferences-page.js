(() => {
    const page = document.querySelector('[data-preferences-page]');
    if (!page || !window.AppPreferences) {
        return;
    }

    const densitySelect = document.getElementById('preferenceDensity');
    const rowsPerPageSelect = document.getElementById('preferenceRowsPerPage');
    const textSizeSelect = document.getElementById('preferenceTextSize');
    const themeSelect = document.getElementById('preferenceTheme');
    const message = document.getElementById('preferencesMessage');
    const resetButton = document.getElementById('resetPreferencesButton');

    function updateControls() {
        const preferences = window.AppPreferences.get();
        densitySelect.value = preferences.density;
        rowsPerPageSelect.value = String(preferences.rowsPerPage);
        textSizeSelect.value = preferences.textSize;
        themeSelect.value = preferences.theme;
    }

    if (!window.AppPreferences.isStorageAvailable()) {
        message.textContent = 'Preferences could not be read from browser storage. Choose an option to save them again, or check your browser storage settings.';
        message.className = 'settings-status is-error';
    }

    function savePreference(name, value) {
        try {
            window.AppPreferences.set(name, value);
            message.textContent = 'Preference saved in this browser for your account.';
            message.className = 'settings-status is-success';
        } catch (error) {
            updateControls();
            message.textContent = error instanceof Error ? error.message : 'Preference could not be saved.';
            message.className = 'settings-status is-error';
        }
    }

    densitySelect.addEventListener('change', () => savePreference('density', densitySelect.value));
    rowsPerPageSelect.addEventListener('change', () => savePreference('rowsPerPage', rowsPerPageSelect.value));
    textSizeSelect.addEventListener('change', () => savePreference('textSize', textSizeSelect.value));
    themeSelect.addEventListener('change', () => savePreference('theme', themeSelect.value));
    resetButton.addEventListener('click', () => {
        try {
            window.AppPreferences.reset();
            updateControls();
            message.textContent = 'Preferences restored to the light theme, comfortable spacing, 10 rows per page, and default text size.';
            message.className = 'settings-status is-success';
        } catch (error) {
            message.textContent = error instanceof Error ? error.message : 'Preferences could not be reset.';
            message.className = 'settings-status is-error';
        }
    });

    updateControls();
})();
