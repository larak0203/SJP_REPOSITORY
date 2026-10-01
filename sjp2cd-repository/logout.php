<?php
require_once __DIR__ . '/config/config.php';
signOut($pdo);
flash('ok', 'You are signed out.');
redirect('login.php');
