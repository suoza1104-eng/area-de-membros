<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/welcome_page.php';

proteger_admin();
$pdo = getPDO();
welcome_page_ensure_schema($pdo);

function bv_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$ok = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = $_POST;
        if (!empty($_FILES['welcome_video_file']['name']) && is_uploaded_file($_FILES['welcome_video_file']['tmp_name'])) {
            $max = 250 * 1024 * 1024;
            if ((int)($_FILES['welcome_video_file']['size'] ?? 0) > $max) {
                throw new RuntimeException('O video deve ter no maximo 250 MB.');
            }
            $ext = strtolower(pathinfo((string)$_FILES['welcome_video_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) {
                throw new RuntimeException('Formato de video invalido. Use MP4, WEBM, MOV ou M4V.');
            }
            $dir = __DIR__ . '/../uploads/welcome';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $name = 'welcome_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = $dir . '/' . $name;
            if (!move_uploaded_file($_FILES['welcome_video_file']['tmp_name'], $dest)) {
                throw new RuntimeException('Nao foi possivel salvar o video enviado.');
            }
            $data['welcome_page_video_type'] = 'upload';
            $data['welcome_page_video_value'] = rtrim(dirname(BASE_URL), '/') . '/uploads/welcome/' . $name;
        }
        welcome_page_save_settings($data);
        $ok = 'Pagina de boas-vindas salva com sucesso.';
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$cfg = welcome_page_settings();
$sampleUser = null;
try {
    $sampleUser = $pdo->query("SELECT id,nome,email,telefone,codigo_turma,welcome_token,welcome_url FROM users ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($sampleUser) {
        $sampleUser['welcome_url'] = welcome_page_ensure_user_link($pdo, (int)$sampleUser['id']);
    }
} catch (Throwable $e) {}

$menu = 'aulas';
include __DIR__ . '/_header.php';
?>
<style>
.bv{max-width:1240px;margin:24px auto;padding:0 18px 70px}.bv-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.bv h1{margin:0;font-size:26px}.bv-muted{color:#94a3b8;font-size:13px;line-height:1.5}.bv-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:16px}.bv-card{background:#0b1120;border:1px solid #1e293b;border-radius:12px;padding:16px;margin-bottom:14px}.bv-card h2{font-size:17px;margin:0 0 10px}.bv label{display:block;font-size:11px;text-transform:uppercase;color:#94a3b8;margin:10px 0 5px}.bv input,.bv textarea,.bv select{width:100%;background:#07101f;border:1px solid #1e293b;color:#e2e8f0;border-radius:8px;padding:9px}.bv textarea{min-height:74px;resize:vertical}.bv-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.bv-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.bv-btn{border:0;border-radius:8px;padding:9px 12px;font-weight:800;cursor:pointer;background:#facc15;color:#111;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.bv-btn.ghost{background:#111827;color:#e2e8f0;border:1px solid #263448}.bv-msg{padding:10px 12px;border-radius:8px;margin-bottom:12px}.bv-ok{background:#064e3b;color:#bbf7d0}.bv-err{background:#7f1d1d;color:#fecaca}.bv-help code{display:inline-block;margin:4px 4px 0 0;padding:4px 6px;border-radius:6px;background:#111827;color:#fde68a}.bv-preview{position:sticky;top:18px}.bv-phone{border:1px solid #263448;border-radius:18px;background:#f4f6f9;color:#0b1220;overflow:hidden}.bv-phone-top{padding:22px 18px 70px;text-align:center;background:radial-gradient(120% 90% at 50% 0%,#1e2433 0%,#0b1220 65%);color:#fff}.bv-pill{display:inline-flex;padding:5px 10px;border-radius:999px;background:rgba(250,204,21,.15);color:#fde68a;font-size:11px;font-weight:800;text-transform:uppercase}.bv-phone h3{margin:12px 0 6px;font-size:23px;line-height:1.05}.bv-video{height:124px;margin:-50px 18px 16px;border-radius:14px;background:#0a101c;border:1px solid rgba(255,255,255,.1);display:grid;place-items:center;color:#facc15;font-weight:900}.bv-step{margin:10px 14px;padding:12px;border:1px solid #e5e7eb;border-radius:14px;background:#fff}.bv-step b{display:block;font-size:14px}.bv-step span{display:block;margin-top:4px;color:#64748b;font-size:12px}.bv-step i{display:inline-block;margin-top:8px;padding:8px 10px;border-radius:9px;background:#facc15;font-style:normal;font-weight:800;font-size:12px}@media(max-width:980px){.bv-grid,.bv-two{grid-template-columns:1fr}.bv-preview{position:static}}
</style>
<div class="bv">
  <div class="bv-head">
    <div>
      <h1>Pagina de boas-vindas</h1>
      <p class="bv-muted">Configure a pagina unica e personalizada que cada aluno recebe por link curto. As variaveis sao aplicadas quando o aluno abre o link.</p>
    </div>
    <?php if ($sampleUser): ?><a class="bv-btn ghost" href="<?= bv_h((string)$sampleUser['welcome_url']) ?>" target="_blank" rel="noopener">Abrir exemplo</a><?php endif; ?>
  </div>
  <?php if ($ok): ?><div class="bv-msg bv-ok"><?= bv_h($ok) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="bv-msg bv-err"><?= bv_h($err) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <div class="bv-grid">
      <div>
        <div class="bv-card">
          <h2>Cabecalho e video</h2>
          <label><input type="checkbox" name="welcome_page_enabled" value="1" <?= $cfg['welcome_page_enabled'] === '1' ? 'checked' : '' ?> style="width:auto"> Pagina ativa</label>
          <label><input type="checkbox" name="welcome_page_video_enabled" value="1" <?= ($cfg['welcome_page_video_enabled'] ?? '1') === '1' ? 'checked' : '' ?> style="width:auto"> Exibir video na pagina</label>
          <div class="bv-two">
            <div><label>Nome do curso</label><input name="welcome_page_course_name" value="<?= bv_h($cfg['welcome_page_course_name']) ?>"></div>
            <div><label>Selo superior</label><input name="welcome_page_badge_text" value="<?= bv_h($cfg['welcome_page_badge_text']) ?>"></div>
          </div>
          <label>Titulo</label><input name="welcome_page_headline" value="<?= bv_h($cfg['welcome_page_headline']) ?>">
          <label>Texto de apoio</label><textarea name="welcome_page_lead"><?= bv_h($cfg['welcome_page_lead']) ?></textarea>
          <div class="bv-two">
            <div><label>Tipo de video</label><select name="welcome_page_video_type"><option value="youtube" <?= $cfg['welcome_page_video_type']==='youtube'?'selected':'' ?>>YouTube</option><option value="iframe" <?= $cfg['welcome_page_video_type']==='iframe'?'selected':'' ?>>Iframe/embed</option><option value="upload" <?= $cfg['welcome_page_video_type']==='upload'?'selected':'' ?>>Upload</option></select></div>
            <div><label>Duracao exibida</label><input name="welcome_page_video_duration" value="<?= bv_h($cfg['welcome_page_video_duration']) ?>"></div>
          </div>
          <label>URL/ID do YouTube, URL do video ou iframe</label><textarea name="welcome_page_video_value"><?= bv_h($cfg['welcome_page_video_value']) ?></textarea>
          <label>Enviar video</label><input type="file" name="welcome_video_file" accept="video/mp4,video/webm,video/quicktime,video/x-m4v">
          <div class="bv-two">
            <div><label>Titulo da capa</label><input name="welcome_page_video_title" value="<?= bv_h($cfg['welcome_page_video_title']) ?>"></div>
            <div><label>Subtitulo da capa</label><input name="welcome_page_video_subtitle" value="<?= bv_h($cfg['welcome_page_video_subtitle']) ?>"></div>
          </div>
        </div>
        <div class="bv-card">
          <h2>Botoes e passos</h2>
          <?php for ($i=1; $i<=4; $i++): ?>
            <div class="bv-card" style="background:#08111f">
              <h2>Botao <?= $i ?></h2>
              <div class="bv-two">
                <div><label>Titulo do passo</label><input name="welcome_page_button<?= $i ?>_text" value="<?= bv_h($cfg['welcome_page_button'.$i.'_text']) ?>"></div>
                <div><label>Texto do botao</label><input name="welcome_page_button<?= $i ?>_label" value="<?= bv_h($cfg['welcome_page_button'.$i.'_label']) ?>"></div>
              </div>
              <label>Descricao</label><textarea name="welcome_page_button<?= $i ?>_desc"><?= bv_h($cfg['welcome_page_button'.$i.'_desc']) ?></textarea>
              <div class="bv-two">
                <div><label>Link do botao</label><input name="welcome_page_button<?= $i ?>_url" value="<?= bv_h($cfg['welcome_page_button'.$i.'_url']) ?>"></div>
                <div><label>Abrir link</label><select name="welcome_page_button<?= $i ?>_target"><option value="_self" <?= ($cfg['welcome_page_button'.$i.'_target'] ?? '_blank')==='_self'?'selected':'' ?>>Na mesma pagina</option><option value="_blank" <?= ($cfg['welcome_page_button'.$i.'_target'] ?? '_blank')==='_blank'?'selected':'' ?>>Em outra aba</option></select></div>
              </div>
            </div>
          <?php endfor; ?>
        </div>
        <div class="bv-card">
          <h2>Links auxiliares</h2>
          <div class="bv-two">
            <div><label>Link do aplicativo</label><input name="welcome_page_app_url" value="<?= bv_h($cfg['welcome_page_app_url']) ?>"></div>
            <div><label>Link para ativar notificacoes</label><input name="welcome_page_notification_url" value="<?= bv_h($cfg['welcome_page_notification_url']) ?>"></div>
          </div>
          <label>Link do suporte</label><input name="welcome_page_support_url" value="<?= bv_h($cfg['welcome_page_support_url']) ?>" placeholder="Vazio usa o suporte padrao">
          <label>Texto do rodape</label><textarea name="welcome_page_footer_text"><?= bv_h($cfg['welcome_page_footer_text']) ?></textarea>
        </div>
        <div class="bv-actions"><button class="bv-btn" type="submit">Salvar pagina</button></div>
      </div>
      <aside class="bv-preview">
        <div class="bv-card bv-help">
          <h2>Variaveis</h2>
          <p class="bv-muted">Use nos textos e links. Em links, os valores sao codificados automaticamente.</p>
          <?php foreach (['{{nome}}','{{primeiro_nome}}','{{email}}','{{telefone}}','{{codigo_turma}}','{{data_live}}','{{app_url}}','{{app_login_url}}','{{notification_url}}','{{welcome_url}}'] as $var): ?><code><?= bv_h($var) ?></code><?php endforeach; ?>
        </div>
        <div class="bv-phone">
          <div class="bv-phone-top">
            <span class="bv-pill"><?= bv_h($cfg['welcome_page_badge_text']) ?></span>
            <h3><?= bv_h($cfg['welcome_page_headline']) ?></h3>
            <p><?= bv_h($cfg['welcome_page_lead']) ?></p>
          </div>
          <?php if (($cfg['welcome_page_video_enabled'] ?? '1') === '1'): ?><div class="bv-video">VIDEO</div><?php endif; ?>
          <?php for ($i=1; $i<=4; $i++): ?>
            <div class="bv-step"><b><?= bv_h($cfg['welcome_page_button'.$i.'_text']) ?></b><span><?= bv_h($cfg['welcome_page_button'.$i.'_desc']) ?></span><i><?= bv_h($cfg['welcome_page_button'.$i.'_label']) ?></i></div>
          <?php endfor; ?>
        </div>
        <?php if ($sampleUser): ?>
          <div class="bv-card">
            <h2>Ultimo aluno</h2>
            <p class="bv-muted"><?= bv_h('#'.(int)$sampleUser['id'].' - '.(string)$sampleUser['nome'].' - turma '.(string)$sampleUser['codigo_turma']) ?></p>
            <input readonly value="<?= bv_h((string)$sampleUser['welcome_url']) ?>" onclick="this.select()">
          </div>
        <?php endif; ?>
      </aside>
    </div>
  </form>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
