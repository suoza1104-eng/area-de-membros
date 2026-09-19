<?php
declare(strict_types=1);

require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/funcoes.php';
require __DIR__ . '/../app/certificados_individuais.php';

proteger_admin();
$menu = 'certificado';
$pdo = getPDO();
ci_ensure_schema($pdo);

function ci_h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$msg = '';
$err = '';
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

try {
    if (isset($_GET['preview'])) {
        $tpl = ci_find_template($pdo, (int)$_GET['preview']);
        if (!$tpl) throw new RuntimeException('Certificado nao encontrado.');
        $pdf = ci_generate_pdf($pdo, $tpl, [
            'id' => 0,
            'template_id' => (int)$tpl['id'],
            'public_token' => 'PREVIEW-' . date('YmdHis'),
            'full_name' => 'Aluno de Teste',
            'email' => 'aluno.teste@example.com',
            'phone' => '(11) 99999-9999',
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
        ci_log($pdo, (int)$tpl['id'], null, 'info', 'template_preview', 'PDF de teste gerado');
        header('Location: ' . $pdf);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action === 'save') {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $current = $id > 0 ? ci_find_template($pdo, $id) : null;
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Informe o nome do certificado.');
            $description = trim((string)($_POST['description'] ?? ''));
            $slug = trim((string)($_POST['slug'] ?? ''));
            $slug = $slug !== '' ? ci_slugify($slug) : ci_unique_slug($pdo, $name, $id);
            $slug = ci_unique_slug($pdo, $slug, $id);
            $status = in_array(($_POST['status'] ?? 'active'), ['active','paused'], true) ? (string)$_POST['status'] : 'active';
            $layoutJson = trim((string)($_POST['layout_json'] ?? '{"front":[],"back":[]}'));
            if (!is_array(json_decode($layoutJson, true))) $layoutJson = '{"front":[],"back":[]}';
            $formCfg = [
                'collect_email' => isset($_POST['collect_email']),
                'collect_phone' => isset($_POST['collect_phone']),
                'require_password' => isset($_POST['require_password']),
                'show_illustration' => isset($_POST['show_illustration']),
                'page_title' => trim((string)($_POST['page_title'] ?? 'Gerar certificado')),
                'page_description' => trim((string)($_POST['page_description'] ?? 'Informe seus dados para gerar o certificado.')),
            ];
            $actionMode = in_array(($_POST['action_mode'] ?? 'download'), ['download','redirect'], true) ? (string)$_POST['action_mode'] : 'download';
            $redirectUrl = trim((string)($_POST['redirect_url'] ?? ''));
            $buttonLabel = trim((string)($_POST['button_label'] ?? 'Baixar certificado'));
            $success = trim((string)($_POST['success_message'] ?? 'Certificado gerado com sucesso.'));
            $errorMsg = trim((string)($_POST['error_message'] ?? 'Senha incorreta. Confira e tente novamente.'));
            $front = $current['front_image'] ?? null;
            $back = $current['back_image'] ?? null;
            $illustration = $current['illustration_image'] ?? null;
            if ($u = ci_store_upload('front_image', 'front')) $front = $u;
            if ($u = ci_store_upload('back_image', 'back')) $back = $u;
            if ($u = ci_store_upload('illustration_image', 'illustration')) $illustration = $u;
            $password = trim((string)($_POST['password'] ?? ''));
            $passwordSql = '';
            $params = [
                'name' => $name, 'description' => $description ?: null, 'slug' => $slug, 'status' => $status,
                'front_image' => $front, 'back_image' => $back, 'illustration_image' => $illustration,
                'layout_json' => $layoutJson,
                'form_config_json' => json_encode($formCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'action_mode' => $actionMode, 'redirect_url' => $redirectUrl,
                'button_label' => $buttonLabel ?: 'Baixar certificado',
                'success_message' => $success, 'error_message' => $errorMsg,
            ];
            if ($password !== '') {
                $passwordSql = ', password_hash=:password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            if ($id > 0 && $current) {
                $params['id'] = $id;
                $pdo->prepare("
                    UPDATE individual_certificate_templates SET
                    name=:name,description=:description,slug=:slug,status=:status,front_image=:front_image,back_image=:back_image,
                    illustration_image=:illustration_image,layout_json=:layout_json,form_config_json=:form_config_json,
                    action_mode=:action_mode,redirect_url=:redirect_url,button_label=:button_label,
                    success_message=:success_message,error_message=:error_message {$passwordSql}
                    WHERE id=:id
                ")->execute($params);
                ci_log($pdo, $id, null, 'info', 'template_updated', 'Certificado atualizado', ['name' => $name]);
                $msg = 'Certificado atualizado.';
            } else {
                $params['password_hash'] = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
                $pdo->prepare("
                    INSERT INTO individual_certificate_templates
                    (name,description,slug,status,front_image,back_image,illustration_image,layout_json,form_config_json,password_hash,action_mode,redirect_url,button_label,success_message,error_message)
                    VALUES (:name,:description,:slug,:status,:front_image,:back_image,:illustration_image,:layout_json,:form_config_json,:password_hash,:action_mode,:redirect_url,:button_label,:success_message,:error_message)
                ")->execute($params);
                $id = (int)$pdo->lastInsertId();
                ci_log($pdo, $id, null, 'info', 'template_created', 'Certificado criado', ['name' => $name]);
                $msg = 'Certificado criado.';
            }
            header('Location: certificados_individuais.php?edit=' . $id . '&ok=' . urlencode($msg));
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE individual_certificate_templates SET status='deleted' WHERE id=:id")->execute(['id' => $id]);
            ci_log($pdo, $id, null, 'warning', 'template_deleted', 'Certificado excluido');
            header('Location: certificados_individuais.php?ok=' . urlencode('Certificado excluido.'));
            exit;
        }
        if ($action === 'clone') {
            $id = (int)($_POST['id'] ?? 0);
            $tpl = ci_find_template($pdo, $id);
            if (!$tpl) throw new RuntimeException('Certificado nao encontrado.');
            $name = 'Copia de ' . $tpl['name'];
            $slug = ci_unique_slug($pdo, $name);
            $st = $pdo->prepare("
                INSERT INTO individual_certificate_templates
                (name,description,slug,status,front_image,back_image,illustration_image,layout_json,form_config_json,password_hash,action_mode,redirect_url,button_label,success_message,error_message)
                VALUES (:name,:description,:slug,'paused',:front_image,:back_image,:illustration_image,:layout_json,:form_config_json,:password_hash,:action_mode,:redirect_url,:button_label,:success_message,:error_message)
            ");
            $st->execute([
                'name' => $name, 'description' => $tpl['description'], 'slug' => $slug, 'front_image' => $tpl['front_image'],
                'back_image' => $tpl['back_image'], 'illustration_image' => $tpl['illustration_image'], 'layout_json' => $tpl['layout_json'],
                'form_config_json' => $tpl['form_config_json'], 'password_hash' => $tpl['password_hash'], 'action_mode' => $tpl['action_mode'],
                'redirect_url' => $tpl['redirect_url'], 'button_label' => $tpl['button_label'], 'success_message' => $tpl['success_message'], 'error_message' => $tpl['error_message'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            ci_log($pdo, $newId, null, 'info', 'template_cloned', 'Certificado clonado', ['source_id' => $id]);
            header('Location: certificados_individuais.php?edit=' . $newId . '&ok=' . urlencode('Certificado clonado.'));
            exit;
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

if (isset($_GET['ok'])) $msg = (string)$_GET['ok'];
$editId = max(0, (int)($_GET['edit'] ?? 0));
$edit = $editId > 0 ? ci_find_template($pdo, $editId) : null;
if (!$edit) {
    $edit = [
        'id' => 0, 'name' => '', 'description' => '', 'slug' => '', 'status' => 'active',
        'front_image' => null, 'back_image' => null, 'illustration_image' => null,
        'layout_json' => '{"front":[],"back":[]}', 'form_config_json' => json_encode(ci_default_form_config()),
        'action_mode' => 'download', 'redirect_url' => '', 'button_label' => 'Baixar certificado',
        'success_message' => 'Certificado gerado com sucesso.', 'error_message' => 'Senha incorreta. Confira e tente novamente.',
    ];
}
$formCfg = ci_decode_form_config((string)($edit['form_config_json'] ?? ''));
$layout = json_decode((string)($edit['layout_json'] ?? ''), true);
if (!is_array($layout)) $layout = ['front' => [], 'back' => []];
$templates = $pdo->query("SELECT t.*, (SELECT COUNT(*) FROM individual_certificate_issues i WHERE i.template_id=t.id AND i.status='generated') generated_count FROM individual_certificate_templates t WHERE status<>'deleted' ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$stats = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM individual_certificate_templates WHERE status<>'deleted') templates,
      (SELECT COUNT(*) FROM individual_certificate_issues WHERE status='generated') generated,
      (SELECT COUNT(*) FROM individual_certificate_logs WHERE level='error') errors,
      (SELECT COUNT(*) FROM individual_certificate_logs WHERE event='password_error') password_errors
")->fetch(PDO::FETCH_ASSOC) ?: [];
$daily = $pdo->query("SELECT DATE(created_at) d, SUM(status='generated') ok, SUM(status='error') err, COUNT(*) total FROM individual_certificate_issues WHERE created_at>=DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY DATE(created_at) ORDER BY d")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$logs = $pdo->query("SELECT l.*,t.name template_name FROM individual_certificate_logs l LEFT JOIN individual_certificate_templates t ON t.id=l.template_id ORDER BY l.id DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$imgBase = ci_upload_base_url();
$frontUrl = !empty($edit['front_image']) ? $imgBase . '/' . $edit['front_image'] : '';
$backUrl = !empty($edit['back_image']) ? $imgBase . '/' . $edit['back_image'] : '';
$illustrationUrl = !empty($edit['illustration_image']) ? $imgBase . '/' . $edit['illustration_image'] : '';
$publicUrl = !empty($edit['slug']) ? ci_public_url($edit) : '';

include __DIR__ . '/_header.php';
?>
<style>
.ci{max-width:1240px;margin:24px auto;padding:0 18px 70px}.ci-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.ci h1{margin:0;font-size:26px}.ci-muted{color:#94a3b8;font-size:13px}.ci-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 18px}.ci-tabs a{padding:9px 13px;border:1px solid #22324c;border-radius:8px;color:#dbeafe;text-decoration:none;background:#0b1220}.ci-tabs a.active{border-color:#facc15;color:#facc15}.ci-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:16px}.ci-card{background:#0b1120;border:1px solid #1e293b;border-radius:12px;padding:16px;margin-bottom:14px}.ci-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.ci-kpi strong{display:block;font-size:24px}.ci input,.ci textarea,.ci select{width:100%;background:#07101f;border:1px solid #1e293b;color:#e2e8f0;border-radius:8px;padding:9px}.ci textarea{min-height:78px}.ci label{display:block;font-size:11px;text-transform:uppercase;color:#94a3b8;margin:10px 0 5px}.ci-btn{border:0;border-radius:8px;padding:9px 12px;font-weight:800;cursor:pointer;background:#facc15;color:#111;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.ci-btn.ghost{background:#111827;color:#e2e8f0;border:1px solid #263448}.ci-btn.danger{background:#ef4444;color:#fff}.ci-list{display:grid;gap:8px}.ci-item{border:1px solid #1e293b;border-radius:9px;padding:10px;background:#08111f}.ci-item.active{border-color:#facc15}.ci-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.ci-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ci-canvas{position:relative;border:1px solid #1e293b;border-radius:10px;overflow:hidden;background:#111827;aspect-ratio:1.414/1;margin-top:8px}.ci-canvas img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.ci-overlay{position:absolute;inset:0}.ci-var{position:absolute;transform:translate(-50%,-50%);background:rgba(0,0,0,.62);border:1px dashed #facc15;color:#fde68a;padding:4px 8px;border-radius:6px;white-space:nowrap;cursor:move;font-weight:800}.ci-var.qr{border-color:#60a5fa;color:#bfdbfe;display:flex;align-items:center;justify-content:center;text-align:center}.ci-x{position:absolute;right:-8px;top:-8px;background:#ef4444;color:#fff;width:18px;height:18px;border-radius:50%;font-size:12px;text-align:center;line-height:18px}.ci-msg{padding:10px 12px;border-radius:8px;margin-bottom:12px}.ci-ok{background:#064e3b;color:#bbf7d0}.ci-err{background:#7f1d1d;color:#fecaca}.ci-bars{display:flex;align-items:end;height:160px;gap:8px}.ci-bar{flex:1;background:#172033;border-radius:6px 6px 0 0;position:relative;min-height:5px}.ci-bar span{position:absolute;bottom:100%;left:0;right:0;text-align:center;font-size:10px;color:#94a3b8}.ci-log{display:grid;grid-template-columns:130px 90px 130px 1fr;gap:8px;padding:8px;border-bottom:1px solid #1e293b;font-size:12px}.ci-badge{font-size:11px;border-radius:999px;padding:2px 7px;background:#1f2937}.ci-badge.error{background:#7f1d1d;color:#fecaca}.ci-badge.warning{background:#78350f;color:#fde68a}@media(max-width:950px){.ci-grid,.ci-two,.ci-kpis{grid-template-columns:1fr}.ci-log{grid-template-columns:1fr}}
</style>
<div class="ci">
  <div class="ci-head"><div><h1>Certificados individuais</h1><p class="ci-muted">Modelos independentes do certificado da area de membros, com link publico, senha propria e logs.</p></div><a class="ci-btn" href="certificados_individuais.php?edit=0">Novo certificado</a></div>
  <?php if ($msg): ?><div class="ci-msg ci-ok"><?= ci_h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ci-msg ci-err"><?= ci_h($err) ?></div><?php endif; ?>
  <div class="ci-tabs"><a class="active" href="#overview">Visao geral</a><a href="#editor">Editor</a><a href="#logs">Logs</a></div>

  <section id="overview" class="ci-card">
    <div class="ci-kpis">
      <div class="ci-card ci-kpi"><span class="ci-muted">Criados</span><strong><?= (int)$stats['templates'] ?></strong></div>
      <div class="ci-card ci-kpi"><span class="ci-muted">Gerados</span><strong><?= (int)$stats['generated'] ?></strong></div>
      <div class="ci-card ci-kpi"><span class="ci-muted">Erros</span><strong><?= (int)$stats['errors'] ?></strong></div>
      <div class="ci-card ci-kpi"><span class="ci-muted">Senha errada</span><strong><?= (int)$stats['password_errors'] ?></strong></div>
    </div>
    <div class="ci-card"><h3>Certificados gerados por dia</h3><div class="ci-bars"><?php $max=max(1,...array_map(fn($r)=>(int)$r['total'],$daily?:[['total'=>1]])); foreach($daily as $d): $h=max(5,round(((int)$d['total']/$max)*145)); ?><div class="ci-bar" style="height:<?= $h ?>px"><span><?= (int)$d['total'] ?></span></div><?php endforeach; ?></div></div>
  </section>

  <section id="editor" class="ci-grid">
    <aside class="ci-card">
      <h3>Modelos</h3>
      <div class="ci-list">
        <?php foreach ($templates as $tpl): ?>
          <div class="ci-item <?= (int)$tpl['id']===(int)$edit['id']?'active':'' ?>">
            <strong><?= ci_h($tpl['name']) ?></strong><br><span class="ci-muted"><?= ci_h($tpl['slug']) ?> · <?= (int)$tpl['generated_count'] ?> gerados</span>
            <div class="ci-row" style="margin-top:8px">
              <a class="ci-btn ghost" href="?edit=<?= (int)$tpl['id'] ?>">Editar</a>
              <form method="post"><input type="hidden" name="action" value="clone"><input type="hidden" name="id" value="<?= (int)$tpl['id'] ?>"><button class="ci-btn ghost">Clonar</button></form>
              <form method="post" onsubmit="return confirm('Excluir este certificado?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$tpl['id'] ?>"><button class="ci-btn danger">Excluir</button></form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </aside>
    <main>
      <form method="post" enctype="multipart/form-data" class="ci-card" id="ciForm">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="layout_json" id="layout_json">
        <div class="ci-two"><div><label>Nome</label><input name="name" value="<?= ci_h($edit['name']) ?>" required></div><div><label>Slug do link</label><input name="slug" value="<?= ci_h($edit['slug']) ?>" placeholder="gerado automaticamente"></div></div>
        <label>Descricao</label><textarea name="description"><?= ci_h($edit['description']) ?></textarea>
        <div class="ci-two"><div><label>Status</label><select name="status"><option value="active" <?= $edit['status']==='active'?'selected':'' ?>>Ativo</option><option value="paused" <?= $edit['status']==='paused'?'selected':'' ?>>Pausado</option></select></div><div><label>Senha de liberacao</label><input name="password" placeholder="<?= !empty($edit['password_hash'])?'Preenchida. Digite para trocar.':'Opcional' ?>"></div></div>
        <?php if ($publicUrl): ?><label>Link do aluno</label><div class="ci-row"><input readonly value="<?= ci_h($publicUrl) ?>"><a class="ci-btn ghost" target="_blank" href="<?= ci_h($publicUrl) ?>">Abrir</a></div><?php endif; ?>
        <div class="ci-two"><div><label>Imagem frente</label><input type="file" name="front_image" accept="image/*"></div><div><label>Imagem verso</label><input type="file" name="back_image" accept="image/*"></div></div>
        <div class="ci-two">
          <div><h3>Frente</h3><div class="ci-canvas" data-side="front"><?php if($frontUrl): ?><img src="<?= ci_h($frontUrl) ?>"><?php endif; ?><div class="ci-overlay"></div></div></div>
          <div><h3>Verso</h3><div class="ci-canvas" data-side="back"><?php if($backUrl): ?><img src="<?= ci_h($backUrl) ?>"><?php endif; ?><div class="ci-overlay"></div></div></div>
        </div>
        <div class="ci-card"><div class="ci-row"><select id="field"><option value="nome">Nome</option><option value="email">Email</option><option value="telefone">Telefone</option><option value="data_emissao">Data</option><option value="certificado">Certificado</option><option value="descricao">Descricao</option><option value="codigo">Codigo</option><option value="qr">QR Code</option></select><input id="font" type="number" value="28" style="width:120px"><select id="fontFamily"><option>DejaVu Sans</option><option>DejaVu Serif</option><option>DejaVu Sans Mono</option></select><button type="button" class="ci-btn ghost" onclick="addVar('front')">Inserir na frente</button><button type="button" class="ci-btn ghost" onclick="addVar('back')">Inserir no verso</button></div><p class="ci-muted">Arraste os campos diretamente no certificado. Para QR Code, o tamanho usa percentual da largura.</p></div>
        <h3>Tela do aluno</h3>
        <div class="ci-two"><div><label>Titulo</label><input name="page_title" value="<?= ci_h((string)$formCfg['page_title']) ?>"></div><div><label>Imagem ilustrativa</label><input type="file" name="illustration_image" accept="image/*"></div></div>
        <label>Texto da tela</label><textarea name="page_description"><?= ci_h((string)$formCfg['page_description']) ?></textarea>
        <div class="ci-row"><label><input type="checkbox" name="collect_email" <?= !empty($formCfg['collect_email'])?'checked':'' ?>> Coletar email</label><label><input type="checkbox" name="collect_phone" <?= !empty($formCfg['collect_phone'])?'checked':'' ?>> Coletar telefone</label><label><input type="checkbox" name="require_password" <?= !empty($formCfg['require_password'])?'checked':'' ?>> Exigir senha</label><label><input type="checkbox" name="show_illustration" <?= !empty($formCfg['show_illustration'])?'checked':'' ?>> Mostrar ilustracao</label></div>
        <?php if($illustrationUrl): ?><p class="ci-muted">Ilustracao atual: <a target="_blank" href="<?= ci_h($illustrationUrl) ?>">abrir imagem</a></p><?php endif; ?>
        <h3>Pos-geracao</h3>
        <div class="ci-two"><div><label>Acao do botao</label><select name="action_mode"><option value="download" <?= $edit['action_mode']==='download'?'selected':'' ?>>Baixar PDF</option><option value="redirect" <?= $edit['action_mode']==='redirect'?'selected':'' ?>>Redirecionar</option></select></div><div><label>Texto do botao</label><input name="button_label" value="<?= ci_h($edit['button_label']) ?>"></div></div>
        <label>URL de redirecionamento</label><input name="redirect_url" value="<?= ci_h($edit['redirect_url']) ?>" placeholder="https://...">
        <div class="ci-two"><div><label>Mensagem sucesso</label><textarea name="success_message"><?= ci_h($edit['success_message']) ?></textarea></div><div><label>Mensagem erro senha</label><textarea name="error_message"><?= ci_h($edit['error_message']) ?></textarea></div></div>
        <div class="ci-row"><button class="ci-btn">Salvar certificado</button><?php if($publicUrl): ?><a class="ci-btn ghost" target="_blank" href="<?= ci_h($publicUrl) ?>">Simular pagina</a><a class="ci-btn ghost" target="_blank" href="?preview=<?= (int)$edit['id'] ?>">Gerar PDF teste</a><?php endif; ?></div>
      </form>
    </main>
  </section>

  <section id="logs" class="ci-card">
    <h3>Logs</h3>
    <?php foreach($logs as $log): ?><div class="ci-log"><span><?= ci_h($log['created_at']) ?></span><span class="ci-badge <?= ci_h($log['level']) ?>"><?= ci_h($log['level']) ?></span><span><?= ci_h($log['event']) ?></span><span><?= ci_h($log['message']) ?> <em class="ci-muted"><?= ci_h($log['template_name'] ?? '') ?></em></span></div><?php endforeach; ?>
  </section>
</div>
<script>
const layout = <?= json_encode($layout, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?> || {front:[],back:[]};
layout.front=Array.isArray(layout.front)?layout.front:[];layout.back=Array.isArray(layout.back)?layout.back:[];
const labels={nome:'Nome',email:'Email',telefone:'Telefone',data_emissao:'Data',certificado:'Certificado',descricao:'Descricao',codigo:'Codigo',qr:'QR'};
function sync(){document.getElementById('layout_json').value=JSON.stringify(layout)}
function render(side){const c=document.querySelector(`.ci-canvas[data-side="${side}"]`),o=c?.querySelector('.ci-overlay');if(!o)return;o.innerHTML='';layout[side].forEach((it,i)=>{const el=document.createElement('div');el.className='ci-var '+(it.field==='qr'?'qr':'');el.style.left=(it.x||50)+'%';el.style.top=(it.y||50)+'%';if(it.field==='qr'){const pct=Math.max(5,Math.min(60,parseInt(it.font||18,10)));const px=Math.round(c.clientWidth*pct/100);el.style.width=px+'px';el.style.height=px+'px';el.textContent='QR '+pct+'%'}else{el.style.fontSize=(it.font||28)+'px';el.style.fontFamily=it.fontFamily||'DejaVu Sans';el.textContent=labels[it.field]||it.field}const x=document.createElement('span');x.className='ci-x';x.textContent='x';x.onclick=e=>{e.stopPropagation();layout[side].splice(i,1);render(side);sync()};el.appendChild(x);drag(el,side,i,c);o.appendChild(el)})}
function drag(el,side,i,c){el.onmousedown=e=>{if(e.target.className==='ci-x')return;e.preventDefault();const r=c.getBoundingClientRect(),sx=e.clientX,sy=e.clientY,ox=parseFloat(el.style.left),oy=parseFloat(el.style.top);function mv(ev){const x=Math.max(0,Math.min(100,ox+(ev.clientX-sx)*100/r.width)),y=Math.max(0,Math.min(100,oy+(ev.clientY-sy)*100/r.height));el.style.left=x+'%';el.style.top=y+'%';layout[side][i].x=x;layout[side][i].y=y;sync()}function up(){document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',up)}document.addEventListener('mousemove',mv);document.addEventListener('mouseup',up)}}
function addVar(side){layout[side].push({field:field.value,x:50,y:50,font:parseInt(font.value||28,10),fontFamily:fontFamily.value});render(side);sync()}
render('front');render('back');sync();document.getElementById('ciForm').onsubmit=sync;
</script>
<?php include __DIR__ . '/_footer.php'; ?>
