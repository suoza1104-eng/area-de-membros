<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

/**
 * Cada página admin deve definir antes de incluir este header:
 *   $menu = 'dashboard' | 'alunos' | 'aulas' | 'turmas' | 'cursos' |
 *           'certificado' | 'webhooks' | 'superfuncionario' | 'monitor' | 'logs' | 'config_app' | 'aparencia';
 *
 * Opcionalmente, a página pode definir:
 *   $page_title = 'Título';
 *
 * Exemplo em alunos.php:
 *   $menu = 'alunos';
 *   include __DIR__ . '/_header.php';
 */
$currentMenu = $menu ?? 'dashboard';

// Título do topo (não altera estilo, só o texto)
$__titleMap = [
    'dashboard'   => 'Dashboard',
    'alunos'      => 'Alunos',
    'aulas'       => 'Aulas',
    'turmas'      => 'Turmas',
    'cursos'      => 'Cursos recomendados',
    'certificado' => 'Certificado',
    'webhooks'    => 'Webhooks',
    'superfuncionario' => 'SuperFuncionário',
    'monitor'     => 'Rastreamento inscrições',
    'logs'        => 'Logs',
    'aparencia'   => 'Aparência',
];

$pageTitle = $page_title ?? ($__titleMap[$currentMenu] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Admin - Área de Membros</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        <?= theme_inline_css_vars(); ?>
        *{box-sizing:border-box;}
        body{
            margin:0;
            font-family:Arial,sans-serif;
            background:var(--bg-main);
            color:var(--text-main);
            font-size: calc(14px * var(--font-scale, 100) / 100);
        }
        a{color:var(--primary);text-decoration:none;}
        .layout{
            display:flex;
            min-height:100vh;
        }
        .sidebar{
            width:220px;
            background:#020617;
            padding:16px 12px;
            border-right:1px solid #111827;
        }
        .sidebar h2{
            font-size:16px;
            margin-top:0;
            margin-bottom:12px;
            color:#e5e7eb;
        }
        .menu{
            display:flex;
            flex-direction:column;
            gap:4px;
        }
        .menu a{
            display:block;
            padding:8px 10px;
            border-radius:8px;
            color:#e5e7eb;
            font-size:0.95rem;
            background:transparent;
            transition:background .15s ease, color .15s ease, transform .05s ease;
        }
        .menu a:hover{
            background:#111827;
            transform:translateX(2px);
        }
        .menu a.menu-active{
            background:var(--primary);
            color:#111827;
            font-weight:bold;
        }

        .content{
            flex:1;
            padding:16px;
        }
        .topbar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:16px;
        }
        .card{
            background:var(--bg-card);
            border-radius:12px;
            padding:12px 14px;
            border:1px solid #1f2937;
            margin-bottom:16px;
        }
        table{width:100%;border-collapse:collapse;font-size:0.95rem;}
        th,td{padding:6px 8px;border-bottom:1px solid #1f2937;text-align:left;}
        th{font-weight:bold;color:#e5e7eb;}
        input,select,textarea{
            padding:6px 8px;
            border-radius:6px;
            border:1px solid #374151;
            background:#020617;
            color:var(--text-main);
            font-size:0.95rem;
        }
        button{
            padding:7px 12px;
            border-radius:999px;
            border:none;
            background:var(--primary);
            color:#111827;
            font-weight:bold;
            font-size:0.95rem;
            cursor:pointer;
        }
        button:hover{background:#eab308;}
        .btn-sm{padding:4px 10px;font-size:0.85rem;}
        .badge{
            display:inline-block;
            padding:2px 6px;
            border-radius:999px;
            font-size:0.78rem;
            background:#111827;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <h2>Admin</h2>
        <div class="menu">
            <a href="index.php"
               class="<?= $currentMenu === 'dashboard' ? 'menu-active' : '' ?>">
                Dashboard
            </a>

            <a href="alunos.php"
               class="<?= $currentMenu === 'alunos' ? 'menu-active' : '' ?>">
                Alunos
            </a>

            <a href="aulas.php"
               class="<?= $currentMenu === 'aulas' ? 'menu-active' : '' ?>">
                Aulas
            </a>

            <a href="turmas.php"
               class="<?= $currentMenu === 'turmas' ? 'menu-active' : '' ?>">
                Turmas
            </a>

            <a href="cursos_recomendados.php"
               class="<?= $currentMenu === 'cursos' ? 'menu-active' : '' ?>">
                Cursos recomendados
            </a>

            <a href="certificado_config.php"
               class="<?= $currentMenu === 'certificado' ? 'menu-active' : '' ?>">
                Certificado
            </a>

            <a href="webhooks.php"
               class="<?= $currentMenu === 'webhooks' ? 'menu-active' : '' ?>">
                Webhooks
            </a>

            <a href="superfuncionario.php"
               class="<?= $currentMenu === 'superfuncionario' ? 'menu-active' : '' ?>">
                SuperFuncionário
            </a>

            <a href="logs.php"
               class="<?= $currentMenu === 'logs' ? 'menu-active' : '' ?>">
                Logs
            </a>

            <a href="monitor_inscricoes.php"
               class="<?= $currentMenu === 'monitor' ? 'menu-active' : '' ?>">
                Rastreamento inscrições
            </a>


            <a href="settings_aparencia.php"
               class="<?= $currentMenu === 'aparencia' ? 'menu-active' : '' ?>">
                Aparência
            </a>
        </div>
    </aside>
    <main class="content">
        <div class="topbar">
            <div><strong><?= htmlspecialchars((string)$pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong></div>
            <div>Admin logado | <a href="../public/logout.php">sair (aluno)</a></div>
        </div>
