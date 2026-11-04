(() => {
    const userId = String(window.appUserPreferenceId || '');
    const storageKey = `appsys.preferences.v1.${userId}`;
    const defaults = { theme: 'system', density: 'comfortable', rowsPerPage: 10, textSize: 'default' };
    const validThemes = ['system', 'light', 'dark'];
    const validDensities = ['comfortable', 'compact'];
    const validPageSizes = [5, 10, 20, 50];
    const validTextSizes = ['default', 'large'];
    const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
    let storageAvailable = true;

    function readPreferences() {
        try {
            const stored = window.localStorage.getItem(storageKey);
            if (!stored) {
                return { ...defaults };
            }

            const parsed = JSON.parse(stored);
            if (!parsed || typeof parsed !== 'object') {
                return { ...defaults };
            }
            return {
                theme: validThemes.includes(parsed.theme) ? parsed.theme : defaults.theme,
                density: validDensities.includes(parsed.density) ? parsed.density : defaults.density,
                rowsPerPage: validPageSizes.includes(Number(parsed.rowsPerPage)) ? Number(parsed.rowsPerPage) : defaults.rowsPerPage,
                textSize: validTextSizes.includes(parsed.textSize) ? parsed.textSize : defaults.textSize,
            };
        } catch (error) {
            storageAvailable = false;
            console.error('App preferences could not be read from this browser.', error);
            return { ...defaults };
        }
    }

    let preferences = readPreferences();

    function applyPreferences() {
        const theme = preferences.theme === 'system'
            ? (colorScheme.matches ? 'dark' : 'light')
            : preferences.theme;
        document.documentElement.dataset.appTheme = theme;
        document.documentElement.dataset.appDensity = preferences.density;
        document.documentElement.dataset.appTextSize = preferences.textSize;
    }

    function save(nextPreferences) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(nextPreferences));
        } catch (error) {
            storageAvailable = false;
            console.error('App preferences could not be saved to this browser.', error);
            throw new Error('Your browser could not save this preference. Check its storage settings and try again.');
        }

        storageAvailable = true;
        preferences = nextPreferences;
        applyPreferences();
        window.dispatchEvent(new CustomEvent('app-preferences-change', { detail: { ...preferences } }));
    }

    colorScheme.addEventListener('change', () => {
        if (preferences.theme === 'system') {
            applyPreferences();
        }
    });

    window.addEventListener('storage', (event) => {
        if (event.key === storageKey) {
            preferences = readPreferences();
            applyPreferences();
        }
    });

    applyPreferences();

    window.AppPreferences = {
        get() {
            return { ...preferences };
        },
        isStorageAvailable() {
            return storageAvailable;
        },
        set(name, value) {
            if (name === 'theme' && validThemes.includes(value)) {
                save({ ...preferences, theme: value });
                return;
            }
            if (name === 'density' && validDensities.includes(value)) {
                save({ ...preferences, density: value });
                return;
            }
            if (name === 'rowsPerPage' && validPageSizes.includes(Number(value))) {
                save({ ...preferences, rowsPerPage: Number(value) });
                return;
            }
            if (name === 'textSize' && validTextSizes.includes(value)) {
                save({ ...preferences, textSize: value });
                return;
            }
            throw new Error('Choose a supported preference option.');
        },
        reset() {
            save({ ...defaults });
        },
    };
})();
