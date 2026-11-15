(() => {
    const userId = String(window.appUserPreferenceId || '');
    const storageKey = `appsys.preferences.v1.${userId}`;
    const defaults = { density: 'comfortable', rowsPerPage: 10, textSize: 'default', theme: 'light' };
    const validDensities = ['comfortable', 'compact'];
    const validPageSizes = [5, 10, 20, 50];
    const validTextSizes = ['default', 'large'];
    const validThemes = ['light', 'dark', 'system'];
    const systemThemeQuery = window.matchMedia('(prefers-color-scheme: dark)');
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
                density: validDensities.includes(parsed.density) ? parsed.density : defaults.density,
                rowsPerPage: validPageSizes.includes(Number(parsed.rowsPerPage)) ? Number(parsed.rowsPerPage) : defaults.rowsPerPage,
                textSize: validTextSizes.includes(parsed.textSize) ? parsed.textSize : defaults.textSize,
                theme: validThemes.includes(parsed.theme) ? parsed.theme : defaults.theme,
            };
        } catch (error) {
            storageAvailable = false;
            console.error('App preferences could not be read from this browser.', error);
            return { ...defaults };
        }
    }

    let preferences = readPreferences();

    function applyPreferences() {
        document.documentElement.dataset.appDensity = preferences.density;
        document.documentElement.dataset.appTextSize = preferences.textSize;
        document.documentElement.dataset.appTheme = preferences.theme === 'system'
            ? (systemThemeQuery.matches ? 'dark' : 'light')
            : preferences.theme;
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

    window.addEventListener('storage', (event) => {
        if (event.key === storageKey) {
            preferences = readPreferences();
            applyPreferences();
        }
    });
    const updateSystemTheme = () => {
        if (preferences.theme === 'system') applyPreferences();
    };
    if (typeof systemThemeQuery.addEventListener === 'function') {
        systemThemeQuery.addEventListener('change', updateSystemTheme);
    } else {
        systemThemeQuery.addListener(updateSystemTheme);
    }

    applyPreferences();

    window.AppPreferences = {
        get() {
            return { ...preferences };
        },
        isStorageAvailable() {
            return storageAvailable;
        },
        set(name, value) {
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
            if (name === 'theme' && validThemes.includes(value)) {
                save({ ...preferences, theme: value });
                return;
            }
            throw new Error('Choose a supported preference option.');
        },
        reset() {
            save({ ...defaults });
        },
    };
})();
