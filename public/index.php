<?php require_once __DIR__.'/../app/bootstrap.php'; redirect(current_user() ? 'dashboard.php' : 'login.php');
