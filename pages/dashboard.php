<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=dashboard');
    exit;
}

requireLogin();
?>

<div class="min-h-[calc(100vh-5rem)] p-1 text-slate-900 sm:p-2">
  <div class="grid grid-cols-1 gap-4 xl:grid-cols-4">
    <div class="flex h-36 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
      <div class="flex h-9 w-9 items-center justify-center rounded-lg border border-rose-200 bg-rose-100 text-rose-600"><i data-lucide="book-open" class="h-5 w-5"></i></div>
      <div><p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-600">Total Titles</p><p class="mt-1 text-3xl font-bold leading-none text-slate-950">4</p></div>
    </div>
    <div class="flex h-36 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
      <div class="flex h-9 w-9 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-100 text-emerald-600"><i data-lucide="calendar-check" class="h-5 w-5"></i></div>
      <div><p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-600">Within 5 Yrs</p><p class="mt-1 text-3xl font-bold leading-none text-emerald-600">1</p></div>
    </div>
    <div class="flex h-36 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
      <div class="flex h-9 w-9 items-center justify-center rounded-lg border border-amber-200 bg-amber-100 text-amber-600"><i data-lucide="calendar-clock" class="h-5 w-5"></i></div>
      <div><p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-600">Within 10 Yrs</p><p class="mt-1 text-3xl font-bold leading-none text-amber-600">4</p></div>
    </div>
    <div class="flex h-36 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
      <div class="flex h-9 w-9 items-center justify-center rounded-lg border border-rose-200 bg-rose-100 text-rose-600"><i data-lucide="calendar-range" class="h-5 w-5"></i></div>
      <div><p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-600">Within 20 Yrs</p><p class="mt-1 text-3xl font-bold leading-none text-rose-700">4</p></div>
    </div>
  </div>

  <div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-[1.15fr_1.15fr_0.72fr]">
    <section class="min-h-[370px] rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
      <div class="flex items-center justify-between"><h2 class="text-base font-bold">Quarterly Progress</h2><i data-lucide="more-horizontal" class="h-5 w-5 text-slate-300"></i></div>
      <p class="mt-10 text-center text-sm italic text-slate-500">Loading progress...</p>
    </section>

    <section class="min-h-[370px] rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
      <h2 class="text-base font-bold">Upcoming Deadlines</h2>
    </section>

    <div class="space-y-4">
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
        <h2 class="text-base font-bold">Programs Overview</h2>
        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex items-center justify-between border-b border-slate-100 pb-2"><dt class="text-slate-600">Total Programs</dt><dd class="font-semibold text-slate-900">&mdash;</dd></div>
          <div class="flex items-center justify-between border-b border-slate-100 pb-2"><dt class="text-slate-600">Verified (any Q)</dt><dd class="font-semibold text-emerald-600">&mdash;</dd></div>
          <div class="flex items-center justify-between"><dt class="text-slate-600">Pending</dt><dd class="font-semibold text-rose-600">&mdash;</dd></div>
        </dl>
        <a href="app.php?page=organization" class="mt-5 flex items-center justify-center gap-1 border-t border-slate-100 pt-3 text-xs font-semibold text-rose-600"><i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>View Organization</a>
      </section>

      <section class="min-h-[150px] rounded-2xl bg-[#191b1c] p-5 text-white shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
        <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-sky-200">Current Quarter</p>
        <p class="mt-5 text-lg font-semibold">&mdash;</p>
        <p class="mt-2 text-sm text-slate-400">&mdash;</p>
        <div class="mt-4 h-1.5 rounded-full bg-slate-600"></div>
        <p class="mt-1 text-right text-xs text-sky-200">0% through quarter</p>
      </section>
    </div>
  </div>
</div>
