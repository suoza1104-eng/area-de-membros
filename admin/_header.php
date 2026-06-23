<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

// Sessões criadas antes do sistema de equipe não têm admin_tipo → força novo login
if (!isset($_SESSION['admin_tipo'])) {
    session_destroy();
    header('Location: ' . BASE_URL_ADMIN . '/index.php');
    exit;
}

$currentMenu = $menu ?? 'dashboard';

// ─── Equipe permission gate ────────────────────────────────────────────────
$__isEquipe    = ($_SESSION['admin_tipo'] === 'equipe');
$__equipePerms = $__isEquipe
    ? (json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [])
    : [];
if ($__isEquipe && empty($__equipePerms['whatsapp_config']) && !empty($__equipePerms['whatsapp_ai'])) {
    $__equipePerms['whatsapp_config'] = $__equipePerms['whatsapp_ai'];
}
if ($__isEquipe && empty($__equipePerms['cron_monitor']) && !empty($__equipePerms['logs'])) {
    $__equipePerms['cron_monitor'] = $__equipePerms['logs'];
}

// Dashboard é sempre acessível (evita loop de redirect pós-login)
if ($__isEquipe && $currentMenu !== 'dashboard') {
    if (empty($__equipePerms[$currentMenu]['acesso'])) {
        header('Location: ' . BASE_URL_ADMIN . '/index.php?sem_acesso=1');
        exit;
    }
}

// $podeEscrever: as páginas podem usar para esconder botões de edição
$podeEscrever = !$__isEquipe || !empty($__equipePerms[$currentMenu]['escrever']);

// Visibilidade dos itens do sidebar
$__sbV = [];
foreach (['dashboard','vendas_analytics','vendas_vitalicio','alunos','retorno_agendamentos','reagendamentos_live','aulas','turmas','cursos','certificado',
          'webhooks','superfuncionario','manychat','disparos','live_events','inbound_webhooks','whatsapp_config','whatsapp_monitor','whatsapp_ai','monitor','cron_monitor','logs','aparencia','config_app','equipe'] as $__k) {
    $__sbV[$__k] = !$__isEquipe || !empty($__equipePerms[$__k]['acesso']) || $__k === 'dashboard';
}

// Informações do usuário logado para o sidebar
$__sbNome    = $__isEquipe ? ($_SESSION['equipe_nome']  ?? 'Membro') : 'Administrador';
$__sbRole    = $__isEquipe ? 'Equipe' : 'Admin logado';
$__sbInitial = strtoupper(substr($__sbNome, 0, 1));
$__appVersion = defined('APP_VERSION') ? APP_VERSION : 'V1';
// ──────────────────────────────────────────────────────────────────────────

$titleMap = [
    'dashboard'        => 'Dashboard',
    'vendas_analytics' => 'Analise de Vendas',
    'vendas_vitalicio' => 'Vendas Vitalicio',
    'alunos'           => 'Alunos',
    'retorno_agendamentos' => 'Agendamentos de Retorno',
    'reagendamentos_live' => 'Reagendamentos de Live',
    'aulas'            => 'Aulas',
    'turmas'           => 'Turmas',
    'cursos'           => 'Cursos Recomendados',
    'certificado'      => 'Certificado',
    'webhooks'         => 'Webhooks',
    'manychat'         => 'Manychat',
    'superfuncionario' => 'SuperFuncionário',
    'whatsapp_config'  => 'Configurações WhatsApp',
    'whatsapp_monitor' => 'WhatsApp Monitor',
    'whatsapp_ai'      => 'IA WhatsApp',
    'monitor'          => 'Rastreamento',
    'cron_monitor'     => 'Monitor de Cron',
    'logs'             => 'Logs',
    'aparencia'        => 'Aparência',
    'config_app'       => 'Configurações',
    'pontuacao'        => 'Pontuação',
];
$pageTitle = $page_title ?? ($titleMap[$currentMenu] ?? 'Admin');

