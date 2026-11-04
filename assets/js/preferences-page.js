(() => {
    const page = document.querySelector('[data-preferences-page]');
    if (!page || !window.AppPreferences) {
        return;
    }

    const themeSelect = document.getElementById('preferenceTheme');
    const densitySelect = document.getElementById('preferenceDensity');
    const rowsPerPageSelect = document.getElementById('preferenceRowsPerPage');
    const textSizeSelect = document.getElementById('preferenceTextSize');
    const message = document.getElementById('preferencesMessage');
    const resetButton = document.getElementById('resetPreferencesButton');

    function updateControls() {
        const preferences = window.AppPreferences.get();
        themeSelect.value = preferences.theme;
        densitySelect.value = preferences.density;
        rowsPerPageSelect.value = String(preferences.rowsPerPage);
        textSizeSelect.value = preferences.textSize;
    }

    if (!window.AppPreferences.isStorageAvailable()) {
        message.textContent = 'Preferences could not be read from browser storage. Choose an option to save them again, or check your browser storage settings.';
        message.className = 'text-sm text-rose-700';
    }

    function savePreference(name, value) {
        try {
            window.AppPreferences.set(name, value);
            message.textContent = 'Preference saved in this browser for your account.';
            message.className = 'text-sm text-green-700';
        } catch (error) {
            updateControls();
            message.textContent = error instanceof Error ? error.message : 'Preference could not be saved.';
            message.className = 'text-sm text-rose-700';
        }
    }

    themeSelect.addEventListener('change', () => savePreference('theme', themeSelect.value));
    densitySelect.addEventListener('change', () => savePreference('density', densitySelect.value));
    rowsPerPageSelect.addEventListener('change', () => savePreference('rowsPerPage', rowsPerPageSelect.value));
    textSizeSelect.addEventListener('change', () => savePreference('textSize', textSizeSelect.value));
    resetButton.addEventListener('click', () => {
        try {
            window.AppPreferences.reset();
            updateControls();
            message.textContent = 'Preferences restored to device theme, comfortable spacing, 10 rows per page, and default text size.';
            message.className = 'text-sm text-green-700';
        } catch (error) {
            message.textContent = error instanceof Error ? error.message : 'Preferences could not be reset.';
            message.className = 'text-sm text-rose-700';
        }
    });

    updateControls();
})();
