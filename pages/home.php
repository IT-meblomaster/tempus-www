<div class="p-5 mb-4 bg-light rounded-3 border">
    <div class="container-fluid py-4">
        <h1 class="display-6">Template WWW</h1>
        <p class="lead mb-3">Bazowy szkielet aplikacji z logowaniem, użytkownikami, rolami i uprawnieniami do podstron.</p>
        <?php if (!is_logged_in()): ?>
            <a href="index.php?page=login" class="btn btn-primary btn-lg">Zaloguj</a>
        <?php else: ?>
            <a href="index.php?page=dashboard" class="btn btn-primary btn-lg">Przejdź do dashboardu</a>
        <?php endif; ?>
    </div>
</div>
