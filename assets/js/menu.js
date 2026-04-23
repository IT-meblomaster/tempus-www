document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-submenu-toggle="true"]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var parentLi = button.closest('.dropdown-submenu');
            if (!parentLi) {
                return;
            }

            var submenu = parentLi.querySelector(':scope > .dropdown-menu');
            if (!submenu) {
                return;
            }

            var parentMenu = parentLi.parentElement;
            if (parentMenu) {
                parentMenu.querySelectorAll(':scope > .dropdown-submenu > .dropdown-menu.show').forEach(function (openMenu) {
                    if (openMenu !== submenu) {
                        openMenu.classList.remove('show');
                        var openToggle = openMenu.parentElement.querySelector(':scope > [data-submenu-toggle="true"]');
                        if (openToggle) {
                            openToggle.setAttribute('aria-expanded', 'false');
                        }
                    }
                });
            }

            var isOpen = submenu.classList.contains('show');
            submenu.classList.toggle('show', !isOpen);
            button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
    });

    document.querySelectorAll('.dropdown').forEach(function (dropdownEl) {
        dropdownEl.addEventListener('hidden.bs.dropdown', function () {
            dropdownEl.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
                menu.classList.remove('show');
            });

            dropdownEl.querySelectorAll('[data-submenu-toggle="true"]').forEach(function (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            });
        });
    });

    document.addEventListener('click', function () {
        document.querySelectorAll('.dropdown-submenu > .dropdown-menu.show').forEach(function (menu) {
            menu.classList.remove('show');
        });

        document.querySelectorAll('[data-submenu-toggle="true"]').forEach(function (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        });
    });
});
