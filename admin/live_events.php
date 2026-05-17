<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

$pdo = getPDO();

// Tabelas
$pdo->exec("CREATE TABLE IF NOT EXISTS live_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    descricao TEXT NULL,
    tipo ENUM('acessou','oferta','compra','custom') NOT NULL DEFAULT 'acessou',
    tag_nome VARCHAR(200) NOT NULL,
    token VARCHAR(64) NOT NULL,
    payload_map_json TEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    total_recebidos INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_le_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS live_event_recebimentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NULL,
    payload_raw TEXT NOT NULL,
    status ENUM('pendente','processado','erro') NOT NULL DEFAULT 'pendente',
    erro_msg TEXT NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em DATETIME NULL,
    INDEX idx_ler_event (event_id),
    INDEX idx_ler_status (status),
    INDEX idx_ler_recebido (recebido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ações AJAX
$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');

    if ($acao === 'salvar') {
        $id        = (int)($_POST['id'] ?? 0);
        $nome      = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $tipo      = in_array($_POST['tipo'] ?? '', ['acessou','oferta','compra','custom']) ? $_POST['tipo'] : 'acessou';
        $tagNome   = trim((string)($_POST['tag_nome'] ?? ''));
        $mapJson   = trim((string)($_POST['payload_map_json'] ?? ''));
        if ($mapJson === '') $mapJson = json_encode(['nome'=>'nome','email'=>'email','telefone'=>'telefone']);

        if ($nome === '' || $tagNome === '') {
            echo json_encode(['ok' => false, 'msg' => 'Nome e tag são obrigatórios']); exit;
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE live_events SET nome=:n, descricao=:d, tipo=:t, tag_nome=:tg, payload_map_json=:m WHERE id=:id")
                ->execute([':n'=>$nome,':d'=>$descricao,':t'=>$tipo,':tg'=>$tagNome,':m'=>$mapJson,':id'=>$id]);
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO live_events (nome, descricao, tipo, tag_nome, token, payload_map_json) VALUES (:n,:d,:t,:tg,:tk,:m)")
                ->execute([':n'=>$nome,':d'=>$descricao,':t'=>$tipo,':tg'=>$tagNome,':tk'=>$token,':m'=>$mapJson]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($acao === 'deletar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM live_event_recebimentos WHERE event_id = :id")->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM live_events WHERE id = :id")->execute([':id'=>$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($acao === 'clonar') {
        $id  = (int)($_POST['id'] ?? 0);
        $r   = $pdo->prepare("SELECT * FROM live_events WHERE id = :id");
        $r->execute([':id'=>$id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Não encontrado']); exit; }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO live_events (nome, descricao, tipo, tag_nome, token, payload_map_json, ativo) VALUES (:n,:d,:t,:tg,:tk,:m,1)")
            ->execute([
                ':n'=>'[Cópia] ' . $row['nome'], ':d'=>$row['descricao'], ':t'=>$row['tipo'],
                ':tg'=>$row['tag_nome'], ':tk'=>$token, ':m'=>$row['payload_map_json'],
            ]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($acao === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE live_events SET ativo = 1 - ativo WHERE id = :id")->execute([':id'=>$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($acao === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $r = $pdo->prepare("SELECT * FROM live_events WHERE id = :id");
        $r->execute([':id'=>$id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        echo $row ? json_encode(['ok'=>true,'data'=>$row]) : json_encode(['ok'=>false]);
        exit;
    }

    if ($acao === 'listar') {
        $rows = $pdo->query("SELECT id, nome, descricao, tipo, tag_nome, token, ativo, total_recebidos, criado_em FROM live_events ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'data'=>$rows]);
        exit;
    }

    if ($acao === 'recebimentos') {
        $eid = (int)($_GET['event_id'] ?? 0);
        $rows = $pdo->prepare("SELECT id, user_id, payload_raw, status, erro_msg, recebido_em, processado_em FROM live_event_recebimentos WHERE event_id = :e ORDER BY id DESC LIMIT 100");
        $rows->execute([':e'=>$eid]);
        echo json_encode(['ok'=>true,'data'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação desconhecida']);
    exit;
}

$basePublic = rtrim(BASE_URL, '/');
$webhookBaseUrl = $basePublic . '/live_webhook.php?t=';

$currentMenu = 'live_events';
$page_title  = 'Eventos de Live';
require_once __DIR__ . '/_header.php';
?>
<style>
.le-wrap { display: flex; gap: 24px; align-items: flex-start; }
.le-list { flex: 1; min-width: 0; }
.le-form-panel {
    width: 520px; flex-shrink: 0;
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 12px; padding: 24px;
    position: sticky; top: 24px;
}
@media (max-width: 1100px) { .le-wrap { flex-direction: column; } .le-form-panel { width: 100%; position: static; } }

.le-card {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 10px; padding: 16px 20px; margin-bottom: 12px;
}
.le-card-top { display: flex; align-items: center; gap: 14px; }
.le-card-info { flex: 1; min-width: 0; }
.le-card-nome { font-weight: 600; font-size: 15px; margin-bottom: 4px; }
.le-card-meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 12px; flex-wrap: wrap; }
.le-card-actions { display: flex; gap: 6px; flex-shrink: 0; }
.le-webhook-row {
    margin-top: 10px; padding: 8px 10px; background: #14142a;
    border: 1px solid var(--border); border-radius: 6px;
    display: flex; align-items: center; gap: 8px; font-size: 11px;
}
.le-webhook-row code {
    flex: 1; overflow-x: auto; white-space: nowrap; color: #60a5fa;
    background: none; padding: 0;
}
.le-copy-btn {
    background: var(--accent, #6366f1); border: none; color: #fff;
    padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 11px;
}
.le-tag-pill {
    display: inline-block; padding: 2px 10px; border-radius: 20px;
    background: rgba(168,85,247,.15); color: #c084fc;
    font-size: 11px; font-weight: 600;
}
.le-tipo-pill {
    display: inline-block; padding: 2px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600; text-transform: uppercase;
}
.tipo-acessou { background: #1e3a5f; color: #60a5fa; }
.tipo-oferta  { background: #3a2e10; color: #fbbf24; }
.tipo-compra  { background: #1a3520; color: #34d399; }
.tipo-custom  { background: #3a3a4a; color: #aaa; }
.le-status-on { color: #4ade80; }
.le-status-off { color: #f87171; }

.form-row { margin-bottom: 14px; }
.form-row label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 5px; font-weight: 600; }
.form-row input, .form-row select, .form-row textarea {
    width: 100%; box-sizing: border-box;
    background: var(--input-bg,#1e1e2e); border: 1px solid var(--border);
    border-radius: 8px; color: var(--text); padding: 8px 12px; font-size: 14px;
    font-family: var(--font, inherit);
}
.form-row textarea { min-height: 70px; resize: vertical; }
.map-row { display: flex; gap: 6px; margin-bottom: 4px; align-items: center; }
.map-row input { flex: 1; }
.le-empty { text-align: center; color: var(--text-muted); padding: 48px 0; font-size: 15px; }

/* Modal de recebimentos */
.le-recv-modal {
    position: fixed; inset: 0; background: rgba(0,0,0,.7);
    display: none; align-items: center; justify-content: center; z-index: 1000;
}
.le-recv-modal.visible { display: flex; }
.le-recv-box {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 14px; padding: 24px; width: 800px; max-width: 95vw;
    max-height: 85vh; overflow: hidden; display: flex; flex-direction: column;
}
.le-recv-list { overflow-y: auto; flex: 1; }
.le-recv-row {
    padding: 10px 12px; border-bottom: 1px solid var(--border);
    font-size: 12px; display: grid; grid-template-columns: 130px 90px 1fr;
    gap: 12px; align-items: start;
}
.le-recv-row pre {
    background: #0a0a1a; padding: 6px 10px; border-radius: 6px;
    overflow-x: auto; margin: 0; max-height: 80px; font-size: 11px;
    color: var(--text);
}
.le-st-ok { color: #4ade80; }
.le-st-erro { color: #f87171; }
.le-st-pendente { color: #fbbf24; }
</style>

<div class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Eventos de Live</h1>
      <p class="page-subtitle">Configure URLs de webhook para capturar acesso, oferta e compra em lives externas</p>
    </div>
    <button class="btn btn-primary" onclick="leNovo()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:6px;vertical-align:-3px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Novo evento
    </button>
  </div>

  <div class="le-wrap">
    <div class="le-list">
      <div id="leListCont"><div class="le-empty">Carregando…</div></div>
    </div>

    <div class="le-form-panel" id="leFormPanel" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
        <h3 style="margin:0;font-size:16px" id="leFormTitle">Novo evento</h3>
        <button onclick="leFechar()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:20px;line-height:1">&times;</button>
      </div>

      <input type="hidden" id="leId" value="0">

      <div class="form-row">
        <label>Nome do evento *</label>
        <input type="text" id="leNome" placeholder="Ex: Live 26/05 — Acessou">
      </div>

      <div class="form-row">
        <label>Descrição (opcional)</label>
        <textarea id="leDescricao" placeholder="Ex: Pessoas que acessaram a sala da live de quarta..."></textarea>
      </div>

      <div class="form-row" style="display:flex;gap:12px">
        <div style="flex:1">
          <label>Tipo de ação *</label>
          <select id="leTipo">
            <option value="acessou">Acessou (LIVE_ACESSOU)</option>
            <option value="oferta">Ficou até a oferta (LIVE_OFERTA)</option>
            <option value="compra">Clicou na compra (LIVE_COMPRA)</option>
            <option value="custom">Customizado (LIVE_EVENTO)</option>
          </select>
        </div>
        <div style="flex:1">
          <label>Tag a aplicar *</label>
          <input type="text" id="leTagNome" placeholder="Ex: LIVE_2605_ACESSOU">
        </div>
      </div>

      <div class="form-row">
        <label>Mapeamento do payload <span style="color:var(--text-muted);font-weight:400">(de → para)</span></label>
        <div id="leMap"></div>
        <button type="button" onclick="leAddMap()" style="background:none;border:1px dashed var(--border);color:var(--text-muted);border-radius:6px;padding:5px 10px;cursor:pointer;font-size:11px;margin-top:4px">+ Adicionar mapeamento</button>
        <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
          Define como os campos do payload externo mapeiam para os campos internos. Padrão: nome→nome, email→email, telefone→telefone.
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:18px">
        <button class="btn btn-primary" style="flex:1" onclick="leSalvar()">Salvar</button>
        <button class="btn" style="flex:1" onclick="leFechar()">Cancelar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de recebimentos -->
<div class="le-recv-modal" id="leRecvModal">
  <div class="le-recv-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h3 style="margin:0" id="leRecvTitle">Recebimentos</h3>
      <button onclick="leRecvFechar()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:24px">&times;</button>
    </div>
    <div class="le-recv-list" id="leRecvList">Carregando…</div>
  </div>
</div>

<script>
const WEBHOOK_BASE = <?= json_encode($webhookBaseUrl) ?>;

document.addEventListener('DOMContentLoaded', leCarregarLista);

async function leCarregarLista() {
    const j = await (await fetch('live_events.php?acao=listar')).json();
    const cont = document.getElementById('leListCont');
    if (!j.ok || !j.data.length) {
        cont.innerHTML = '<div class="le-empty">Nenhum evento criado ainda.<br>Clique em <strong>Novo evento</strong> para criar o primeiro.</div>';
        return;
    }
    cont.innerHTML = j.data.map(e => {
        const url = WEBHOOK_BASE + e.token;
        const tipoCls = 'tipo-' + e.tipo;
        return `<div class="le-card" id="leCard${e.id}">
            <div class="le-card-top">
                <div class="le-card-info">
                    <div class="le-card-nome">${esc(e.nome)} <span class="${e.ativo==1?'le-status-on':'le-status-off'}" style="font-size:11px">${e.ativo==1?'● ativo':'○ pausado'}</span></div>
                    <div class="le-card-meta">
                        <span class="le-tipo-pill ${tipoCls}">${e.tipo}</span>
                        <span class="le-tag-pill">tag: ${esc(e.tag_nome)}</span>
                        <span>📥 ${e.total_recebidos || 0} recebimentos</span>
                        ${e.descricao ? `<span style="opacity:.7">${esc(e.descricao.substring(0,80))}${e.descricao.length>80?'...':''}</span>` : ''}
                    </div>
                </div>
                <div class="le-card-actions">
                    <button class="btn btn-sm" onclick="leVerRecebimentos(${e.id}, '${esc(e.nome).replace(/'/g,"\\'")}')" title="Ver recebimentos">📥</button>
                    <button class="btn btn-sm" onclick="leToggle(${e.id})" title="${e.ativo==1?'Pausar':'Ativar'}">${e.ativo==1?'⏸':'▶'}</button>
                    <button class="btn btn-sm" onclick="leEditar(${e.id})" title="Editar">✏️</button>
                    <button class="btn btn-sm" onclick="leClonar(${e.id})" title="Clonar">🗐</button>
                    <button class="btn btn-sm btn-danger" onclick="leDeletar(${e.id})" title="Deletar">🗑</button>
                </div>
            </div>
            <div class="le-webhook-row">
                <span style="color:var(--text-muted);font-weight:600">URL:</span>
                <code id="le-url-${e.id}">${esc(url)}</code>
                <button class="le-copy-btn" onclick="leCopiar('${esc(url).replace(/'/g,"\\'")}', this)">Copiar</button>
            </div>
        </div>`;
    }).join('');
}

function leNovo() {
    document.getElementById('leId').value = 0;
    document.getElementById('leNome').value = '';
    document.getElementById('leDescricao').value = '';
    document.getElementById('leTipo').value = 'acessou';
    document.getElementById('leTagNome').value = '';
    document.getElementById('leMap').innerHTML = '';
    leAddMap('nome', 'nome');
    leAddMap('email', 'email');
    leAddMap('telefone', 'telefone');
    document.getElementById('leFormTitle').textContent = 'Novo evento';
    document.getElementById('leFormPanel').style.display = '';
}

async function leEditar(id) {
    const j = await (await fetch('live_events.php?acao=get&id=' + id)).json();
    if (!j.ok) return alert('Erro ao carregar');
    const d = j.data;
    document.getElementById('leId').value = d.id;
    document.getElementById('leNome').value = d.nome;
    document.getElementById('leDescricao').value = d.descricao || '';
    document.getElementById('leTipo').value = d.tipo;
    document.getElementById('leTagNome').value = d.tag_nome;
    document.getElementById('leMap').innerHTML = '';
    const map = JSON.parse(d.payload_map_json || '{}');
    Object.entries(map).forEach(([k, v]) => leAddMap(k, v));
    if (!Object.keys(map).length) {
        leAddMap('nome','nome'); leAddMap('email','email'); leAddMap('telefone','telefone');
    }
    document.getElementById('leFormTitle').textContent = 'Editar: ' + d.nome;
    document.getElementById('leFormPanel').style.display = '';
}

function leFechar() {
    document.getElementById('leFormPanel').style.display = 'none';
}

function leAddMap(from, to) {
    const cont = document.getElementById('leMap');
    const div = document.createElement('div');
    div.className = 'map-row';
    div.innerHTML = `
        <input type="text" value="${esc(from||'')}" placeholder="campo interno (nome)" class="map-from">
        <span style="color:var(--text-muted)">←</span>
        <input type="text" value="${esc(to||'')}" placeholder="campo no payload externo" class="map-to">
        <button type="button" onclick="this.parentNode.remove()" style="background:none;border:1px solid #553;color:#f87171;border-radius:4px;padding:3px 8px;cursor:pointer">×</button>
    `;
    cont.appendChild(div);
}

function leColetarMap() {
    const map = {};
    document.querySelectorAll('#leMap .map-row').forEach(row => {
        const f = row.querySelector('.map-from').value.trim();
        const t = row.querySelector('.map-to').value.trim();
        if (f && t) map[f] = t;
    });
    return map;
}

async function leSalvar() {
    const nome = document.getElementById('leNome').value.trim();
    const tag  = document.getElementById('leTagNome').value.trim();
    if (!nome || !tag) return alert('Nome e tag são obrigatórios');
    const fd = new FormData();
    fd.append('acao', 'salvar');
    fd.append('id', document.getElementById('leId').value);
    fd.append('nome', nome);
    fd.append('descricao', document.getElementById('leDescricao').value);
    fd.append('tipo', document.getElementById('leTipo').value);
    fd.append('tag_nome', tag);
    fd.append('payload_map_json', JSON.stringify(leColetarMap()));
    const j = await (await fetch('live_events.php', {method:'POST', body:fd})).json();
    if (!j.ok) return alert('Erro: ' + (j.msg || ''));
    leFechar();
    leCarregarLista();
}

async function leDeletar(id) {
    if (!confirm('Deletar este evento e todos seus recebimentos?')) return;
    const fd = new FormData(); fd.append('acao','deletar'); fd.append('id',id);
    await fetch('live_events.php', {method:'POST',body:fd});
    leCarregarLista();
}

async function leClonar(id) {
    const fd = new FormData(); fd.append('acao','clonar'); fd.append('id',id);
    const j = await (await fetch('live_events.php', {method:'POST',body:fd})).json();
    if (j.ok) { leCarregarLista(); leEditar(j.id); }
}

async function leToggle(id) {
    const fd = new FormData(); fd.append('acao','toggle'); fd.append('id',id);
    await fetch('live_events.php', {method:'POST',body:fd});
    leCarregarLista();
}

function leCopiar(url, btn) {
    navigator.clipboard.writeText(url).then(() => {
        const old = btn.textContent;
        btn.textContent = '✓';
        setTimeout(() => btn.textContent = old, 1200);
    });
}

async function leVerRecebimentos(eid, nome) {
    document.getElementById('leRecvTitle').textContent = 'Recebimentos — ' + nome;
    document.getElementById('leRecvList').innerHTML = 'Carregando...';
    document.getElementById('leRecvModal').classList.add('visible');
    const j = await (await fetch('live_events.php?acao=recebimentos&event_id=' + eid)).json();
    if (!j.ok || !j.data.length) {
        document.getElementById('leRecvList').innerHTML = '<div style="padding:20px;color:var(--text-muted);text-align:center">Nenhum recebimento ainda.</div>';
        return;
    }
    document.getElementById('leRecvList').innerHTML = j.data.map(r => {
        let payload = '';
        try { payload = JSON.stringify(JSON.parse(r.payload_raw), null, 2); } catch(e) { payload = r.payload_raw || ''; }
        return `<div class="le-recv-row">
            <div>
                <div style="font-size:11px">${fmtDate(r.recebido_em)}</div>
                ${r.processado_em ? `<div style="font-size:10px;color:var(--text-muted)">${fmtDate(r.processado_em)}</div>` : ''}
            </div>
            <div>
                <span class="le-st-${r.status}">${r.status}</span>
                ${r.user_id ? `<div style="font-size:10px;color:var(--text-muted)">uid: ${r.user_id}</div>` : ''}
                ${r.erro_msg ? `<div style="font-size:10px;color:#f87171">${esc(r.erro_msg).substring(0,60)}</div>` : ''}
            </div>
            <pre>${esc(payload)}</pre>
        </div>`;
    }).join('');
}

function leRecvFechar() {
    document.getElementById('leRecvModal').classList.remove('visible');
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(dt) {
    if (!dt) return '';
    const d = new Date(dt.replace(' ','T'));
    return d.toLocaleString('pt-BR');
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
