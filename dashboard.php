<?php
session_start();
require_once __DIR__ . '/includes/functions.php';

// --- Non-functional: Authentication access protection ---
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AppSys Library - Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50">

  <nav class="bg-white shadow px-6 py-4 flex items-center justify-between">
    <h1 class="text-lg font-bold text-slate-900">AppSys Library</h1>
    <div class="flex items-center gap-4 text-sm">
      <span class="text-slate-600">
        Hi, <?php echo htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8'); ?>
        <span class="text-slate-400">(<?php echo htmlspecialchars($_SESSION['role'], ENT_QUOTES, 'UTF-8'); ?>)</span>
      </span>
      <a href="logout.php" class="bg-slate-900 hover:bg-slate-800 text-white px-4 py-2 rounded-lg">Logout</a>
    </div>
  </nav>

  <main class="max-w-4xl mx-auto mt-10 p-6">
    <div class="bg-white rounded-2xl shadow-md p-8">
      <h2 class="text-2xl font-bold text-slate-900 mb-2">Welcome to the Librarian Management Portal</h2>
      <p class="text-slate-500">You have successfully logged in. This is the main system page — Week 2+ modules will build on top of this.</p>
    </div>
  </main>

</body>
</html>