try {
    $__pdo = getPDO();
    $__cfg = $__pdo->query("SELECT course_title, logo_url FROM app_config WHERE id = 1 LIMIT 1")->fetch();
    $__courseTitle = $__cfg['course_title'] ?? 'Área de Membros';
    $__logoUrl     = $__cfg['logo_url'] ?? '';
} catch (Throwable $e) {
    $__courseTitle = 'Área de Membros';
    $__logoUrl     = '';
}

function __esc(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title><?= __esc($pageTitle) ?> — Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-chart-funnel@4"></script>
<style>
/* ===== EXTENDED DESIGN SYSTEM ===== */
:root {
  --bg:             #080e1a;
  --bg-card:        #0d1526;
  --bg-sidebar:     #060c18;
  --bg-hover:       rgba(255,255,255,.05);
  --border:         rgba(255,255,255,.08);
  --border-light:   rgba(255,255,255,.12);
  --primary:        #facc15;
  --primary-dim:    rgba(250,204,21,.1);
  --primary-soft:   rgba(250,204,21,.18);
  --text:           #e2e8f0;
  --muted:          #64748b;
  --dim:            #334155;
  --success:        #22c55e;
  --success-dim:    rgba(34,197,94,.12);
  --danger:         #ef4444;
  --danger-dim:     rgba(239,68,68,.12);
  --info:           #38bdf8;
  --info-dim:       rgba(56,189,248,.12);
  --warning:        #f59e0b;
  --warning-dim:    rgba(245,158,11,.12);
  --sidebar-w:      252px;
  --topbar-h:       60px;
  --r:              10px;
  --r-lg:           14px;
  --r-xl:           18px;
  --r-full:         999px;
  --shadow:         0 4px 20px rgba(0,0,0,.45);
  --shadow-lg:      0 10px 40px rgba(0,0,0,.55);
  --font:           'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --t:              .15s ease;
}

/* ===== RESET ===== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { height: 100%; scroll-behavior: smooth; }
body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  font-size: calc(14px * var(--font-scale, 100) / 100);
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
  overflow-x: hidden;
}
a { color: var(--primary); text-decoration: none; }
a:hover { text-decoration: underline; }
button { font-family: var(--font); cursor: pointer; border: none; }
img { max-width: 100%; display: block; }

::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: var(--r-full); }
::-webkit-scrollbar-thumb:hover { background: var(--dim); }

/* ===== LAYOUT ===== */
#layout { display: flex; min-height: 100vh; }

/* ===== SIDEBAR ===== */
#sidebar {
  width: var(--sidebar-w);
  min-height: 100vh;
  background: var(--bg-sidebar);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0; left: 0; bottom: 0;
  z-index: 200;
  transition: transform var(--t);
  overflow-y: auto;
  overflow-x: hidden;
}
.sb-logo {
  padding: 16px 14px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.sb-logo-img {
  width: 34px; height: 34px;
  border-radius: 8px;
  background: var(--bg-hover);
  border: 1px solid var(--border-light);
  overflow: hidden;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  color: var(--primary);
}
.sb-logo-img img { width: 100%; height: 100%; object-fit: contain; }
.sb-logo-info { flex: 1; min-width: 0; }
.sb-logo-name {
  font-size: 13px; font-weight: 700;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  color: var(--text);
}
.sb-logo-badge {
  font-size: 9px; font-weight: 600;
  text-transform: uppercase; letter-spacing: .06em;
  color: var(--muted);
  background: var(--bg-hover);
  padding: 1px 6px; border-radius: var(--r-full);
  border: 1px solid var(--border);
  margin-top: 2px; display: inline-block;
}
.sb-logo-version {
  color: var(--primary);
  border-color: rgba(250,204,21,.28);
  background: rgba(250,204,21,.08);
  margin-left: 4px;
}

.sb-nav {
  flex: 1;
  padding: 8px 8px 4px;
  overflow-y: auto;
}
.sb-section {
  font-size: 9.5px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em;
  color: var(--dim);
  padding: 14px 8px 4px;
}
.sb-item {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 10px;
  border-radius: var(--r);
  color: var(--muted);
  font-size: 13px; font-weight: 450;
  transition: background var(--t), color var(--t);
  text-decoration: none !important;
  position: relative;
  margin-bottom: 1px;
}
.sb-item:hover { background: var(--bg-hover); color: var(--text); }
.sb-item.active {
  background: var(--primary-dim);
  color: var(--primary);
  font-weight: 600;
}
.sb-item.active::before {
  content: '';
  position: absolute; left: 0; top: 50%;
  transform: translateY(-50%);
  width: 3px; height: 55%;
  background: var(--primary);
  border-radius: 0 3px 3px 0;
}
.sb-icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .65; }
.sb-item.active .sb-icon { opacity: 1; }
.sb-item:hover .sb-icon { opacity: .85; }

