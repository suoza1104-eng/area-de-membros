<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';

unset($_SESSION['aluno_id']);
session_write_close();
header('Location: ' . BASE_URL . '/login.php');
exit;
