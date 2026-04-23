    </main>

    <footer class="footer">
        <div class="container">
            <small>&copy; <?= date('Y') ?> <?= e($config['app']['name'] ?? 'Template') ?></small>
        </div>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(($config['app']['base_url'] ?? '') . '/assets/js/menu.js') ?>"></script>
<script src="<?= e(($config['app']['base_url'] ?? '') . '/assets/js/app.js') ?>"></script>
</body>
</html>