.sb-footer {
  border-top: 1px solid var(--border);
  padding: 10px 8px;
  flex-shrink: 0;
}
.sb-user {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 10px; border-radius: var(--r);
  background: var(--bg-hover);
}
.sb-avatar {
  width: 28px; height: 28px;
  border-radius: var(--r-full);
  background: var(--primary-dim);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; color: var(--primary);
  flex-shrink: 0;
}
.sb-user-info { flex: 1; min-width: 0; }
.sb-user-name { font-size: 12px; font-weight: 600; color: var(--text); }
.sb-user-role { font-size: 10px; color: var(--muted); }
.sb-logout {
  color: var(--muted); transition: color var(--t);
  display: flex; align-items: center;
}
.sb-logout:hover { color: var(--danger); }
.sb-logout svg { width: 15px; height: 15px; }

/* ===== MAIN WRAPPER ===== */
#main-wrapper {
  margin-left: var(--sidebar-w);
  flex: 1; display: flex; flex-direction: column; min-height: 100vh;
  overflow-x: hidden; min-width: 0;
}

/* ===== TOPBAR ===== */
#topbar {
  height: var(--topbar-h);
  background: var(--bg);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 20px;
  position: sticky; top: 0; z-index: 100;
  gap: 12px;
}
#topbar-left { display: flex; align-items: center; gap: 10px; }
.tb-hamburger {
  display: none;
  width: 34px; height: 34px;
  border: 1px solid var(--border);
  background: transparent;
  border-radius: var(--r);
  align-items: center; justify-content: center;
  color: var(--muted);
  transition: background var(--t), color var(--t);
}
.tb-hamburger:hover { background: var(--bg-hover); color: var(--text); }
.tb-hamburger svg { width: 17px; height: 17px; }
.tb-title { font-size: 15px; font-weight: 600; color: var(--text); }

#topbar-right { display: flex; align-items: center; gap: 8px; }
.tb-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 12px; border-radius: var(--r-full);
  font-size: 12px; font-weight: 500;
  color: var(--muted);
  border: 1px solid var(--border);
  background: transparent;
  transition: background var(--t), color var(--t), border-color var(--t);
  cursor: pointer; text-decoration: none !important;
}
.tb-btn:hover { background: var(--bg-hover); color: var(--text); border-color: var(--border-light); }
.tb-btn svg { width: 13px; height: 13px; }

/* ===== PAGE CONTENT ===== */
#page-content { flex: 1; padding: 20px 22px 32px; }

/* ===== OVERLAY ===== */
#sb-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.65); z-index: 199;
  backdrop-filter: blur(3px);
}
#sb-overlay.show { display: block; }

/* ===== CARDS ===== */
.card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 16px 18px;
  margin-bottom: 16px;
  box-shadow: var(--shadow);
}
.card-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 14px;
}
.card-header-title {
  font-size: 14px; font-weight: 600;
  display: flex; align-items: center; gap: 8px;
}

