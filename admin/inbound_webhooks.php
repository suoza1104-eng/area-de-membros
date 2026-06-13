<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

$pdo = getPDO();

// Tabelas
$pdo->exec("CREATE TABLE IF NOT EXISTS inbound_webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    descricao TEXT NULL,
    evento VARCHAR(100) NOT NULL,
    lesson_id INT NULL,
    codigo_turma VARCHAR(100) NULL,
    tag_extra VARCHAR(200) NULL,
    token VARCHAR(64) NOT NULL,
    payload_map_json TEXT NULL,
    disparar_webhook TINYINT(1) NOT NULL DEFAULT 1,
    disparar_sf TINYINT(1) NOT NULL DEFAULT 1,
    disparar_manychat TINYINT(1) NOT NULL DEFAULT 1,
    criar_se_nao_existir TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    total_recebidos INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_iw_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN oferta_codigo VARCHAR(500) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN disparar_webhook TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN disparar_sf TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN disparar_manychat TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
$pdo->exec("CREATE TABLE IF NOT EXISTS inbound_webhook_recebimentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT NOT NULL,
    user_id INT NULL,
    payload_raw TEXT NOT NULL,
    status ENUM('pendente','processado','erro') NOT NULL DEFAULT 'pendente',
    erro_msg TEXT NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em DATETIME NULL,
    INDEX idx_iwr_webhook (webhook_id),
    INDEX idx_iwr_status (status),
    INDEX idx_iwr_recebido (recebido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->exec("ALTER TABLE inbound_webhook_recebimentos MODIFY COLUMN status ENUM('pendente','processado','erro','ignorado') NOT NULL DEFAULT 'pendente'"); } catch (Throwable $e) {}

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');

    if ($acao === 'salvar') {
        $id        = (int)($_POST['id'] ?? 0);
        $nome      = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $evento    = trim((string)($_POST['evento'] ?? ''));
        $lessonId  = (int)($_POST['lesson_id'] ?? 0);
        $codTurma  = trim((string)($_POST['codigo_turma'] ?? ''));
        $tagExtra  = trim((string)($_POST['tag_extra'] ?? ''));
        $ofertaCod = trim((string)($_POST['oferta_codigo'] ?? ''));
        $mapJson   = trim((string)($_POST['payload_map_json'] ?? ''));
        $dispWebhook = isset($_POST['disparar_webhook']) ? 1 : 0;
        $dispSf = isset($_POST['disparar_sf']) ? 1 : 0;
        $dispManychat = isset($_POST['disparar_manychat']) ? 1 : 0;
        $criar     = isset($_POST['criar_se_nao_existir']) ? 1 : 0;

        if ($mapJson === '') $mapJson = json_encode(['nome'=>'nome','email'=>'email','telefone'=>'telefone','oferta'=>'oferta','retorno_data'=>'retorno_data','retorno_tipo'=>'retorno_tipo','retorno_assunto'=>'retorno_assunto','retorno_mensagem'=>'retorno_mensagem']);
        if ($nome === '' || $evento === '') { echo json_encode(['ok'=>false,'msg'=>'Nome e evento são obrigatórios']); exit; }
        if ($evento === 'VIU_AULA' && $lessonId <= 0) { echo json_encode(['ok'=>false,'msg'=>'Selecione a aula']); exit; }

        if ($id > 0) {
            $pdo->prepare("UPDATE inbound_webhooks SET nome=:n,descricao=:d,evento=:ev,lesson_id=:l,codigo_turma=:ct,tag_extra=:tg,oferta_codigo=:of,payload_map_json=:m,disparar_webhook=:dw,disparar_sf=:dsf,disparar_manychat=:dm,criar_se_nao_existir=:cr WHERE id=:id")
                ->execute([':n'=>$nome,':d'=>$descricao,':ev'=>$evento,':l'=>$lessonId?:null,':ct'=>$codTurma?:null,':tg'=>$tagExtra?:null,':of'=>$ofertaCod?:null,':m'=>$mapJson,':dw'=>$dispWebhook,':dsf'=>$dispSf,':dm'=>$dispManychat,':cr'=>$criar,':id'=>$id]);
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO inbound_webhooks (nome,descricao,evento,lesson_id,codigo_turma,tag_extra,oferta_codigo,token,payload_map_json,disparar_webhook,disparar_sf,disparar_manychat,criar_se_nao_existir) VALUES (:n,:d,:ev,:l,:ct,:tg,:of,:tk,:m,:dw,:dsf,:dm,:cr)")
                ->execute([':n'=>$nome,':d'=>$descricao,':ev'=>$evento,':l'=>$lessonId?:null,':ct'=>$codTurma?:null,':tg'=>$tagExtra?:null,':of'=>$ofertaCod?:null,':tk'=>$token,':m'=>$mapJson,':dw'=>$dispWebhook,':dsf'=>$dispSf,':dm'=>$dispManychat,':cr'=>$criar]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }

    if ($acao === 'deletar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM inbound_webhook_recebimentos WHERE webhook_id = :id")->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM inbound_webhooks WHERE id = :id")->execute([':id'=>$id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($acao === 'clonar') {
        $id = (int)($_POST['id'] ?? 0);
        $r = $pdo->prepare("SELECT * FROM inbound_webhooks WHERE id = :id");
        $r->execute([':id'=>$id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false]); exit; }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO inbound_webhooks (nome,descricao,evento,lesson_id,codigo_turma,tag_extra,oferta_codigo,token,payload_map_json,disparar_webhook,disparar_sf,disparar_manychat,criar_se_nao_existir,ativo) VALUES (:n,:d,:ev,:l,:ct,:tg,:of,:tk,:m,:dw,:dsf,:dm,:cr,1)")
            ->execute([':n'=>'[Copia] '.$row['nome'], ':d'=>$row['descricao'], ':ev'=>$row['evento'], ':l'=>$row['lesson_id'], ':ct'=>$row['codigo_turma'], ':tg'=>$row['tag_extra'], ':of'=>$row['oferta_codigo']??null, ':tk'=>$token, ':m'=>$row['payload_map_json'], ':dw'=>(int)($row['disparar_webhook'] ?? 1), ':dsf'=>(int)($row['disparar_sf'] ?? 1), ':dm'=>(int)($row['disparar_manychat'] ?? 1), ':cr'=>$row['criar_se_nao_existir']]);
        echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]); exit;
    }

    if ($acao === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare("UPDATE inbound_webhooks SET ativo = 1 - ativo WHERE id = :id")->execute([':id'=>$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($acao === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $r = $pdo->prepare("SELECT * FROM inbound_webhooks WHERE id = :id"); $r->execute([':id'=>$id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        echo $row ? json_encode(['ok'=>true,'data'=>$row]) : json_encode(['ok'=>false]); exit;
    }

    if ($acao === 'listar') {
        $rows = $pdo->query("SELECT id,nome,descricao,evento,lesson_id,codigo_turma,tag_extra,oferta_codigo,disparar_webhook,disparar_sf,disparar_manychat,token,ativo,total_recebidos,criado_em FROM inbound_webhooks ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'data'=>$rows]); exit;
    }

    if ($acao === 'recebimentos') {
        $wid = (int)($_GET['webhook_id'] ?? 0);
        $st = $pdo->prepare("SELECT id,user_id,payload_raw,status,erro_msg,recebido_em,processado_em FROM inbound_webhook_recebimentos WHERE webhook_id = :w ORDER BY id DESC LIMIT 100");
        $st->execute([':w'=>$wid]);
        echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação desconhecida']); exit;
}

// Lessons disponíveis
$lessons = [];
try { $lessons = $pdo->query("SELECT id, titulo, ordem FROM lessons WHERE ativo = 1 ORDER BY ordem ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Turmas disponíveis (codigo)
$turmas = [];
try { $turmas = $pdo->query("SELECT codigo FROM turmas ORDER BY janela_inicio DESC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}

$webhookBaseUrl = rtrim(BASE_URL, '/') . '/inbound_webhook.php?t=';

$currentMenu = 'inbound_webhooks';
$page_title  = 'Webhooks de Entrada';
require_once __DIR__ . '/_header.php';
?>
<style>
.iw-wrap { display: flex; gap: 24px; align-items: flex-start; }
.iw-list { flex: 1; min-width: 0; }
.iw-form-panel {
    width: 540px; flex-shrink: 0;
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 12px; padding: 24px;
    position: sticky; top: 24px; max-height: calc(100vh - 48px); overflow-y: auto;
}
@media (max-width: 1100px) { .iw-wrap { flex-direction: column; } .iw-form-panel { width: 100%; position: static; max-height: none; } }

.iw-card {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 10px; padding: 16px 20px; margin-bottom: 12px;
}
.iw-card-top { display: flex; align-items: center; gap: 14px; }
.iw-card-info { flex: 1; min-width: 0; }
.iw-card-nome { font-weight: 600; font-size: 15px; margin-bottom: 4px; }
.iw-card-meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.iw-card-actions { display: flex; gap: 6px; flex-shrink: 0; }
.iw-webhook-row {
    margin-top: 10px; padding: 8px 10px; background: #14142a;
    border: 1px solid var(--border); border-radius: 6px;
    display: flex; align-items: center; gap: 8px; font-size: 11px;
}
.iw-webhook-row code { flex: 1; overflow-x: auto; white-space: nowrap; color: #60a5fa; background: none; padding: 0; }
.iw-copy-btn { background: var(--accent,#6366f1); border: none; color: #fff; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; }
.ev-pill { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.ev-inscrito { background: #1a3520; color: #34d399; }
.ev-aula     { background: #1e3a5f; color: #60a5fa; }
.ev-trilha   { background: #3a2e10; color: #fbbf24; }
.ev-login    { background: #2a1a3a; color: #c084fc; }
.ev-cert     { background: #3a1a1a; color: #f87171; }
.ev-tag      { background: #3a3a4a; color: #aaa; }

.form-row { margin-bottom: 14px; }
.form-row label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 5px; font-weight: 600; }
.form-row input, .form-row select, .form-row textarea {
    width: 100%; box-sizing: border-box;
    background: var(--input-bg,#1e1e2e); border: 1px solid var(--border);
    border-radius: 8px; color: var(--text); padding: 8px 12px; font-size: 14px;
}
.form-row textarea { min-height: 60px; resize: vertical; }
.map-row { display: flex; gap: 6px; margin-bottom: 4px; align-items: center; }
.map-row input { flex: 1; }
.iw-empty { text-align: center; color: var(--text-muted); padding: 48px 0; font-size: 15px; }

.iw-recv-modal { position: fixed; inset: 0; background: rgba(0,0,0,.7); display: none; align-items: center; justify-content: center; z-index: 1000; }
.iw-recv-modal.visible { display: flex; }
.iw-recv-box {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 14px; padding: 24px; width: 800px; max-width: 95vw;
    max-height: 85vh; overflow: hidden; display: flex; flex-direction: column;
}
.iw-recv-list { overflow-y: auto; flex: 1; }
.iw-recv-row { padding: 10px 12px; border-bottom: 1px solid var(--border); font-size: 12px; display: grid; grid-template-columns: 130px 90px 1fr; gap: 12px; align-items: start; }
.iw-recv-row pre { background: #0a0a1a; padding: 6px 10px; border-radius: 6px; overflow-x: auto; margin: 0; max-height: 100px; font-size: 11px; color: var(--text); }
.iw-st-processado { color: #4ade80; }
.iw-st-erro { color: #f87171; }
.iw-st-pendente { color: #fbbf24; }
.iw-st-ignorado { color: #94a3b8; }
.iw-integrations { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; }
.iw-integration-check { display:flex; align-items:center; gap:8px; padding:9px 10px; border:1px solid var(--border); border-radius:8px; background:rgba(255,255,255,.03); font-size:12px; cursor:pointer; }
.iw-integration-check input { width:auto; accent-color:var(--primary); }
.iw-int-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700; border:1px solid var(--border); background:rgba(255,255,255,.05); color:var(--text-muted); }
.iw-int-badge.on.webhook { color:#7dd3fc; border-color:rgba(56,189,248,.3); background:rgba(56,189,248,.1); }
.iw-int-badge.on.sf { color:#c4b5fd; border-color:rgba(167,139,250,.3); background:rgba(167,139,250,.1); }
.iw-int-badge.on.manychat { color:#f9a8d4; border-color:rgba(236,72,153,.3); background:rgba(236,72,153,.1); }
@media(max-width:700px){.iw-integrations{grid-template-columns:1fr;}}
</style>

<div class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Webhooks de Entrada</h1>
      <p class="page-subtitle">URLs que recebem dados de Hotmart, Kiwify, Eduzz e outras plataformas externas</p>
    </div>
    <button class="btn btn-primary" onclick="iwNovo()">+ Novo webhook</button>
  </div>

  <div class="iw-wrap">
    <div class="iw-list">
      <div id="iwListCont"><div class="iw-empty">Carregando…</div></div>
    </div>

    <div class="iw-form-panel" id="iwFormPanel" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
        <h3 style="margin:0;font-size:16px" id="iwFormTitle">Novo webhook</h3>
        <button onclick="iwFechar()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:20px;line-height:1">&times;</button>
      </div>

      <input type="hidden" id="iwId" value="0">

      <div class="form-row">
        <label>Nome *</label>
        <input type="text" id="iwNome" placeholder="Ex: Hotmart — Venda do Curso X">
      </div>

      <div class="form-row">
        <label>Descrição (opcional)</label>
        <textarea id="iwDescricao" placeholder="Ex: Recebe webhook da Hotmart quando uma venda é aprovada"></textarea>
      </div>

      <div class="form-row">
        <label>Evento que será disparado no sistema *</label>
        <select id="iwEvento" onchange="iwAtualizaCamposCondicionais()">
          <option value="INSCRITO">INSCRITO — cria aluno e libera acesso (principal — Hotmart/Kiwify)</option>
          <option value="PRIMEIRO_LOGIN">PRIMEIRO_LOGIN — marca como acessou a plataforma</option>
          <option value="VIU_AULA">VIU_AULA — marca aula como concluída</option>
          <option value="CONCLUIU_TRILHA">CONCLUIU_TRILHA — marca toda a trilha como concluída</option>
          <option value="CERT_EMITIDO">CERT_EMITIDO — dispara evento de certificado</option>
          <option value="REENVIO_CERTIFICADO">REENVIO_CERTIFICADO — dispara gatilho de reenvio do certificado</option>
          <option value="AGENDAR_RETORNO">AGENDAR_RETORNO — cria retorno agendado por payload</option>
          <option value="TAG_CUSTOM">TAG_CUSTOM — apenas aplica tag e dispara evento custom</option>
        </select>
      </div>

      <div class="form-row">
        <label>Redirecionar para integracoes</label>
        <div class="iw-integrations">
          <label class="iw-integration-check">
            <input type="checkbox" id="iwDispararWebhook" checked>
            <span>Webhook</span>
          </label>
          <label class="iw-integration-check">
            <input type="checkbox" id="iwDispararSf" checked>
            <span>SuperFuncionario</span>
          </label>
          <label class="iw-integration-check">
            <input type="checkbox" id="iwDispararManychat" checked>
            <span>Manychat</span>
          </label>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
          O evento acima sera encaminhado somente para os canais marcados. As regras de cada canal continuam nas telas Webhooks, SuperFuncionario e Manychat.
        </div>
      </div>

      <div class="form-row" id="iwLessonWrap" style="display:none">
        <label>Aula a marcar como concluída *</label>
        <select id="iwLessonId">
          <option value="0">-- selecione --</option>
          <?php foreach ($lessons as $l): ?>
          <option value="<?= (int)$l['id'] ?>">Aula <?= (int)$l['ordem'] ?> — <?= htmlspecialchars((string)$l['titulo']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row" id="iwTurmaWrap">
        <label>Turma a atribuir <span style="color:var(--text-muted);font-weight:400">(opcional)</span></label>
        <select id="iwCodigoTurma">
          <option value="">-- automática (turma com janela aberta na hora do webhook) --</option>
          <?php foreach ($turmas as $tc): ?>
          <option value="<?= htmlspecialchars((string)$tc) ?>"><?= htmlspecialchars((string)$tc) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
          Se nada for selecionado, o sistema usa a turma cuja <strong>janela de inscrição</strong> estiver aberta no momento do recebimento (mesma regra das inscrições orgânicas).
        </div>
      </div>

      <div class="form-row">
        <label>Tag extra a aplicar <span style="color:var(--text-muted);font-weight:400">(opcional)</span></label>
        <input type="text" id="iwTagExtra" placeholder="Ex: HOTMART_CURSO_X">
      </div>

      <div class="form-row">
        <label>Código(s) da oferta <span style="color:var(--text-muted);font-weight:400">(opcional — múltiplos separados por vírgula)</span></label>
        <input type="text" id="iwOfertaCodigo" placeholder="Ex: ZBF54VLP ou ZBF54VLP, OUTRA_OFF">
        <div style="font-size:11px;color:var(--text-muted);margin-top:6px;background:#14142a;padding:8px 10px;border-radius:6px;border:1px solid var(--border)">
          <strong style="color:#fbbf24">Filtro de oferta:</strong> se preenchido, o sistema só processa o webhook quando o código vindo no campo <code>oferta</code> do mapeamento bater com algum dos valores listados aqui. <strong>Vazio = aceita todas as ofertas.</strong> Útil pra Hotmart quando um único webhook recebe várias ofertas mas você só quer liberar uma específica.
        </div>
      </div>

      <div class="form-row">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="iwCriarSeNaoExistir" checked>
          <span>Criar aluno automaticamente se não existir <span style="color:var(--text-muted);font-weight:400">(libera acesso instantâneo)</span></span>
        </label>
      </div>

      <div class="form-row">
        <label>Mapeamento do payload</label>
        <div style="display:flex;gap:6px;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;padding:0 4px">
          <span style="flex:1">Campo interno</span><span style="width:24px"></span>
          <span style="flex:1">Caminho no payload externo</span><span style="width:32px"></span>
        </div>
        <div id="iwMap"></div>
        <button type="button" onclick="iwAddMap()" style="background:none;border:1px dashed var(--border);color:var(--text-muted);border-radius:6px;padding:5px 10px;cursor:pointer;font-size:11px;margin-top:4px">+ Adicionar mapeamento</button>
        <div style="font-size:11px;color:var(--text-muted);margin-top:8px;background:#14142a;padding:8px 10px;border-radius:6px;border:1px solid var(--border)">
          <strong style="color:#60a5fa">Caminhos aninhados suportados.</strong> Para a Hotmart que envia <code>{"data":{"buyer":{"email":"..."}}}</code>, use <code>email ← data.buyer.email</code>.<br>
          O sistema procura aluno por email e telefone; se não achar e "Criar aluno" estiver marcado, cria com senha = telefone (só números).
        </div>
        <div style="font-size:11px;color:var(--text);margin-top:8px;background:#1a2a1a;padding:10px 12px;border-radius:6px;border:1px solid rgba(52,211,153,.3)">
          <strong style="color:#34d399">📌 Referência — Hotmart (event PURCHASE_APPROVED, v2.0.0):</strong>
          <table style="margin-top:6px;font-size:11px;width:100%;border-collapse:collapse">
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted);width:80px">nome</td><td><code>data.buyer.name</code> <span style="color:var(--text-muted)">(ou <code>data.buyer.first_name</code> só primeiro nome)</span></td></tr>
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted)">email</td><td><code>data.buyer.email</code></td></tr>
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted)">telefone</td><td><code>data.buyer.checkout_phone</code> <span style="color:var(--text-muted)">(número completo com DDD)</span></td></tr>
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted)">oferta</td><td><code>data.purchase.offer.code</code> <span style="color:var(--text-muted)">(ou <code>data.product.ucode</code> p/ produto)</span></td></tr>
          </table>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:18px">
        <button class="btn btn-primary" style="flex:1" onclick="iwSalvar()">Salvar</button>
        <button class="btn" style="flex:1" onclick="iwFechar()">Cancelar</button>
      </div>
    </div>
  </div>
</div>

<div class="iw-recv-modal" id="iwRecvModal">
  <div class="iw-recv-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h3 style="margin:0" id="iwRecvTitle">Recebimentos</h3>
      <button onclick="iwRecvFechar()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:24px">&times;</button>
    </div>
    <div class="iw-recv-list" id="iwRecvList">Carregando…</div>
  </div>
</div>

<script>
const IW_WEBHOOK_BASE = <?= json_encode($webhookBaseUrl) ?>;
const EV_CLS = {
    'INSCRITO':'ev-inscrito','PRIMEIRO_LOGIN':'ev-login','VIU_AULA':'ev-aula',
    'CONCLUIU_TRILHA':'ev-trilha','CERT_EMITIDO':'ev-cert','REENVIO_CERTIFICADO':'ev-cert','AGENDAR_RETORNO':'ev-login','TAG_CUSTOM':'ev-tag'
};

document.addEventListener('DOMContentLoaded', iwCarregar);

async function iwCarregar() {
    const j = await (await fetch('inbound_webhooks.php?acao=listar')).json();
    const cont = document.getElementById('iwListCont');
    if (!j.ok || !j.data.length) {
        cont.innerHTML = '<div class="iw-empty">Nenhum webhook configurado.<br>Clique em <strong>+ Novo webhook</strong> para criar o primeiro.</div>';
        return;
    }
    cont.innerHTML = j.data.map(w => {
        const url = IW_WEBHOOK_BASE + w.token;
        const evCls = EV_CLS[w.evento] || 'ev-tag';
        return `<div class="iw-card">
            <div class="iw-card-top">
                <div class="iw-card-info">
                    <div class="iw-card-nome">${esc(w.nome)} <span style="font-size:11px;color:${w.ativo==1?'#4ade80':'#f87171'}">${w.ativo==1?'● ativo':'○ pausado'}</span></div>
                    <div class="iw-card-meta">
                        <span class="ev-pill ${evCls}">${w.evento}${w.lesson_id?(' #'+w.lesson_id):''}</span>
                        ${w.codigo_turma?`<span>turma: <strong>${esc(w.codigo_turma)}</strong></span>`:''}
                        ${w.tag_extra?`<span>tag: <strong>${esc(w.tag_extra)}</strong></span>`:''}
                        ${w.oferta_codigo?`<span style="color:#fbbf24">oferta: <strong>${esc(w.oferta_codigo)}</strong></span>`:''}
                        <span class="iw-int-badge ${parseInt(w.disparar_webhook||0)===1?'on webhook':''}">webhook</span>
                        <span class="iw-int-badge ${parseInt(w.disparar_sf||0)===1?'on sf':''}">sf</span>
                        <span class="iw-int-badge ${parseInt(w.disparar_manychat||0)===1?'on manychat':''}">manychat</span>
                        <span>📥 ${w.total_recebidos||0} recebimentos</span>
                    </div>
                </div>
                <div class="iw-card-actions">
                    <button class="btn btn-sm" onclick="iwVerRecebimentos(${w.id},'${esc(w.nome).replace(/'/g,"\\'")}')">📥</button>
                    <button class="btn btn-sm" onclick="iwToggle(${w.id})">${w.ativo==1?'⏸':'▶'}</button>
                    <button class="btn btn-sm" onclick="iwEditar(${w.id})">✏️</button>
                    <button class="btn btn-sm" onclick="iwClonar(${w.id})">🗐</button>
                    <button class="btn btn-sm btn-danger" onclick="iwDeletar(${w.id})">🗑</button>
                </div>
            </div>
            <div class="iw-webhook-row">
                <span style="color:var(--text-muted);font-weight:600">URL:</span>
                <code>${esc(url)}</code>
                <button class="iw-copy-btn" onclick="iwCopiar('${esc(url).replace(/'/g,"\\'")}', this)">Copiar</button>
            </div>
        </div>`;
    }).join('');
}

function iwNovo() {
    document.getElementById('iwId').value = 0;
    document.getElementById('iwNome').value = '';
    document.getElementById('iwDescricao').value = '';
    document.getElementById('iwEvento').value = 'INSCRITO';
    document.getElementById('iwDispararWebhook').checked = true;
    document.getElementById('iwDispararSf').checked = true;
    document.getElementById('iwDispararManychat').checked = true;
    document.getElementById('iwLessonId').value = 0;
    document.getElementById('iwCodigoTurma').value = '';
    document.getElementById('iwTagExtra').value = '';
    document.getElementById('iwOfertaCodigo').value = '';
    document.getElementById('iwCriarSeNaoExistir').checked = true;
    document.getElementById('iwMap').innerHTML = '';
    iwAddMap('nome','nome'); iwAddMap('email','email'); iwAddMap('telefone','telefone'); iwAddMap('oferta','oferta');
    iwAddMap('retorno_data','retorno_data'); iwAddMap('retorno_tipo','retorno_tipo'); iwAddMap('retorno_assunto','retorno_assunto'); iwAddMap('retorno_mensagem','retorno_mensagem');
    document.getElementById('iwFormTitle').textContent = 'Novo webhook';
    document.getElementById('iwFormPanel').style.display = '';
    iwAtualizaCamposCondicionais();
}

async function iwEditar(id) {
    const j = await (await fetch('inbound_webhooks.php?acao=get&id=' + id)).json();
    if (!j.ok) return alert('Erro');
    const d = j.data;
    document.getElementById('iwId').value = d.id;
    document.getElementById('iwNome').value = d.nome || '';
    document.getElementById('iwDescricao').value = d.descricao || '';
    document.getElementById('iwEvento').value = d.evento;
    document.getElementById('iwDispararWebhook').checked = parseInt(d.disparar_webhook ?? 1) === 1;
    document.getElementById('iwDispararSf').checked = parseInt(d.disparar_sf ?? 1) === 1;
    document.getElementById('iwDispararManychat').checked = parseInt(d.disparar_manychat ?? 1) === 1;
    document.getElementById('iwLessonId').value = d.lesson_id || 0;
    document.getElementById('iwCodigoTurma').value = d.codigo_turma || '';
    document.getElementById('iwTagExtra').value = d.tag_extra || '';
    document.getElementById('iwOfertaCodigo').value = d.oferta_codigo || '';
    document.getElementById('iwCriarSeNaoExistir').checked = parseInt(d.criar_se_nao_existir||0) === 1;
    document.getElementById('iwMap').innerHTML = '';
    const map = JSON.parse(d.payload_map_json || '{}');
    Object.entries(map).forEach(([k,v]) => iwAddMap(k,v));
    if (!Object.keys(map).length) {
        iwAddMap('nome','nome'); iwAddMap('email','email'); iwAddMap('telefone','telefone'); iwAddMap('oferta','oferta');
        iwAddMap('retorno_data','retorno_data'); iwAddMap('retorno_tipo','retorno_tipo'); iwAddMap('retorno_assunto','retorno_assunto'); iwAddMap('retorno_mensagem','retorno_mensagem');
    }
    document.getElementById('iwFormTitle').textContent = 'Editar: ' + d.nome;
    document.getElementById('iwFormPanel').style.display = '';
    iwAtualizaCamposCondicionais();
}

function iwFechar() { document.getElementById('iwFormPanel').style.display = 'none'; }

function iwAtualizaCamposCondicionais() {
    const ev = document.getElementById('iwEvento').value;
    document.getElementById('iwLessonWrap').style.display = (ev === 'VIU_AULA') ? '' : 'none';
    document.getElementById('iwTurmaWrap').style.display  = (ev === 'INSCRITO') ? '' : 'none';
}

function iwAddMap(from, to) {
    const cont = document.getElementById('iwMap');
    const div = document.createElement('div');
    div.className = 'map-row';
    div.innerHTML = `
        <input type="text" value="${esc(from||'')}" placeholder="nome|email|telefone" class="iw-map-from">
        <span style="color:var(--text-muted);font-size:14px">←</span>
        <input type="text" value="${esc(to||'')}" placeholder="campo.do.payload" class="iw-map-to">
        <button type="button" onclick="this.parentNode.remove()" style="background:none;border:1px solid #553;color:#f87171;border-radius:4px;padding:3px 8px;cursor:pointer">×</button>`;
    cont.appendChild(div);
}

function iwColetarMap() {
    const map = {};
    document.querySelectorAll('#iwMap .map-row').forEach(row => {
        const f = row.querySelector('.iw-map-from').value.trim();
        const t = row.querySelector('.iw-map-to').value.trim();
        if (f && t) map[f] = t;
    });
    return map;
}

async function iwSalvar() {
    const nome = document.getElementById('iwNome').value.trim();
    if (!nome) return alert('Nome obrigatório');
    const fd = new FormData();
    fd.append('acao','salvar');
    fd.append('id', document.getElementById('iwId').value);
    fd.append('nome', nome);
    fd.append('descricao', document.getElementById('iwDescricao').value);
    fd.append('evento', document.getElementById('iwEvento').value);
    if (document.getElementById('iwDispararWebhook').checked) fd.append('disparar_webhook','1');
    if (document.getElementById('iwDispararSf').checked) fd.append('disparar_sf','1');
    if (document.getElementById('iwDispararManychat').checked) fd.append('disparar_manychat','1');
    fd.append('lesson_id', document.getElementById('iwLessonId').value);
    fd.append('codigo_turma', document.getElementById('iwCodigoTurma').value);
    fd.append('tag_extra', document.getElementById('iwTagExtra').value);
    fd.append('oferta_codigo', document.getElementById('iwOfertaCodigo').value);
    if (document.getElementById('iwCriarSeNaoExistir').checked) fd.append('criar_se_nao_existir','1');
    fd.append('payload_map_json', JSON.stringify(iwColetarMap()));
    const j = await (await fetch('inbound_webhooks.php',{method:'POST',body:fd})).json();
    if (!j.ok) return alert('Erro: ' + (j.msg||''));
    iwFechar(); iwCarregar();
}

async function iwDeletar(id) {
    if (!confirm('Deletar este webhook e seus recebimentos?')) return;
    const fd = new FormData(); fd.append('acao','deletar'); fd.append('id',id);
    await fetch('inbound_webhooks.php',{method:'POST',body:fd}); iwCarregar();
}

async function iwClonar(id) {
    const fd = new FormData(); fd.append('acao','clonar'); fd.append('id',id);
    const j = await (await fetch('inbound_webhooks.php',{method:'POST',body:fd})).json();
    if (j.ok) { iwCarregar(); iwEditar(j.id); }
}

async function iwToggle(id) {
    const fd = new FormData(); fd.append('acao','toggle'); fd.append('id',id);
    await fetch('inbound_webhooks.php',{method:'POST',body:fd}); iwCarregar();
}

function iwCopiar(url, btn) {
    navigator.clipboard.writeText(url).then(() => {
        const old = btn.textContent; btn.textContent = '✓';
        setTimeout(()=>btn.textContent=old, 1200);
    });
}

async function iwVerRecebimentos(wid, nome) {
    document.getElementById('iwRecvTitle').textContent = 'Recebimentos — ' + nome;
    document.getElementById('iwRecvList').innerHTML = 'Carregando...';
    document.getElementById('iwRecvModal').classList.add('visible');
    const j = await (await fetch('inbound_webhooks.php?acao=recebimentos&webhook_id=' + wid)).json();
    if (!j.ok || !j.data.length) {
        document.getElementById('iwRecvList').innerHTML = '<div style="padding:20px;color:var(--text-muted);text-align:center">Nenhum recebimento ainda.</div>';
        return;
    }
    document.getElementById('iwRecvList').innerHTML = j.data.map(r => {
        let payload = '';
        try { payload = JSON.stringify(JSON.parse(r.payload_raw), null, 2); } catch(e) { payload = r.payload_raw || ''; }
        return `<div class="iw-recv-row">
            <div>
                <div style="font-size:11px">${fmtDate(r.recebido_em)}</div>
                ${r.processado_em?`<div style="font-size:10px;color:var(--text-muted)">${fmtDate(r.processado_em)}</div>`:''}
            </div>
            <div>
                <span class="iw-st-${r.status}">${r.status}</span>
                ${r.user_id?`<div style="font-size:10px;color:var(--text-muted)">uid: ${r.user_id}</div>`:''}
                ${r.erro_msg?`<div style="font-size:10px;color:#f87171">${esc(r.erro_msg).substring(0,80)}</div>`:''}
            </div>
            <pre>${esc(payload)}</pre>
        </div>`;
    }).join('');
}

function iwRecvFechar() { document.getElementById('iwRecvModal').classList.remove('visible'); }

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(dt) { if (!dt) return ''; return new Date(dt.replace(' ','T')).toLocaleString('pt-BR'); }
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
