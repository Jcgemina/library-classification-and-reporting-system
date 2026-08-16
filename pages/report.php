<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=report');
    exit;
}

requireLogin();
?>
<div class="bg-white rounded-2xl shadow-md p-8">
  <h2 class="text-2xl font-bold text-slate-900 mb-2">Reports Module</h2>
  <p class="text-slate-500">This is the report page placeholder. Add summary statistics, charts, and exported reports here.</p>
</div>