/* ===== KPI CARDS ===== */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 14px; margin-bottom: 18px;
}
.kpi {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 16px 18px;
  position: relative; overflow: hidden;
  box-shadow: var(--shadow);
}
.kpi::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
}
.kpi.kpi-y::after  { background: var(--primary); }
.kpi.kpi-g::after  { background: var(--success); }
.kpi.kpi-b::after  { background: var(--info); }
.kpi.kpi-o::after  { background: var(--warning); }
.kpi.kpi-r::after  { background: var(--danger); }
.kpi-icon {
  width: 34px; height: 34px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 10px;
}
.kpi-icon svg { width: 17px; height: 17px; }
.kpi-icon.y { background: var(--primary-dim); color: var(--primary); }
.kpi-icon.g { background: var(--success-dim); color: var(--success); }
.kpi-icon.b { background: var(--info-dim);    color: var(--info); }
.kpi-icon.o { background: var(--warning-dim); color: var(--warning); }
.kpi-icon.r { background: var(--danger-dim);  color: var(--danger); }
.kpi-label { font-size: 11px; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
.kpi-value { font-size: 26px; font-weight: 700; color: var(--text); line-height: 1.1; }
.kpi-sub   { font-size: 11px; color: var(--muted); margin-top: 3px; }

/* ===== GRID ===== */
.grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
.grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }

/* ===== PANEL (charts) ===== */
.panel {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 16px 18px;
  margin-bottom: 16px;
  box-shadow: var(--shadow);
}
.panel-title {
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  color: var(--muted); margin-bottom: 14px;
  display: flex; align-items: center; justify-content: space-between;
}
.panel canvas { width: 100% !important; max-height: 230px; }

/* ===== TABLE ===== */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th {
  padding: 8px 12px; text-align: left;
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--muted);
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
tbody td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  color: var(--text); vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: var(--bg-hover); }

/* ===== FORMS ===== */
.form-group { margin-bottom: 14px; }
.form-label {
  display: block; font-size: 12px; font-weight: 500;
  color: var(--muted); margin-bottom: 5px;
}
input[type="text"], input[type="email"], input[type="password"],
input[type="date"], input[type="number"], input[type="url"],
input[type="time"], select, textarea {
  width: 100%; padding: 8px 12px;
  border-radius: var(--r);
  border: 1px solid var(--border-light);
  background: var(--bg); color: var(--text);
  font-size: 13px; font-family: var(--font);
  transition: border-color var(--t), box-shadow var(--t);
  outline: none;
}
input:focus, select:focus, textarea:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-soft);
}
input::placeholder, textarea::placeholder { color: var(--dim); }
select { appearance: none; cursor: pointer; }
textarea { resize: vertical; min-height: 80px; }
input[type="checkbox"], input[type="radio"] { width: auto; }

/* ===== BUTTONS ===== */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 8px 16px; border-radius: var(--r-full);
  font-size: 13px; font-weight: 600; font-family: var(--font);
  border: none; cursor: pointer;
  transition: filter var(--t), opacity var(--t);
  white-space: nowrap; text-decoration: none !important;
}
.btn:hover { filter: brightness(1.07); text-decoration: none; }
.btn:active { filter: brightness(.94); }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn svg { width: 14px; height: 14px; }

