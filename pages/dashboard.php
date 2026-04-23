<?php
$user = current_user($pdo);
?>
<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h3 mb-3">Dashboard</h1>
                <p class="mb-0">Witaj, <?= e($user['username'] ?? '') ?>. To jest podstawowy panel startowy dla nowego serwisu.</p>
            </div>
        </div>
    </div>
</div>
