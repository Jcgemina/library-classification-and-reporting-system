<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=preferences');
    exit;
}

requireLogin();
?>

<div class="mx-auto w-full max-w-4xl space-y-6 p-1 text-slate-900 sm:p-2" data-preferences-page>
    <header>
        <h2 class="text-2xl font-bold text-slate-900">Preferences</h2>
        <p class="mt-1 text-sm leading-6 text-slate-600">Adjust how AppSys Library looks for you. These choices do not affect other users.</p>
    </header>

    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="appearanceHeading">
        <div class="border-b border-slate-200 pb-4">
            <h3 id="appearanceHeading" class="text-lg font-bold text-slate-900">Appearance</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">Choose a color theme that suits your workspace.</p>
        </div>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <label for="preferenceTheme" class="block text-sm font-semibold text-slate-700">
                Theme
                <select id="preferenceTheme" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                    <option value="system">Use device setting</option>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                </select>
                <span class="mt-1 block text-xs font-normal leading-5 text-slate-500">Device setting follows your operating system’s light or dark mode.</span>
            </label>
            <label for="preferenceDensity" class="block text-sm font-semibold text-slate-700">
                Table spacing
                <select id="preferenceDensity" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                    <option value="comfortable">Comfortable</option>
                    <option value="compact">Compact</option>
                </select>
                <span class="mt-1 block text-xs font-normal leading-5 text-slate-500">Compact spacing shows more rows at once; comfortable spacing gives records more room.</span>
            </label>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="readingHeading">
        <div class="border-b border-slate-200 pb-4">
            <h3 id="readingHeading" class="text-lg font-bold text-slate-900">Reading and lists</h3>
            <p class="mt-1 text-sm leading-6 text-slate-600">Set text size and how many records appear on each page in paginated lists.</p>
        </div>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <label for="preferenceTextSize" class="block text-sm font-semibold text-slate-700">
                Text size
                <select id="preferenceTextSize" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                    <option value="default">Default</option>
                    <option value="large">Larger</option>
                </select>
                <span class="mt-1 block text-xs font-normal leading-5 text-slate-500">Larger text improves readability throughout the app.</span>
            </label>
            <label for="preferenceRowsPerPage" class="block text-sm font-semibold text-slate-700">
                Rows per page
                <select id="preferenceRowsPerPage" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                    <option value="5">5 rows</option>
                    <option value="10">10 rows</option>
                    <option value="20">20 rows</option>
                    <option value="50">50 rows</option>
                </select>
                <span class="mt-1 block text-xs font-normal leading-5 text-slate-500">Applies to paginated account and audit-log lists.</span>
            </label>
        </div>
    </section>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p id="preferencesMessage" class="text-sm text-slate-600" role="status" aria-live="polite">Preferences are saved in this browser for your account.</p>
        <button id="resetPreferencesButton" type="button" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-200">Reset to defaults</button>
    </div>
</div>
<script src="assets/js/preferences-page.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/preferences-page.js'); ?>"></script>
