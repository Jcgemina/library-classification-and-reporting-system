<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=user');
    exit;
}

requireLogin();
?>
<div class="bg-white rounded-2xl shadow-md p-8">
  <h2 class="text-2xl font-bold text-slate-900 mb-2">User Module</h2>
  <p class="text-slate-500">This is the user page placeholder. Add account management, librarian profiles, and access controls here.</p>
</div>
