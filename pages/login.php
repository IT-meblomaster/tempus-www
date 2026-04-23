<?php
declare(strict_types=1);

if (is_logged_in()) {
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        set_flash('danger', 'Nieprawidłowy token formularza.');
        redirect('index.php?page=login');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        set_flash('warning', 'Podaj login i hasło.');
        redirect('index.php?page=login');
    }

    if (login($pdo, $username, $password)) {
        regenerate_csrf_token();
        set_flash('success', 'Zalogowano poprawnie.');
        redirect('index.php?page=dashboard');
    }

    set_flash('danger', 'Nieprawidłowy login lub hasło.');
    redirect('index.php?page=login');
}
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Logowanie</strong></div>
            <div class="card-body">
                <form method="post" action="index.php?page=login" autocomplete="off">
                    <?= csrf_input() ?>

                    <div class="mb-3">
                        <label for="username" class="form-label">Login</label>
                        <input type="text" class="form-control" id="username" name="username" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Hasło</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Zaloguj</button>
                </form>
            </div>
        </div>
    </div>
</div>