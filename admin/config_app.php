<?php
// FILE: admin/config_app.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/metrics.php';
require_once __DIR__ . '/../app/login_recovery.php';

proteger_admin();
$pdo = getPDO();
metrics_ensure_schema($pdo);

$mensagemOk = '';
$mensagemErro = '';

// Carrega config atual (id=1)
try {
    $st = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
    $config = $st->fetch();
    if (!$config) {
        $pdo->exec("
            INSERT INTO app_config (id, course_title, primary_color, secondary_color, background_color, certificado_cta_label, paid_courses_title)
            VALUES (1, 'Trilha de Aulas', '#facc15', '#22c55e', '#020617', 'Emitir Certificado', 'Conheça nossos cursos pagos')
            ON DUPLICATE KEY UPDATE id = id
        ");
        $st = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
        $config = $st->fetch();
    }
} catch (Throwable $e) {
    $config = [
        'course_title'          => 'Trilha de Aulas',
        'primary_color'         => '#facc15',
        'secondary_color'       => '#22c55e',
        'background_color'      => '#020617',
        'logo_url'              => '',
        'certificado_cta_label' => 'Emitir Certificado',
        'paid_courses_title'    => 'Conheça nossos cursos pagos',
    ];
}

$formSection = (string)($_POST['form_section'] ?? 'visual');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formSection === 'metrics') {
    try {
        $integration = metrics_active_integration($pdo);
        $name = trim((string)($_POST['meta_name'] ?? 'Meta Principal')) ?: 'Meta Principal';
        $account = normalize_account_id((string)($_POST['meta_ad_account_id'] ?? ''));
        $appId = trim((string)($_POST['meta_app_id'] ?? ''));
        $appSecret = trim((string)($_POST['meta_app_secret'] ?? ''));
        $accessToken = trim((string)($_POST['meta_access_token'] ?? ''));
        $interval = max(5, min(1440, (int)($_POST['meta_sync_interval'] ?? 30)));
        if ($account === '') throw new RuntimeException('Informe o ID da conta de anuncios da Meta.');
        if ($integration) {
            $stmt = $pdo->prepare("UPDATE meta_integrations SET name=:name,app_id=:app_id,
                app_secret=CASE WHEN :app_secret='' THEN app_secret ELSE :app_secret END,
                access_token=CASE WHEN :access_token='' THEN access_token ELSE :access_token END,
                ad_account_id=:account,sync_interval_minutes=:sync_interval,status='active',updated_at=NOW() WHERE id=:id");
            $stmt->execute(['name'=>$name,'app_id'=>$appId?:null,'app_secret'=>$appSecret,'access_token'=>$accessToken,'account'=>$account,'sync_interval'=>$interval,'id'=>(int)$integration['id']]);
        } else {
            if ($accessToken === '') throw new RuntimeException('Informe o token de acesso da Meta na primeira configuracao.');
            $stmt = $pdo->prepare("INSERT INTO meta_integrations (name,app_id,app_secret,access_token,ad_account_id,status,sync_interval_minutes,timezone,created_at,updated_at) VALUES (:name,:app_id,:app_secret,:access_token,:account,'active',:sync_interval,'America/Sao_Paulo',NOW(),NOW())");
            $stmt->execute(['name'=>$name,'app_id'=>$appId?:null,'app_secret'=>$appSecret?:null,'access_token'=>$accessToken,'account'=>$account,'sync_interval'=>$interval]);
        }
        $hotmartToken = trim((string)($_POST['hotmart_hottok'] ?? ''));
        if ($hotmartToken !== '') set_setting('metrics_hotmart_hottok', $hotmartToken);
        set_setting('metrics_default_revenue_basis', in_array(($_POST['metrics_revenue_basis'] ?? ''), ['gross_revenue','net_revenue','producer_net'], true) ? $_POST['metrics_revenue_basis'] : 'producer_net');
        $mensagemOk = 'Integracoes de metricas salvas com sucesso.';
    } catch (Throwable $e) {
        $mensagemErro = 'Erro ao salvar integracoes: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formSection !== 'metrics') {
    $courseTitle      = trim((string)($_POST['course_title'] ?? ''));
    $primaryColor     = trim((string)($_POST['primary_color'] ?? ''));
    $secondaryColor   = trim((string)($_POST['secondary_color'] ?? ''));
    $backgroundColor  = trim((string)($_POST['background_color'] ?? ''));
    $logoUrl          = trim((string)($_POST['logo_url'] ?? ''));
    $certCtaLabel     = trim((string)($_POST['certificado_cta_label'] ?? ''));
    $paidCoursesTitle = trim((string)($_POST['paid_courses_title'] ?? ''));
    $loginIgnorePassword = !empty($_POST['login_ignore_password']) ? '1' : '0';

    if ($courseTitle === '') {
        $mensagemErro = 'Informe o título do curso.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE app_config
                SET course_title = :course_title,
                    primary_color = :primary_color,
                    secondary_color = :secondary_color,
                    background_color = :background_color,
                    logo_url = :logo_url,
                    certificado_cta_label = :cert_cta,
                    paid_courses_title = :paid_title,
                    updated_at = NOW()
                WHERE id = 1
            ");
            $stmt->execute([
                'course_title' => $courseTitle,
                'primary_color' => $primaryColor ?: '#facc15',
                'secondary_color' => $secondaryColor ?: '#22c55e',
                'background_color' => $backgroundColor ?: '#020617',
                'logo_url' => $logoUrl,
                'cert_cta' => $certCtaLabel ?: 'Emitir Certificado',
                'paid_title' => $paidCoursesTitle ?: 'Conheça nossos cursos pagos',
            ]);

            $mensagemOk = 'Configurações salvas com sucesso.';
            set_setting('login_ignore_password', $loginIgnorePassword);

            set_setting('login_recovery_enabled', !empty($_POST['login_recovery_enabled']) ? '1' : '0');
            if (isset($_POST['login_recovery_title'])) set_setting('login_recovery_title', trim((string)$_POST['login_recovery_title']));
            if (isset($_POST['login_recovery_subtitle'])) set_setting('login_recovery_subtitle', trim((string)$_POST['login_recovery_subtitle']));
            if (isset($_POST['login_recovery_btn_confirm'])) set_setting('login_recovery_btn_confirm', trim((string)$_POST['login_recovery_btn_confirm']));
            if (isset($_POST['login_recovery_btn_fix'])) set_setting('login_recovery_btn_fix', trim((string)$_POST['login_recovery_btn_fix']));
            if (isset($_POST['login_recovery_step2_title'])) set_setting('login_recovery_step2_title', trim((string)$_POST['login_recovery_step2_title']));
            if (isset($_POST['login_recovery_step2_desc'])) set_setting('login_recovery_step2_desc', trim((string)$_POST['login_recovery_step2_desc']));

            // Recarrega config
            $st = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
            $config = $st->fetch();
        } catch (Throwable $e) {
            $mensagemErro = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}

$metricsIntegration = metrics_active_integration($pdo) ?: [];
$metricsRevenueBasis = get_setting('metrics_default_revenue_basis', 'producer_net') ?: 'producer_net';
$hasHotmartToken = (get_setting('metrics_hotmart_hottok', '') ?: '') !== '';
$loginIgnorePassword = (int)(get_setting('login_ignore_password', '0') ?: '0') === 1;
$recConfig = login_recovery_get_config($pdo);

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$menu = 'config_app';
include __DIR__ . '/_header.php';
?>

<div style="max-width:860px">
    <?php if ($mensagemOk): ?>
        <div class="alert alert-ok mb-3"><?= h($mensagemOk) ?></div>
    <?php endif; ?>
    <?php if ($mensagemErro): ?>
        <div class="alert alert-error mb-3"><?= h($mensagemErro) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="post" action="">
            <input type="hidden" name="form_section" value="visual">
            <div class="section-label">Identidade visual</div>

            <div class="form-group">
                <label class="form-label" for="course_title">Título principal do curso (aparece na trilha)</label>
                <input type="text" id="course_title" name="course_title"
                       value="<?= h($config['course_title'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="logo_url">URL da logo (PNG / JPG)</label>
                <input type="text" id="logo_url" name="logo_url"
                       value="<?= h($config['logo_url'] ?? '') ?>">
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <div class="form-group" style="flex:1;min-width:180px">
                    <label class="form-label" for="primary_color">Cor primária (botões, destaques)</label>
                    <input type="text" id="primary_color" name="primary_color"
                           placeholder="#facc15"
                           value="<?= h($config['primary_color'] ?? '#facc15') ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:180px">
                    <label class="form-label" for="secondary_color">Cor de acento (progresso, tags)</label>
                    <input type="text" id="secondary_color" name="secondary_color"
                           placeholder="#22c55e"
                           value="<?= h($config['secondary_color'] ?? '#22c55e') ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:180px">
                    <label class="form-label" for="background_color">Cor de fundo da área de membros</label>
                    <input type="text" id="background_color" name="background_color"
                           placeholder="#020617"
                           value="<?= h($config['background_color'] ?? '#020617') ?>">
                </div>
            </div>

            <div class="section-label">Certificado &amp; Cursos pagos</div>

            <div class="form-group">
                <label class="form-label" for="certificado_cta_label">Texto do botão de certificado na trilha</label>
                <input type="text" id="certificado_cta_label" name="certificado_cta_label"
                       placeholder="Emitir Certificado"
                       value="<?= h($config['certificado_cta_label'] ?? 'Emitir Certificado') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="paid_courses_title">Título da seção de cursos pagos na trilha</label>
                <input type="text" id="paid_courses_title" name="paid_courses_title"
                       placeholder="Conheça nossos cursos pagos"
                       value="<?= h($config['paid_courses_title'] ?? 'Conheça nossos cursos pagos') ?>">
            </div>

            <div class="section-label">Login dos alunos</div>
            <label class="form-group" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--bg)">
                <input type="checkbox" name="login_ignore_password" value="1" <?= $loginIgnorePassword ? 'checked' : '' ?> style="margin-top:2px">
                <span>
                    <span style="display:block;font-size:13px;font-weight:700;color:var(--text)">Ignorar senha no login</span>
                    <span style="display:block;font-size:12px;color:var(--muted);line-height:1.45;margin-top:3px">Quando ativado, o aluno entra informando apenas um e-mail cadastrado. Alunos bloqueados continuam sem acesso.</span>
                </span>
            </label>

            <!-- SEÇÃO DE RECUPERAÇÃO DE LOGIN E AUTO-CADASTRO -->
            <div class="section-label" style="display:flex;align-items:center;justify-content:space-between;margin-top:24px;">
                <span>🛡️ Recuperação de Login &amp; Cadastro Automático (Modal Inteligente)</span>
            </div>
            
            <label class="form-group" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid rgba(250,204,21,0.3);border-radius:10px;background:rgba(250,204,21,0.04)">
                <input type="checkbox" name="login_recovery_enabled" value="1" <?= $recConfig['enabled'] ? 'checked' : '' ?> style="margin-top:2px">
                <span>
                    <span style="display:block;font-size:13px;font-weight:700;color:#facc15">Ativar Modal Inteligente de Recuperação quando o login falhar</span>
                    <span style="display:block;font-size:12px;color:var(--muted);line-height:1.45;margin-top:3px">
                        Exibe confirmação de e-mail em destaque e formulário de cadastro/liberção automática para alunos que erraram o e-mail ou não foram encontrados.
                    </span>
                </span>
            </label>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:12px;margin-top:12px">
                <div class="form-group">
                    <label class="form-label" for="login_recovery_title">Título (Passo 1)</label>
                    <input type="text" id="login_recovery_title" name="login_recovery_title" value="<?= h($recConfig['title']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="login_recovery_btn_confirm">Botão de Confirmação (Passo 1)</label>
                    <input type="text" id="login_recovery_btn_confirm" name="login_recovery_btn_confirm" value="<?= h($recConfig['btn_confirm']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="login_recovery_btn_fix">Botão de Erro (Passo 1)</label>
                    <input type="text" id="login_recovery_btn_fix" name="login_recovery_btn_fix" value="<?= h($recConfig['btn_fix']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="login_recovery_subtitle">Subtítulo (Passo 1)</label>
                <input type="text" id="login_recovery_subtitle" name="login_recovery_subtitle" value="<?= h($recConfig['subtitle']) ?>">
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:12px;margin-top:12px">
                <div class="form-group">
                    <label class="form-label" for="login_recovery_step2_title">Título (Passo 2)</label>
                    <input type="text" id="login_recovery_step2_title" name="login_recovery_step2_title" value="<?= h($recConfig['step2_title']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="login_recovery_step2_desc">Descrição (Passo 2)</label>
                    <input type="text" id="login_recovery_step2_desc" name="login_recovery_step2_desc" value="<?= h($recConfig['step2_desc']) ?>">
                </div>
            </div>

            <div style="background:rgba(15,23,42,0.6);border:1px solid var(--border);border-radius:10px;padding:14px;margin:16px 0 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <strong style="color:#f8fafc;font-size:13px;display:block;">🎮 Simulador Interativo do Modal</strong>
                    <span style="color:var(--muted);font-size:12px;">Testar a experiência do aluno diretamente na tela sem sair desta página.</span>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="button" class="btn btn-secondary" onclick="openLiveRecoverySimulator()" style="font-size:12px;">⚡ Abrir Simulador Aqui</button>
                    <a href="../public/login.php?demo_recovery=1" target="_blank" class="btn btn-secondary" style="font-size:12px;text-decoration:none;">🔗 Testar no Login Real</a>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Salvar configurações</button>
        </form>
    </div>

    <!-- MODAL SIMULADOR AO VIVO NO ADMIN -->
    <div id="adminRecoverySimulatorOverlay" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(2,6,15,0.88);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:16px;">
        <div style="position:relative;width:min(520px,100%);background:#0d1b33;border:1px solid rgba(250,204,21,0.4);border-radius:22px;padding:32px 26px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.85);color:#e2e8f0;font-family:'Inter',sans-serif;">
            <button type="button" onclick="closeLiveRecoverySimulator()" style="position:absolute;top:16px;right:16px;background:none;border:none;color:#94a3b8;font-size:20px;cursor:pointer;">✕</button>
            <div style="text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:#fde047;font-weight:700;margin-bottom:12px;">[ MODO SIMULADOR ]</div>
            
            <div id="simStep1">
                <div style="text-align:center;margin-bottom:16px;">
                    <h3 style="font-size:20px;font-weight:800;color:#fff;" id="simTitle1"><?= h($recConfig['title']) ?></h3>
                    <p style="font-size:13px;color:#94a3b8;" id="simSubtitle1"><?= h($recConfig['subtitle']) ?></p>
                </div>
                <div style="background:rgba(250,204,21,0.08);border:2px dashed rgba(250,204,21,0.5);border-radius:14px;padding:14px;text-align:center;margin:16px 0;">
                    <div style="font-size:11px;text-transform:uppercase;color:#fde047;font-weight:700;">E-mail digitado (Exemplo):</div>
                    <div style="font-size:20px;font-weight:800;color:#facc15;">aluno_teste@gmail.com</div>
                </div>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <button type="button" class="btn btn-primary" onclick="simGoStep(2)" id="simBtnConfirm"><?= h($recConfig['btn_confirm']) ?></button>
                    <button type="button" class="btn btn-secondary" onclick="closeLiveRecoverySimulator()" id="simBtnFix"><?= h($recConfig['btn_fix']) ?></button>
                </div>
            </div>

            <div id="simStep2" style="display:none;">
                <div style="text-align:center;margin-bottom:16px;">
                    <h3 style="font-size:20px;font-weight:800;color:#fff;" id="simTitle2"><?= h($recConfig['step2_title']) ?></h3>
                    <p style="font-size:13px;color:#94a3b8;" id="simSubtitle2"><?= h($recConfig['step2_desc']) ?></p>
                </div>
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <div>
                        <label style="font-size:12px;color:#cbd5e1;">Nome Completo</label>
                        <input type="text" value="Aluno de Teste" style="width:100%;padding:10px;border-radius:8px;border:1px solid #1e293b;background:#0f172a;color:#fff;" readonly>
                    </div>
                    <div>
                        <label style="font-size:12px;color:#cbd5e1;">WhatsApp com DDD</label>
                        <input type="text" value="(11) 98765-4321" style="width:100%;padding:10px;border-radius:8px;border:1px solid #1e293b;background:#0f172a;color:#fff;" readonly>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="alert('Simulação concluída com sucesso! No login real, o aluno seria cadastrado/atualizado e redirecionado para as aulas.')">Liberar Acesso e Assistir Aulas</button>
                    <button type="button" class="btn btn-secondary" onclick="simGoStep(1)">← Voltar ao Passo 1</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    function openLiveRecoverySimulator() {
        var overlay = document.getElementById('adminRecoverySimulatorOverlay');
        if (overlay) {
            simGoStep(1);
            overlay.style.display = 'flex';
        }
    }
    function closeLiveRecoverySimulator() {
        var overlay = document.getElementById('adminRecoverySimulatorOverlay');
        if (overlay) overlay.style.display = 'none';
    }
    function simGoStep(step) {
        document.getElementById('simStep1').style.display = step === 1 ? 'block' : 'none';
        document.getElementById('simStep2').style.display = step === 2 ? 'block' : 'none';
    }
    </script>

    <div class="card" style="margin-top:16px">
        <form method="post" action="" autocomplete="off">
            <input type="hidden" name="form_section" value="metrics">
            <div class="section-label">Métricas, Meta Ads e vendas</div>
            <div style="background:rgba(59,130,246,0.12);border:1px solid #3b82f6;border-radius:10px;padding:12px 16px;margin:10px 0 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div>
                    <strong style="color:#93c5fd;font-size:13px;display:block;">⚡ Gestão Centralizada de BMs da Meta Ads (Múltiplas BMs)</strong>
                    <span style="color:#cbd5e1;font-size:11px;">A configuração da Meta foi movida para a aba Integrações. Agora você pode cadastrar e gerenciar múltiplas Business Managers (BMs) e Contas de Anúncios de forma independente.</span>
                </div>
                <a href="integracoes.php?tab=meta" class="btn btn-primary btn-sm" style="white-space:nowrap;">👉 Abrir Integrações META</a>
            </div>
            <p style="font-size:12px;color:var(--muted);margin:0 0 16px">Credenciais usadas pela sincronizacao do painel de desempenho. Campos secretos em branco preservam o valor atual.</p>
            <div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:12px">
                <div class="form-group"><label class="form-label" for="meta_name">Nome da integracao</label><input type="text" id="meta_name" name="meta_name" value="<?= h((string)($metricsIntegration['name'] ?? 'Meta Principal')) ?>"></div>
                <div class="form-group"><label class="form-label" for="meta_ad_account_id">Conta de anuncios</label><input type="text" id="meta_ad_account_id" name="meta_ad_account_id" placeholder="act_123456789" value="<?= h((string)($metricsIntegration['ad_account_id'] ?? '')) ?>" required></div>
                <div class="form-group"><label class="form-label" for="meta_app_id">Meta App ID</label><input type="text" id="meta_app_id" name="meta_app_id" value="<?= h((string)($metricsIntegration['app_id'] ?? '')) ?>"></div>
                <div class="form-group"><label class="form-label" for="meta_sync_interval">Intervalo de sincronizacao (minutos)</label><input type="number" min="5" max="1440" id="meta_sync_interval" name="meta_sync_interval" value="<?= (int)($metricsIntegration['sync_interval_minutes'] ?? 30) ?>"></div>
                <div class="form-group"><label class="form-label" for="meta_app_secret">Meta App Secret</label><input type="password" id="meta_app_secret" name="meta_app_secret" placeholder="<?= !empty($metricsIntegration['app_secret']) ? 'Configurado - deixe vazio para manter' : 'Informe o App Secret' ?>"></div>
                <div class="form-group"><label class="form-label" for="meta_access_token">Meta Access Token</label><input type="password" id="meta_access_token" name="meta_access_token" placeholder="<?= !empty($metricsIntegration['access_token']) ? 'Configurado - deixe vazio para manter' : 'Informe o token' ?>"></div>
                <div class="form-group"><label class="form-label" for="hotmart_hottok">Hotmart HOTTOK</label><input type="password" id="hotmart_hottok" name="hotmart_hottok" placeholder="<?= $hasHotmartToken ? 'Configurado - deixe vazio para manter' : 'Token de validacao do webhook' ?>"></div>
                <div class="form-group"><label class="form-label" for="metrics_revenue_basis">Base padrao para o ROAS</label><select id="metrics_revenue_basis" name="metrics_revenue_basis"><option value="producer_net" <?= $metricsRevenueBasis==='producer_net'?'selected':'' ?>>Liquido do produtor</option><option value="net_revenue" <?= $metricsRevenueBasis==='net_revenue'?'selected':'' ?>>Receita liquida</option><option value="gross_revenue" <?= $metricsRevenueBasis==='gross_revenue'?'selected':'' ?>>Faturamento bruto</option></select></div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px"><button type="submit" class="btn btn-primary">Salvar integracoes</button><span style="font-size:11px;color:var(--muted)">Ultima sincronizacao: <?= !empty($metricsIntegration['last_success_sync_at']) ? h(date('d/m/Y H:i',strtotime((string)$metricsIntegration['last_success_sync_at']))) : 'ainda nao executada' ?></span></div>
        </form>
    </div>

    <div class="card" style="margin-top:4px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:12px">Pré-visualização rápida</div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border-radius:10px;background:var(--bg);border:1px dashed var(--border)">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:38px;height:38px;border-radius:999px;background:var(--bg-hover);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--primary);font-size:15px;flex-shrink:0">
                    <?php if (!empty($config['logo_url'])): ?>
                        <img src="<?= h($config['logo_url']) ?>" alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/></svg>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--text)"><?= h($config['course_title'] ?? '') ?></div>
                    <div style="font-size:11px;color:var(--muted)">Exemplo de como aparece para o aluno</div>
                </div>
            </div>
            <span style="padding:5px 12px;border-radius:999px;background:var(--primary);color:#111827;font-size:11px;font-weight:700;white-space:nowrap">
                <?= h($config['certificado_cta_label'] ?? 'Emitir Certificado') ?>
            </span>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
