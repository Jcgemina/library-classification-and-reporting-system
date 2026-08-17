<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=dashboard');
    exit;
}

requireLogin();
?>

<div class="space-y-6">

  <!-- Header -->
  <div>
    <h2 class="text-2xl font-bold text-slate-900">Dashboard</h2>
    <p class="text-sm text-slate-500 mt-1">
      Overview of library books and quarterly progress.
    </p>
  </div>


  <!-- Total Books -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-md p-6">
    <div class="flex items-center justify-between">
      <div>
        <p class="text-sm font-medium text-slate-500">Total Books</p>
        <h3 class="text-4xl font-bold text-slate-900 mt-2">1,248</h3>
        <p class="text-xs text-slate-400 mt-1">Registered in the library</p>
      </div>

      <div class="w-12 h-12 rounded-xl bg-rose-100 text-rose-700 border border-rose-200 flex items-center justify-center">
        <i data-lucide="book-open" class="w-6 h-6"></i>
      </div>
    </div>
  </div>


  <!-- Copyright Overview -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-md p-6">

    <div class="flex items-center justify-between mb-5">
      <div>
        <h3 class="text-lg font-bold text-slate-900">Copyright Overview</h3>
        <p class="text-sm text-slate-500">
          Distribution of books based on copyright age.
        </p>
      </div>

      <i data-lucide="calendar-clock" class="w-5 h-5 text-slate-400"></i>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

      <!-- 5 Years -->
      <div class="rounded-xl bg-slate-50 border border-slate-200 p-5">
        <div class="flex items-center justify-between">
          <span class="text-sm font-medium text-slate-500">
            Within 5 Years
          </span>
          <span class="text-lg font-bold text-slate-900">486</span>
        </div>

        <div class="mt-4 h-2 bg-slate-200 rounded-full overflow-hidden">
          <div class="h-full bg-rose-500 rounded-full" style="width: 39%;"></div>
        </div>

        <p class="text-xs text-slate-400 mt-2">39% of total books</p>
      </div>


      <!-- 10 Years -->
      <div class="rounded-xl bg-slate-50 border border-slate-200 p-5">
        <div class="flex items-center justify-between">
          <span class="text-sm font-medium text-slate-500">
            Within 10 Years
          </span>
          <span class="text-lg font-bold text-slate-900">392</span>
        </div>

        <div class="mt-4 h-2 bg-slate-200 rounded-full overflow-hidden">
          <div class="h-full bg-rose-400 rounded-full" style="width: 31%;"></div>
        </div>

        <p class="text-xs text-slate-400 mt-2">31% of total books</p>
      </div>


      <!-- 20 Years -->
      <div class="rounded-xl bg-slate-50 border border-slate-200 p-5">
        <div class="flex items-center justify-between">
          <span class="text-sm font-medium text-slate-500">
            20+ Years
          </span>
          <span class="text-lg font-bold text-slate-900">370</span>
        </div>

        <div class="mt-4 h-2 bg-slate-200 rounded-full overflow-hidden">
          <div class="h-full bg-slate-500 rounded-full" style="width: 30%;"></div>
        </div>

        <p class="text-xs text-slate-400 mt-2">30% of total books</p>
      </div>

    </div>
  </div>


  <!-- Quarterly Progress -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-md p-6">

    <div class="flex items-center justify-between mb-6">
      <div>
        <h3 class="text-lg font-bold text-slate-900">Quarterly Progress</h3>
        <p class="text-sm text-slate-500">
          Progress of quarterly library activities.
        </p>
      </div>

      <i data-lucide="chart-no-axes-column-increasing" class="w-5 h-5 text-slate-400"></i>
    </div>


    <div class="space-y-5">

      <!-- Q1 -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-semibold text-slate-700">Q1</span>
          <span class="text-xs font-medium text-slate-500">100%</span>
        </div>

        <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full bg-rose-500 rounded-full" style="width: 100%;"></div>
        </div>
      </div>


      <!-- Q2 -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-semibold text-slate-700">Q2</span>
          <span class="text-xs font-medium text-slate-500">75%</span>
        </div>

        <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full bg-rose-500 rounded-full" style="width: 75%;"></div>
        </div>
      </div>


      <!-- Q3 -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-semibold text-slate-700">Q3</span>
          <span class="text-xs font-medium text-slate-500">45%</span>
        </div>

        <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full bg-rose-500 rounded-full" style="width: 45%;"></div>
        </div>
      </div>


      <!-- Q4 -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-semibold text-slate-700">Q4</span>
          <span class="text-xs font-medium text-slate-500">20%</span>
        </div>

        <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
          <div class="h-full bg-rose-500 rounded-full" style="width: 20%;"></div>
        </div>
      </div>

    </div>
  </div>


  <!-- Quarterly Deadlines -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-md p-6">

    <div class="flex items-center justify-between mb-5">
      <div>
        <h3 class="text-lg font-bold text-slate-900">Quarterly Deadlines</h3>
        <p class="text-sm text-slate-500">
          Scheduled deadlines for each quarter.
        </p>
      </div>

      <i data-lucide="calendar-days" class="w-5 h-5 text-slate-400"></i>
    </div>


    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

      <!-- Q1 -->
      <div class="border border-slate-200 rounded-xl p-4">
        <div class="flex items-center justify-between">
          <span class="text-sm font-bold text-slate-900">Q1</span>
          <span class="text-xs px-2 py-1 rounded-full bg-emerald-100 text-emerald-700">
            Completed
          </span>
        </div>

        <p class="text-sm text-slate-500 mt-3">March 31, 2026</p>
      </div>


      <!-- Q2 -->
      <div class="border border-slate-200 rounded-xl p-4">
        <div class="flex items-center justify-between">
          <span class="text-sm font-bold text-slate-900">Q2</span>
          <span class="text-xs px-2 py-1 rounded-full bg-emerald-100 text-emerald-700">
            Completed
          </span>
        </div>

        <p class="text-sm text-slate-500 mt-3">June 30, 2026</p>
      </div>


      <!-- Q3 -->
      <div class="border border-slate-200 rounded-xl p-4">
        <div class="flex items-center justify-between">
          <span class="text-sm font-bold text-slate-900">Q3</span>
          <span class="text-xs px-2 py-1 rounded-full bg-amber-100 text-amber-700">
            In Progress
          </span>
        </div>

        <p class="text-sm text-slate-500 mt-3">September 30, 2026</p>
      </div>


      <!-- Q4 -->
      <div class="border border-slate-200 rounded-xl p-4">
        <div class="flex items-center justify-between">
          <span class="text-sm font-bold text-slate-900">Q4</span>
          <span class="text-xs px-2 py-1 rounded-full bg-slate-100 text-slate-600">
            Upcoming
          </span>
        </div>

        <p class="text-sm text-slate-500 mt-3">December 31, 2026</p>
      </div>

    </div>
  </div>

</div>