.btn-primary { background: var(--primary); color: #111827; }
.btn-success { background: var(--success); color: #fff; }
.btn-danger  { background: var(--danger);  color: #fff; }
.btn-info    { background: var(--info);    color: #0c1a2e; }
.btn-ghost   { background: transparent; color: var(--muted); border: 1px solid var(--border-light); }
.btn-ghost:hover { background: var(--bg-hover); color: var(--text); border-color: var(--border-light); filter: none; }
.btn-sm   { padding: 5px 12px; font-size: 12px; }
.btn-xs   { padding: 3px 8px;  font-size: 11px; }
.btn-icon { padding: 7px; border-radius: var(--r); }

/* Legacy button (pages that use plain <button>) */
button:not([class]) {
  padding: 8px 16px; border-radius: var(--r-full);
  background: var(--primary); color: #111827;
  font-weight: 600; font-size: 13px; font-family: var(--font);
  border: none; cursor: pointer;
  transition: filter var(--t);
}
button:not([class]):hover { filter: brightness(1.07); }

/* ===== BADGES ===== */
.badge {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 2px 8px; border-radius: var(--r-full);
  font-size: 11px; font-weight: 500;
}
.badge-success { background: rgba(34,197,94,.12);  color: #86efac; }
.badge-danger  { background: rgba(239,68,68,.1);   color: #fca5a5; }
.badge-info    { background: rgba(14,165,233,.1);   color: #7dd3fc; }
.badge-warning { background: rgba(245,158,11,.1);   color: #fcd34d; }
.badge-neutral { background: rgba(100,116,139,.1);  color: #94a3b8; }
.badge-primary { background: var(--primary-dim);    color: var(--primary); }

/* ===== ALERTS ===== */
.alert {
  padding: 10px 14px; border-radius: var(--r);
  font-size: 13px; margin-bottom: 14px;
}
.alert-ok    { background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.22); color: #86efac; }
.alert-error { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.22); color: #fca5a5; }
.alert-info  { background: rgba(14,165,233,.08);border: 1px solid rgba(14,165,233,.22); color: #7dd3fc; }

/* ===== FILTER BAR ===== */
.filter-bar {
  display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;
  margin-bottom: 16px;
  padding: 14px 16px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  box-shadow: var(--shadow);
}
.filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 130px; }
.filter-group label {
  font-size: 10.5px; font-weight: 600;
  text-transform: uppercase; letter-spacing: .06em;
  color: var(--muted);
}
.filter-group input, .filter-group select { padding: 6px 10px; font-size: 12px; }
.filter-actions { display: flex; align-items: center; gap: 8px; padding-top: 14px; }
.filter-actions .reset-link {
  font-size: 11px; color: var(--dim); text-decoration: underline !important; cursor: pointer;
}

/* Legacy topbar used by some pages */
.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; flex-wrap: wrap; gap: 10px; }
.topbar-title { font-size: 16px; font-weight: 700; }
.topbar-right { font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 8px; }

/* ===== SECTION TITLE ===== */
.section-label {
  font-size: 10.5px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--muted); margin: 20px 0 10px;
  display: flex; align-items: center; gap: 8px;
}
.section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* ===== UTILITIES ===== */
.text-muted    { color: var(--muted) !important; }
.text-success  { color: var(--success) !important; }
.text-danger   { color: var(--danger) !important; }
.text-primary  { color: var(--primary) !important; }
.text-sm       { font-size: 12px; }
.text-xs       { font-size: 11px; }
.fw-600        { font-weight: 600; }
.fw-700        { font-weight: 700; }
.d-flex        { display: flex; }
.align-center  { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-2  { gap: 8px; }
.gap-3  { gap: 12px; }
.flex-1 { flex: 1; }
.mt-2   { margin-top: 8px; }
.mt-3   { margin-top: 12px; }
.mt-4   { margin-top: 16px; }
.mb-3   { margin-bottom: 12px; }
.mb-4   { margin-bottom: 16px; }
.code {
  font-family: monospace; font-size: 12px;
  background: rgba(255,255,255,.06); padding: 1px 6px;
  border-radius: 5px; border: 1px solid var(--border);
  color: var(--info);
}
.truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ===== RESPONSIVE ===== */
@media (max-width: 900px) {
  #sidebar { transform: translateX(-100%); }
  #sidebar.open { transform: translateX(0); box-shadow: var(--shadow-lg); }
  #main-wrapper { margin-left: 0; }
  .tb-hamburger { display: flex; }
  #page-content { padding: 14px 14px 24px; }
  .kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .grid-2, .grid-3 { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
  .kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .filter-bar { flex-direction: column; }
  .filter-group { min-width: 100%; }
}
</style>
<!-- Theme overrides from DB (runs after defaults so it wins the cascade) -->
<style><?= theme_inline_css_vars(); ?></style>
</head>
<body>

<div id="sb-overlay" onclick="closeSidebar()"></div>

<div id="layout">

<!-- ===== SIDEBAR ===== -->
<aside id="sidebar">
  <div class="sb-logo">
    <div class="sb-logo-img">
      <?php if ($__logoUrl): ?>
        <img src="<?= __esc($__logoUrl) ?>" alt="Logo">
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:17px;height:17px">
          <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
          <path d="M6 12v5c3 3 9 3 12 0v-5"/>
        </svg>
      <?php endif; ?>
    </div>
    <div class="sb-logo-info">
      <div class="sb-logo-name"><?= __esc($__courseTitle) ?></div>
      <span class="sb-logo-badge">Admin</span>
      <span class="sb-logo-badge sb-logo-version"><?= __esc($__appVersion) ?></span>
    </div>
  </div>

  <nav class="sb-nav">
    <?php if ($__sbV['dashboard'] || $__sbV['vendas_analytics'] || $__sbV['vendas_vitalicio'] || $__sbV['alunos'] || $__sbV['retorno_agendamentos'] || $__sbV['reagendamentos_live'] || $__sbV['aulas'] || $__sbV['turmas']): ?>
    <div class="sb-section">Geral</div>
    <?php endif; ?>

    <?php if ($__sbV['dashboard']): ?>
    <a href="index.php" class="sb-item <?= $currentMenu === 'dashboard' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="3" width="7" height="7" rx="1.5"/>
        <rect x="14" y="3" width="7" height="7" rx="1.5"/>
        <rect x="14" y="14" width="7" height="7" rx="1.5"/>
        <rect x="3" y="14" width="7" height="7" rx="1.5"/>
      </svg>
      Dashboard
    </a>
    <?php endif; ?>

    <?php if ($__sbV['vendas_analytics']): ?>
    <a href="vendas_analytics.php" class="sb-item <?= $currentMenu === 'vendas_analytics' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="12" y1="20" x2="12" y2="10"/>
        <line x1="18" y1="20" x2="18" y2="4"/>
        <line x1="6" y1="20" x2="6" y2="16"/>
      </svg>
      Vendas
    </a>
    <?php endif; ?>

    <?php if ($__sbV['vendas_vitalicio']): ?>
    <a href="vendas_vitalicio.php" class="sb-item <?= $currentMenu === 'vendas_vitalicio' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20 7h-9"/>
        <path d="M14 17H5"/>
        <circle cx="17" cy="17" r="3"/>
        <circle cx="7" cy="7" r="3"/>
      </svg>
      Vendas Vitalicio
    </a>
    <?php endif; ?>

    <?php if ($__sbV['alunos']): ?>
    <a href="alunos.php" class="sb-item <?= $currentMenu === 'alunos' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
      </svg>
      Alunos
    </a>
    <?php endif; ?>

    <?php if ($__sbV['retorno_agendamentos']): ?>
    <a href="retorno_agendamentos.php" class="sb-item <?= $currentMenu === 'retorno_agendamentos' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <polyline points="12 6 12 12 16 14"/>
      </svg>
      Agendamentos
    </a>
    <?php endif; ?>

    <?php if ($__sbV['reagendamentos_live']): ?>
    <a href="reagendamentos_live.php" class="sb-item <?= $currentMenu === 'reagendamentos_live' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2"/>
        <path d="M16 2v4M8 2v4M3 10h18"/>
        <path d="M17 14l-3 3-2-2"/>
      </svg>
      Reagend. Live
    </a>
    <?php endif; ?>

    <?php if ($__sbV['aulas']): ?>
    <a href="aulas.php" class="sb-item <?= $currentMenu === 'aulas' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <polygon points="10 8 16 12 10 16 10 8"/>
      </svg>
      Aulas
    </a>
    <?php endif; ?>

    <?php if ($__sbV['turmas']): ?>
    <a href="turmas.php" class="sb-item <?= $currentMenu === 'turmas' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2"/>
        <line x1="16" y1="2" x2="16" y2="6"/>
        <line x1="8" y1="2" x2="8" y2="6"/>
        <line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
      Turmas
    </a>
    <?php endif; ?>

    <?php if ($__sbV['cursos'] || $__sbV['certificado']): ?>
    <div class="sb-section">Conteúdo</div>
    <?php endif; ?>

    <?php if ($__sbV['cursos']): ?>
    <a href="cursos_recomendados.php" class="sb-item <?= $currentMenu === 'cursos' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M4 19.5A2.5 2.5 0 016.5 17H20"/>
        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/>
      </svg>
      Cursos Recom.
    </a>
    <?php endif; ?>

    <?php if ($__sbV['certificado']): ?>
    <a href="certificado_config.php" class="sb-item <?= $currentMenu === 'certificado' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="8" r="6"/>
        <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
      </svg>
      Certificado
    </a>
    <?php endif; ?>

    <?php if ($__sbV['webhooks'] || $__sbV['superfuncionario'] || $__sbV['manychat'] || $__sbV['disparos'] || $__sbV['live_events'] || $__sbV['inbound_webhooks'] || $__sbV['whatsapp_config'] || $__sbV['whatsapp_monitor'] || $__sbV['whatsapp_ai']): ?>
    <div class="sb-section">Integrações</div>
    <?php endif; ?>

    <?php if ($__sbV['webhooks']): ?>
    <a href="webhooks.php" class="sb-item <?= $currentMenu === 'webhooks' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
      </svg>
      Webhooks
    </a>
    <?php endif; ?>

    <?php if ($__sbV['superfuncionario']): ?>
    <a href="superfuncionario.php" class="sb-item <?= $currentMenu === 'superfuncionario' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M4.93 19.07l1.41-1.41M19.07 19.07l-1.41-1.41M2 12h2M20 12h2M12 2v2M12 20v2"/>
      </svg>
      SuperFuncionário
    </a>
    <?php endif; ?>

    <?php if ($__sbV['manychat']): ?>
    <a href="manychat.php" class="sb-item <?= $currentMenu === 'manychat' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15a4 4 0 01-4 4H8l-5 3V7a4 4 0 014-4h10a4 4 0 014 4z"/>
        <path d="M8 9h8M8 13h5"/>
      </svg>
      Manychat
    </a>
    <?php endif; ?>

    <?php if ($__sbV['whatsapp_config']): ?>
    <a href="whatsapp_config.php" class="sb-item <?= $currentMenu === 'whatsapp_config' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M2 12h3M19 12h3M4.93 19.07l2.12-2.12M16.95 7.05l2.12-2.12"/>
      </svg>
      Configurações WhatsApp
    </a>
    <?php endif; ?>

    <?php if ($__sbV['whatsapp_monitor']): ?>
    <a href="whatsapp_monitor.php" class="sb-item <?= $currentMenu === 'whatsapp_monitor' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M5 4h14a2 2 0 012 2v10a2 2 0 01-2 2H8l-5 3V6a2 2 0 012-2z"/>
        <path d="M8 9h8M8 13h5"/>
      </svg>
      WhatsApp Monitor
    </a>
    <?php endif; ?>

    <?php if ($__sbV['whatsapp_ai']): ?>
    <a href="whatsapp_ai.php" class="sb-item <?= $currentMenu === 'whatsapp_ai' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 2a7 7 0 017 7c0 4-3 6-7 6s-7-2-7-6a7 7 0 017-7z"/>
        <path d="M8 21h8"/>
        <path d="M9 15v3M15 15v3"/>
        <path d="M9 9h.01M15 9h.01"/>
      </svg>
      IA WhatsApp
    </a>
    <?php endif; ?>

    <?php if ($__sbV['disparos']): ?>
    <a href="disparos.php" class="sb-item <?= $currentMenu === 'disparos' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="22" y1="2" x2="11" y2="13"/>
        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
      </svg>
      Disparos
    </a>
    <?php endif; ?>

    <?php if ($__sbV['live_events']): ?>
    <a href="live_events.php" class="sb-item <?= $currentMenu === 'live_events' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="3"/>
        <circle cx="12" cy="12" r="9"/>
      </svg>
      Eventos Live
    </a>
    <?php endif; ?>

    <?php if ($__sbV['inbound_webhooks']): ?>
    <a href="inbound_webhooks.php" class="sb-item <?= $currentMenu === 'inbound_webhooks' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
        <polyline points="17 8 12 3 7 8"/>
        <line x1="12" y1="3" x2="12" y2="15"/>
      </svg>
      Entrada (Webhooks)
    </a>
    <?php endif; ?>

    <?php if ($__sbV['monitor'] || $__sbV['cron_monitor'] || $__sbV['logs'] || $__sbV['aparencia'] || $__sbV['config_app'] || $__sbV['equipe']): ?>
    <div class="sb-section">Sistema</div>
    <?php endif; ?>

    <?php if ($__sbV['monitor']): ?>
    <a href="monitor_inscricoes.php" class="sb-item <?= $currentMenu === 'monitor' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
      </svg>
      Rastreamento
    </a>
    <?php endif; ?>

    <?php if ($__sbV['cron_monitor']): ?>
    <a href="cron_monitor.php" class="sb-item <?= $currentMenu === 'cron_monitor' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="9"/>
        <path d="M12 7v5l3 2"/>
        <path d="M4 4l2 2M20 4l-2 2"/>
      </svg>
      Monitor de Cron
    </a>
    <?php endif; ?>

    <?php if ($__sbV['logs']): ?>
    <a href="logs.php" class="sb-item <?= $currentMenu === 'logs' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
        <polyline points="10 9 9 9 8 9"/>
      </svg>
      Logs
    </a>
    <?php endif; ?>

    <?php if ($__sbV['aparencia']): ?>
    <a href="settings_aparencia.php" class="sb-item <?= $currentMenu === 'aparencia' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="3"/>
        <path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/>
      </svg>
      Aparência
    </a>
    <?php endif; ?>

    <?php if ($__sbV['config_app']): ?>
    <a href="config_app.php" class="sb-item <?= $currentMenu === 'config_app' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
      </svg>
      Configurações
    </a>
    <?php endif; ?>

    <?php if ($__sbV['equipe']): ?>
    <a href="equipe.php" class="sb-item <?= $currentMenu === 'equipe' ? 'active' : '' ?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
      </svg>
      Equipe
    </a>
    <?php endif; ?>
  </nav>

  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-avatar"><?= __esc($__sbInitial) ?></div>
      <div class="sb-user-info">
        <div class="sb-user-name"><?= __esc($__sbNome) ?></div>
        <div class="sb-user-role"><?= __esc($__sbRole) ?></div>
      </div>
      <a href="index.php?logout=1" class="sb-logout" title="Sair">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </a>
    </div>
  </div>
</aside>

<!-- ===== MAIN ===== -->
<div id="main-wrapper">
  <header id="topbar">
    <div id="topbar-left">
      <button class="tb-hamburger" onclick="toggleSidebar()" type="button" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="3" y1="8" x2="21" y2="8"/>
          <line x1="3" y1="16" x2="21" y2="16"/>
        </svg>
      </button>
      <span class="tb-title"><?= __esc($pageTitle) ?></span>
    </div>
    <div id="topbar-right">
      <a href="../public/trilha.php" class="tb-btn" target="_blank">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
          <polyline points="15 3 21 3 21 9"/>
          <line x1="10" y1="14" x2="21" y2="3"/>
        </svg>
        Área do aluno
      </a>
    </div>
  </header>

  <div id="page-content">
