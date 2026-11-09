<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=preferences');
    exit;
}

requireLogin();
?>

<div class="settings-screen" data-preferences-page>
    <header class="settings-page-heading">
        <div>
            <h1>Preference</h1>
            <p class="page-description">Adjust how AppSys Library looks for you. These choices do not affect other users.</p>
        </div>
        <span class="settings-preview-chip"><i data-lucide="sliders-horizontal" aria-hidden="true"></i>Personal settings</span>
    </header>

    <div class="settings-sheet">
        <section class="settings-section" aria-labelledby="appearanceHeading">
            <div>
                <h2 class="settings-section-title" id="appearanceHeading">Appearance</h2>
                <p class="settings-section-copy">Choose how your workspace looks and how much information fits on screen.</p>
            </div>
            <div class="settings-fields">
                <label for="preferenceTheme" class="settings-field">
                    <span class="settings-field-label">Theme</span>
                    <select id="preferenceTheme">
                        <option value="system">Use device setting</option>
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </select>
                    <span class="settings-field-help">Device setting follows your operating system’s light or dark mode.</span>
                </label>
                <label for="preferenceDensity" class="settings-field">
                    <span class="settings-field-label">Table spacing</span>
                    <select id="preferenceDensity">
                        <option value="comfortable">Comfortable</option>
                        <option value="compact">Compact</option>
                    </select>
                    <span class="settings-field-help">Compact spacing shows more rows at once; comfortable spacing gives records more room.</span>
                </label>
            </div>
        </section>

        <section class="settings-section" aria-labelledby="readingHeading">
            <div>
                <h2 class="settings-section-title" id="readingHeading">Reading and lists</h2>
                <p class="settings-section-copy">Set text size and how many records appear on each page in paginated lists.</p>
            </div>
            <div class="settings-fields">
                <label for="preferenceTextSize" class="settings-field">
                    <span class="settings-field-label">Text size</span>
                    <select id="preferenceTextSize">
                        <option value="default">Default</option>
                        <option value="large">Larger</option>
                    </select>
                    <span class="settings-field-help">Larger text improves readability throughout the app.</span>
                </label>
                <label for="preferenceRowsPerPage" class="settings-field">
                    <span class="settings-field-label">Rows per page</span>
                    <select id="preferenceRowsPerPage">
                        <option value="5">5 rows</option>
                        <option value="10">10 rows</option>
                        <option value="20">20 rows</option>
                        <option value="50">50 rows</option>
                    </select>
                    <span class="settings-field-help">Applies to paginated account and audit-log lists.</span>
                </label>
            </div>
        </section>
    </div>

    <div class="settings-actions">
        <p id="preferencesMessage" class="settings-status" role="status" aria-live="polite">Preferences are saved in this browser for your account.</p>
        <button id="resetPreferencesButton" type="button" class="settings-button">Reset to defaults</button>
    </div>
</div>
<script src="assets/js/preferences-page.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/preferences-page.js'); ?>"></script>
