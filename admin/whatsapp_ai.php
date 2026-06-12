<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/whatsapp_ai.php';

proteger_admin();
$pdo = getPDO();
evolution_ensure_tables($pdo);
whatsapp_ai_ensure_tables($pdo);

$menu = 'whatsapp_ai';
$page_title = 'IA WhatsApp';

function wai_h(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wai_dt(?string $v): string {
    if (!$v) return '-';
    try { return (new DateTime($v))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return $v; }
}

function wai_badge(string $text, string $kind = 'neutral'): string {
    $map = [
        'success' => 'background:rgba(34,197,94,.12);color:#86efac;border-color:rgba(34,197,94,.25)',
        'warn' => 'background:rgba(250,204,21,.12);color:#fde68a;border-color:rgba(250,204,21,.25)',
        'danger' => 'background:rgba(239,68,68,.12);color:#fca5a5;border-color:rgba(239,68,68,.25)',
        'info' => 'background:rgba(59,130,246,.12);color:#93c5fd;border-color:rgba(59,130,246,.25)',
        'neutral' => 'background:rgba(148,163,184,.10);color:#cbd5e1;border-color:rgba(148,163,184,.20)',
    ];
    return '<span style="display:inline-flex;align-items:center;padding:3px 8px;border:1px solid;border-radius:999px;font-size:11px;font-weight:700;' . $map[$kind] . '">' . wai_h($text) . '</span>';
}

$notice = '';
$error = '';
$runResult = null;

$generatedCronToken = '';
try {
    if (trim((string)get_setting('cron_whatsapp_ai_token', '')) === '') {
        $generatedCronToken = bin2hex(random_bytes(24));
        set_setting('cron_whatsapp_ai_token', $generatedCronToken);
    }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_config') {
            $current = whatsapp_ai_get_config();
            $postedKey = trim((string)($_POST['openai_api_key'] ?? ''));
            $clearKey = !empty($_POST['clear_openai_api_key']);
            whatsapp_ai_set_config([
                'enabled' => !empty($_POST['enabled']),
                'openai_api_key' => $clearKey ? '' : ($postedKey !== '' ? $postedKey : (string)$current['openai_api_key']),
                'model' => $_POST['model'] ?? '',
                'interval_minutes' => $_POST['interval_minutes'] ?? 5,
                'active_from' => $_POST['active_from'] ?? '08:00',
                'active_to' => $_POST['active_to'] ?? '22:00',
                'max_tokens' => $_POST['max_tokens'] ?? 800,
                'max_messages' => $_POST['max_messages'] ?? 80,
                'context_keep' => $_POST['context_keep'] ?? 6,
                'temperature' => $_POST['temperature'] ?? 0.2,
                'prompt' => $_POST['prompt'] ?? '',
                'criteria' => $_POST['criteria'] ?? '',
            ]);
            header('Location: whatsapp_ai.php?saved=1');
            exit;
        }

        if ($action === 'toggle_group_ignore') {
            $gid = trim((string)($_POST['group_id'] ?? ''));
            if ($gid === '') throw new RuntimeException('Grupo invalido.');
            $pdo->prepare("UPDATE whatsapp_groups SET is_ignored = IF(is_ignored=1,0,1), last_seen_at = NOW() WHERE group_id = :gid LIMIT 1")
                ->execute([':gid' => $gid]);
            header('Location: whatsapp_ai.php?groups=1');
            exit;
        }

        if ($action === 'rotate_token') {
            set_setting('cron_whatsapp_ai_token', bin2hex(random_bytes(24)));
            header('Location: whatsapp_ai.php?token=1');
            exit;
        }

        if ($action === 'run_now') {
            $runResult = whatsapp_ai_process_due($pdo, 10);
            $notice = 'Processamento executado: ' . (int)$runResult['batches_created'] . ' pacote(s), ' . (int)$runResult['messages_processed'] . ' mensagem(ns).';
            if (!empty($runResult['skipped'])) $notice = 'Processamento ignorado: IA desligada ou fora do horario configurado.';
            if (!empty($runResult['error'])) $error = (string)$runResult['error'];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved'])) $notice = 'Configuracao da IA salva.';
if (isset($_GET['groups'])) $notice = 'Configuracao do grupo atualizada.';
if (isset($_GET['token'])) $notice = 'Token do cron atualizado.';

$cfg = whatsapp_ai_get_config();
$cronToken = $generatedCronToken !== '' ? $generatedCronToken : trim((string)get_setting('cron_whatsapp_ai_token', ''));
$cronUrl = rtrim(BASE_URL, '/') . '/cron_whatsapp_ai.php?token=' . $cronToken;

$stats = [
    'pending' => 0,
    'batches' => 0,
    'interventions' => 0,
    'contexts' => 0,
];
try { $stats['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_messages WHERE processed_batch_id IS NULL")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['batches'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_batches")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['interventions'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_batches WHERE needs_intervention = 1")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['contexts'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_contexts")->fetchColumn(); } catch (Throwable $e) {}

$groups = [];
try {
    $groups = $pdo->query("
        SELECT g.*,
               (SELECT COUNT(*) FROM whatsapp_ai_messages m WHERE m.group_id = g.group_id) AS ai_messages,
               (SELECT COUNT(*) FROM whatsapp_ai_messages m WHERE m.group_id = g.group_id AND m.processed_batch_id IS NULL) AS pending_messages,
               (SELECT MAX(message_at) FROM whatsapp_ai_messages m WHERE m.group_id = g.group_id) AS last_message_at
          FROM whatsapp_groups g
         ORDER BY g.is_ignored ASC, COALESCE(g.group_name, g.group_id) ASC
         LIMIT 120
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$batches = [];
try {
    $batches = $pdo->query("
        SELECT b.*, g.group_name
          FROM whatsapp_ai_batches b
          LEFT JOIN whatsapp_groups g ON g.group_id = b.group_id
         ORDER BY b.created_at DESC, b.id DESC
         LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$messages = [];
try {
    $messages = $pdo->query("
        SELECT m.*, u.nome AS aluno_nome, g.group_name
          FROM whatsapp_ai_messages m
          LEFT JOIN users u ON u.id = m.user_id
          LEFT JOIN whatsapp_groups g ON g.group_id = m.group_id
         ORDER BY m.message_at DESC, m.id DESC
         LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

include __DIR__ . '/_header.php';
?>

<style>
.wai-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.wai-kpi{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:14px}
.wai-kpi-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;font-weight:700}
.wai-kpi-value{font-size:24px;color:var(--text);font-weight:800;margin-top:6px}
.wai-layout{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);gap:16px;align-items:start}
.wai-card{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px}
.wai-title{font-size:13px;font-weight:800;color:var(--text);margin-bottom:12px;text-transform:uppercase;letter-spacing:.06em}
.wai-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.wai-help{font-size:11px;color:var(--muted);margin-top:5px;line-height:1.45}
.wai-table{width:100%;border-collapse:collapse}
.wai-table th,.wai-table td{padding:9px 8px;border-bottom:1px solid var(--border);font-size:12px;text-align:left;vertical-align:top}
.wai-table th{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.wai-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:#93c5fd;word-break:break-all}
.wai-msg{max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--muted)}
@media(max-width:1100px){.wai-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wai-layout{grid-template-columns:1fr}.wai-row{grid-template-columns:1fr}}
</style>

<?php if ($notice): ?><div class="alert alert-ok mb-3"><?= wai_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error mb-3"><?= wai_h($error) ?></div><?php endif; ?>

<div class="wai-grid">
    <div class="wai-kpi"><div class="wai-kpi-label">Mensagens pendentes</div><div class="wai-kpi-value"><?= (int)$stats['pending'] ?></div></div>
    <div class="wai-kpi"><div class="wai-kpi-label">Pacotes analisados</div><div class="wai-kpi-value"><?= (int)$stats['batches'] ?></div></div>
    <div class="wai-kpi"><div class="wai-kpi-label">Intervencoes sugeridas</div><div class="wai-kpi-value"><?= (int)$stats['interventions'] ?></div></div>
    <div class="wai-kpi"><div class="wai-kpi-label">Contextos salvos</div><div class="wai-kpi-value"><?= (int)$stats['contexts'] ?></div></div>
</div>

<div class="wai-layout">
    <div>
        <div class="wai-card">
            <div class="wai-title">Configuracao da IA</div>
            <form method="post">
                <input type="hidden" name="action" value="save_config">
                <div class="form-group">
                    <label class="form-label"><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> Ativar analise por IA</label>
                    <div class="wai-help">Nesta fase a IA apenas analisa, resume e sugere. Ela nao envia mensagens nem dispara automacoes.</div>
                </div>

                <div class="wai-row">
                    <div class="form-group">
                        <label class="form-label">Modelo</label>
                        <input type="text" name="model" value="<?= wai_h((string)$cfg['model']) ?>" placeholder="gpt-4.1-mini">
                    </div>
                    <div class="form-group">
                        <label class="form-label">API key OpenAI</label>
                        <input type="password" name="openai_api_key" value="" placeholder="<?= $cfg['openai_api_key'] !== '' ? 'Chave configurada - deixe vazio para manter' : 'sk-...' ?>">
                        <?php if ($cfg['openai_api_key'] !== ''): ?>
                        <label class="wai-help"><input type="checkbox" name="clear_openai_api_key" value="1"> Remover chave salva</label>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="wai-row">
                    <div class="form-group">
                        <label class="form-label">Intervalo de empacotamento em minutos</label>
                        <input type="number" min="1" max="120" name="interval_minutes" value="<?= (int)$cfg['interval_minutes'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Maximo de mensagens por pacote</label>
                        <input type="number" min="1" max="300" name="max_messages" value="<?= (int)$cfg['max_messages'] ?>">
                    </div>
                </div>

                <div class="wai-row">
                    <div class="form-group">
                        <label class="form-label">Horario de ligar</label>
                        <input type="time" name="active_from" value="<?= wai_h((string)$cfg['active_from']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Horario de desligar</label>
                        <input type="time" name="active_to" value="<?= wai_h((string)$cfg['active_to']) ?>">
                    </div>
                </div>

                <div class="wai-row">
                    <div class="form-group">
                        <label class="form-label">Maximo de tokens de saida</label>
                        <input type="number" min="100" max="4000" name="max_tokens" value="<?= (int)$cfg['max_tokens'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contextos anteriores por grupo</label>
                        <input type="number" min="0" max="50" name="context_keep" value="<?= (int)$cfg['context_keep'] ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Temperatura</label>
                    <input type="number" min="0" max="2" step="0.1" name="temperature" value="<?= wai_h((string)$cfg['temperature']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Prompt orientativo</label>
                    <textarea name="prompt" rows="8"><?= wai_h((string)$cfg['prompt']) ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Criterios adicionais</label>
                    <textarea name="criteria" rows="6" placeholder="Ex: responder duvidas tecnicas de eletrica, sinalizar interesse de compra, alertar baixo calao..."><?= wai_h((string)$cfg['criteria']) ?></textarea>
                </div>

                <button class="btn btn-primary" type="submit">Salvar configuracao</button>
            </form>
        </div>

        <div class="wai-card">
            <div class="wai-title">Cron</div>
            <div class="wai-help" style="margin-bottom:8px">Configure este endpoint no cron do servidor, idealmente a cada minuto. A propria configuracao da IA decide se ja passou o intervalo de empacotamento.</div>
            <div class="wai-code"><?= wai_h($cronUrl) ?></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <form method="post"><input type="hidden" name="action" value="run_now"><button class="btn" type="submit">Processar agora</button></form>
                <form method="post" onsubmit="return confirm('Gerar novo token do cron? O cron configurado no servidor precisara ser atualizado.')"><input type="hidden" name="action" value="rotate_token"><button class="btn btn-ghost" type="submit">Gerar novo token</button></form>
            </div>
        </div>

        <div class="wai-card">
            <div class="wai-title">Ultimos pacotes analisados</div>
            <div style="overflow-x:auto">
                <table class="wai-table">
                    <thead><tr><th>Data</th><th>Grupo</th><th>Status</th><th>Categoria</th><th>Resumo</th></tr></thead>
                    <tbody>
                    <?php if (!$batches): ?>
                    <tr><td colspan="5" style="color:var(--muted);text-align:center;padding:18px">Nenhum pacote analisado ainda.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($batches as $b): ?>
                    <tr>
                        <td><?= wai_dt((string)$b['created_at']) ?></td>
                        <td><?= wai_h((string)($b['group_name'] ?: $b['group_id'])) ?><div class="wai-code"><?= wai_h((string)$b['group_id']) ?></div></td>
                        <td>
                            <?php
                            $status = (string)$b['status'];
                            echo wai_badge($status, $status === 'done' ? (!empty($b['needs_intervention']) ? 'warn' : 'success') : ($status === 'error' ? 'danger' : 'info'));
                            ?>
                            <div class="wai-help"><?= (int)$b['message_count'] ?> msg</div>
                        </td>
                        <td><?= wai_h((string)($b['category'] ?: '-')) ?><div class="wai-help"><?= wai_h((string)($b['severity'] ?: '')) ?></div></td>
                        <td>
                            <div style="max-width:520px;color:var(--text)"><?= wai_h((string)($b['summary'] ?: $b['error_message'] ?: '-')) ?></div>
                            <?php if (!empty($b['suggested_response'])): ?><div class="wai-help">Sugestao: <?= wai_h((string)$b['suggested_response']) ?></div><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div>
        <div class="wai-card">
            <div class="wai-title">Grupos monitorados</div>
            <div style="overflow-x:auto;max-height:520px">
                <table class="wai-table">
                    <thead><tr><th>Grupo</th><th>Mensagens</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$groups): ?>
                    <tr><td colspan="4" style="color:var(--muted);text-align:center;padding:18px">Nenhum grupo sincronizado ainda.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($groups as $g): ?>
                    <tr>
                        <td>
                            <strong><?= wai_h((string)($g['group_name'] ?: $g['group_id'])) ?></strong>
                            <div class="wai-code"><?= wai_h((string)$g['group_id']) ?></div>
                            <div class="wai-help">Ultima msg: <?= wai_dt((string)($g['last_message_at'] ?? '')) ?></div>
                        </td>
                        <td><?= (int)$g['ai_messages'] ?><div class="wai-help"><?= (int)$g['pending_messages'] ?> pendentes</div></td>
                        <td><?= ((int)$g['is_ignored'] === 1) ? wai_badge('Ignorado', 'neutral') : wai_badge('Ativo', 'success') ?></td>
                        <td style="text-align:right">
                            <form method="post">
                                <input type="hidden" name="action" value="toggle_group_ignore">
                                <input type="hidden" name="group_id" value="<?= wai_h((string)$g['group_id']) ?>">
                                <button class="btn btn-ghost btn-xs" type="submit"><?= ((int)$g['is_ignored'] === 1) ? 'Ativar' : 'Ignorar' ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="wai-card">
            <div class="wai-title">Ultimas mensagens capturadas</div>
            <div style="overflow-x:auto;max-height:520px">
                <table class="wai-table">
                    <thead><tr><th>Data</th><th>Autor</th><th>Mensagem</th></tr></thead>
                    <tbody>
                    <?php if (!$messages): ?>
                    <tr><td colspan="3" style="color:var(--muted);text-align:center;padding:18px">Nenhuma mensagem capturada ainda.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($messages as $m): ?>
                    <tr>
                        <td><?= wai_dt((string)$m['message_at']) ?></td>
                        <td>
                            <?= wai_h((string)($m['aluno_nome'] ?: $m['sender_name'] ?: 'Participante')) ?>
                            <div class="wai-help"><?= wai_h((string)($m['sender_phone'] ?: $m['sender_id'] ?: '')) ?></div>
                        </td>
                        <td>
                            <div class="wai-msg"><?= wai_h((string)$m['message_text']) ?></div>
                            <div class="wai-help"><?= wai_h((string)($m['group_name'] ?: $m['group_id'])) ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
