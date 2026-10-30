<?php
require __DIR__ . '/../app/bootstrap.php';
if (current_user()) {
    audit('logout', 'usuario', (int)current_user()['id']);
}
logout();
redirect('admin/login.php');
