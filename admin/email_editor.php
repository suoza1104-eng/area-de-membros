<?php
declare(strict_types=1);
require_once __DIR__.'/../app/email_marketing.php';
proteger_admin();
$pdo=getPDO();
email_marketing_ensure_schema($pdo);
$id=max(0,(int)($_GET['id']??$_POST['id']??0));
$csrf=email_admin_csrf();
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($_POST['action']??'',['send_test','ai_review','ai_generate','ai_adjust'],true)){
    header('Content-Type: application/json; charset=utf-8');
    try{
        email_check_csrf();
        $subject=trim((string)($_POST['subject']??''));
        $preheader=trim((string)($_POST['preheader']??''));
        $html=(string)($_POST['html']??'');
        $action=(string)$_POST['action'];
        if($action==='send_test'){
            $messageId=email_send_test(email_settings($pdo),(string)($_POST['test_email']??''),$subject,$preheader,$html);
            echo json_encode(['ok'=>true,'message_id'=>$messageId],JSON_UNESCAPED_UNICODE);
        }elseif($action==='ai_review'){
            echo json_encode(['ok'=>true,'review'=>email_review_with_ai($pdo,$subject,$preheader,$html)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }else{
            $prompt=trim((string)($_POST['prompt']??''));
            if($action==='ai_adjust')$prompt='Ajuste este rascunho conforme pedido, mantendo o que ja estiver bom: '.$prompt;
            $draft=email_generate_template_with_ai($pdo,$prompt,(int)($_POST['inspiration_id']??0),[
                'name'=>(string)($_POST['name']??''),
                'subject'=>$subject,
                'preheader'=>$preheader,
                'html'=>$html,
            ]);
            echo json_encode(['ok'=>true,'draft'=>$draft],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }catch(Throwable $e){
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        email_check_csrf();
        $id=email_template_save($pdo,$_POST,(string)($_SESSION['equipe_nome']??'Administrador'));
        header('Location: email_editor.php?id='.$id.'&saved=1');
        exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}

$template=[
    'name'=>'',
    'subject'=>'Seu acesso ao curso foi liberado',
    'preheader'=>'Confira o link para acessar suas aulas.',
    'html_content'=>'<div style="max-width:600px;margin:auto;padding:28px;font-family:Arial,sans-serif;color:#172033;background:#ffffff"><p style="font-size:16px;line-height:1.6">Olá, {{nome}}.</p><p style="font-size:16px;line-height:1.6">Seu acesso ao curso já está disponível.</p><p style="font-size:16px;line-height:1.6">Para acessar sua área de membros, clique no link abaixo:</p><p style="font-size:16px;line-height:1.6"><a href="{{link_area_membros}}">{{link_area_membros}}</a></p><p style="font-size:16px;line-height:1.6">Se tiver qualquer dúvida, responda este e-mail.</p><p style="font-size:16px;line-height:1.6">Bons estudos,<br>Professor Emerson Leite<br><a href="https://professoremersonleite.com">https://professoremersonleite.com</a></p><p style="font-size:12px;color:#64748b;line-height:1.5;margin-top:28px">Você recebeu este e-mail porque se cadastrou em uma página do Professor Emerson Leite ou solicitou acesso a um de nossos conteúdos.</p><p style="font-size:12px;color:#64748b;line-height:1.5">Se não quiser mais receber nossos e-mails, clique aqui para se descadastrar: <a href="{{link_descadastro}}">{{link_descadastro}}</a></p></div>',
];
if($id){
    $st=$pdo->prepare("SELECT t.*,v.html_content FROM email_templates t LEFT JOIN email_template_versions v ON v.id=t.current_version_id WHERE t.id=:id AND t.status<>'deleted'");
    $st->execute(['id'=>$id]);
    $template=$st->fetch(PDO::FETCH_ASSOC)?:$template;
}

$templateOptions=$pdo->query("SELECT id,name,subject FROM email_templates WHERE status<>'deleted' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)?:[];
$settings=email_settings($pdo);
$aiConfigured=trim((string)get_setting('whatsapp_ai_openai_api_key',''))!=='';
$provider=email_active_provider($settings);
$sendConfigured=$provider==='resend'?email_resend_credentials()['configured']&&!empty($settings['resend_from_email']):!empty($settings['from_email']);
$menu='email_marketing';
$page_title='Editor de e-mail';
include __DIR__.'/_header.php';
echo email_admin_styles();
?>
<style>
.ee-shell{display:grid;gap:12px}.ee-top{display:flex;align-items:center;justify-content:space-between;gap:12px}.ee-top h1{font-size:22px;margin:0}.ee-actions,.ee-view-actions{display:flex;gap:7px;flex-wrap:wrap}.ee-workspace{display:grid;grid-template-columns:210px minmax(420px,1fr) 300px;min-height:680px;border:1px solid var(--border);border-radius:16px;overflow:hidden;background:#080e1a}.ee-side{padding:15px;background:var(--bg-card);overflow:auto}.ee-side.left{border-right:1px solid var(--border)}.ee-side.right{border-left:1px solid var(--border)}.ee-side h2{font-size:13px;margin:0 0 4px}.ee-side-copy{font-size:10px;color:var(--muted);margin-bottom:13px}.ee-blocks{display:grid;grid-template-columns:1fr 1fr;gap:7px}.ee-block{display:grid;place-items:center;gap:5px;min-height:62px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text);font-size:10px;font-weight:700}.ee-block:hover{border-color:var(--primary);background:var(--primary-dim)}.ee-block b{font-size:18px}.ee-center{display:flex;flex-direction:column;min-width:0}.ee-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 13px;border-bottom:1px solid var(--border);background:#0b1220}.ee-toolbar span{font-size:10px;color:var(--muted)}.ee-stage{flex:1;overflow:auto;padding:28px;background:#dfe3e8;background-image:linear-gradient(45deg,#d7dce2 25%,transparent 25%),linear-gradient(-45deg,#d7dce2 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#d7dce2 75%),linear-gradient(-45deg,transparent 75%,#d7dce2 75%);background-size:20px 20px;background-position:0 0,0 10px,10px -10px,-10px 0}.ee-paper{width:600px;max-width:100%;min-height:560px;margin:auto;background:#fff;color:#172033;box-shadow:0 12px 35px #11182733;outline:0;transition:.2s}.ee-stage.mobile .ee-paper{width:375px}.ee-field{display:grid;gap:5px;margin-bottom:11px}.ee-field label{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}.ee-field input,.ee-field textarea,.ee-field select{width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text)}.ee-code{min-height:300px;font-family:monospace;font-size:11px}.ee-variable{display:flex;flex-wrap:wrap;gap:5px}.ee-variable button{padding:4px 7px;border-radius:6px;background:var(--bg);border:1px solid var(--border);color:#93c5fd;font-size:9px}.ee-ai-panel{display:none;gap:10px;padding:14px;border:1px solid #38bdf855;border-radius:14px;background:rgba(14,165,233,.08)}.ee-ai-panel.open{display:grid}.ee-ai-panel-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.ee-ai-panel h2{font-size:15px;margin:0}.ee-ai-panel textarea{width:100%;min-height:70px;padding:9px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text)}.ee-modal{display:none;position:fixed;inset:0;z-index:13000;align-items:center;justify-content:center;padding:20px;background:#020617d9;backdrop-filter:blur(4px)}.ee-modal.open{display:flex}.ee-dialog{width:min(760px,100%);max-height:90vh;overflow:auto;border:1px solid var(--border);border-radius:18px;background:var(--bg-card);padding:20px;box-shadow:0 25px 80px #000}.ee-dialog-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:15px}.ee-dialog-head h2{font-size:17px}.ee-close{background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:7px 9px}.ee-checks,.ee-suggestions{display:grid;gap:9px}.ee-check,.ee-suggestion{padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--bg)}.ee-check.ok{border-color:#22c55e55}.ee-check.warn{border-color:#f59e0b66}.ee-suggestion-head{display:flex;justify-content:space-between;gap:8px}.ee-severity{font-size:9px;text-transform:uppercase;padding:3px 6px;border-radius:999px;background:var(--warning-dim);color:#fcd34d}.ee-diff{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:8px 0;font-size:11px}.ee-diff>div{padding:8px;border-radius:8px;background:#111827;white-space:pre-wrap;overflow-wrap:anywhere}.ee-score{font-size:28px;color:var(--primary);font-weight:800}.ee-status{padding:9px;border-radius:8px;background:var(--info-dim);font-size:11px}.ee-status.error{background:var(--danger-dim);color:#fca5a5}.ee-ai-grid{display:grid;grid-template-columns:1fr 220px;gap:10px}.ee-ai-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}@media(max-width:1050px){.ee-workspace{grid-template-columns:180px minmax(380px,1fr)}.ee-side.right{grid-column:1/-1;border-left:0;border-top:1px solid var(--border);display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.ee-side.right h2,.ee-side.right .ee-side-copy,.ee-side.right .ee-variable{grid-column:1/-1}}@media(max-width:760px){.ee-workspace{grid-template-columns:1fr}.ee-side.left{border-right:0;border-bottom:1px solid var(--border)}.ee-blocks{grid-template-columns:repeat(4,1fr)}.ee-stage{padding:12px}.ee-side.right{display:block}.ee-diff,.ee-ai-grid{grid-template-columns:1fr}.ee-top{align-items:flex-start;flex-direction:column}}
</style>
<div class="ee-shell">
    <div class="ee-top">
        <div><h1>Editor de e-mail</h1><p class="text-muted">Crie, revise, teste e publique e-mails responsivos.</p></div>
        <div class="ee-actions">
            <button class="btn btn-primary" type="button" id="aiCreateBtn" <?=$aiConfigured?'':'disabled'?>>✦ Criar com IA</button>
            <button class="btn btn-ghost" type="button" id="tipsBtn">Boas práticas</button>
            <button class="btn btn-ghost" type="button" id="aiBtn" <?=$aiConfigured?'':'disabled'?>>Verificar com IA</button>
            <button class="btn btn-ghost" type="button" id="testBtn">Enviar teste</button>
            <a class="btn btn-ghost" href="email_modelos.php">← Modelos</a>
        </div>
    </div>
    <?=email_admin_nav('templates')?>
    <?php if(isset($_GET['saved'])):?><div class="em-msg">Nova versão salva.</div><?php endif?>
    <?php if(isset($_GET['cloned'])):?><div class="em-msg">Modelo clonado. Revise e salve a nova versão se fizer ajustes.</div><?php endif?>
    <?php if($error):?><div class="em-msg em-error"><?=email_h($error)?></div><?php endif?>
    <section class="ee-ai-panel" id="aiDraftPanel">
        <div class="ee-ai-panel-head">
            <div>
                <h2>Rascunho criado com IA</h2>
                <p class="text-muted" id="aiDraftStatus">Revise o e-mail abaixo, peça ajustes ou valide antes de salvar.</p>
            </div>
            <button class="btn btn-ghost btn-xs" type="button" id="aiDraftHide">Ocultar</button>
        </div>
        <textarea id="aiInlinePrompt" placeholder="Ex.: deixe mais simples, troque o tom, adicione um botão discreto para {{link_area_membros}}, remova visual publicitário..."></textarea>
        <div class="ee-ai-actions">
            <button class="btn btn-primary" type="button" id="aiInlineAdjust">Pedir ajuste à IA</button>
            <button class="btn btn-ghost" type="button" id="aiInlineValidate">Validar qualidade</button>
            <button class="btn btn-ghost" type="button" id="aiInlineReview">Verificar com IA</button>
            <button class="btn btn-primary" type="button" id="aiInlineSave">Salvar modelo</button>
        </div>
    </section>
    <form method="post" id="editorForm">
        <input type="hidden" name="csrf" id="csrf" value="<?=email_h($csrf)?>">
        <input type="hidden" name="id" value="<?=$id?>">
        <input type="hidden" name="html" id="htmlField">
        <div class="ee-workspace">
            <aside class="ee-side left">
                <h2>Conteúdo</h2>
                <div class="ee-side-copy">Clique para inserir no final.</div>
                <div class="ee-blocks">
                    <?php foreach([['h2','T','Título'],['p','¶','Texto'],['button','▣','Botão'],['image','▧','Imagem'],['divider','-','Divisor'],['space','↕','Espaço'],['columns','▥','Colunas'],['unsubscribe','✓','Descadastro']] as [$key,$icon,$label]):?>
                        <button class="ee-block" type="button" data-block="<?=$key?>"><b><?=$icon?></b><?=$label?></button>
                    <?php endforeach?>
                </div>
                <h2 style="margin-top:20px">Variáveis</h2>
                <div class="ee-variable">
                    <?php foreach(['{{nome}}','{{email}}','{{turma}}','{{link_area_membros}}','{{link_descadastro}}','{{curso}}','{{data_aula}}'] as $var):?>
                        <button type="button" data-var="<?=email_h($var)?>"><?=email_h($var)?></button>
                    <?php endforeach?>
                </div>
            </aside>
            <main class="ee-center">
                <div class="ee-toolbar">
                    <div class="ee-view-actions">
                        <button class="btn btn-ghost btn-xs active" type="button" data-view="desktop">Desktop</button>
                        <button class="btn btn-ghost btn-xs" type="button" data-view="mobile">Celular</button>
                    </div>
                    <span id="contentStats">0 palavras</span>
                    <button class="btn btn-ghost btn-xs" type="button" id="toggleCode">&lt;/&gt; HTML/CSS</button>
                </div>
                <div class="ee-stage" id="stage"><div class="ee-paper" id="visual" contenteditable="true"><?=$template['html_content']?></div></div>
                <textarea class="ee-code" id="code" hidden></textarea>
            </main>
            <aside class="ee-side right">
                <h2>Configuração</h2>
                <div class="ee-side-copy">Identificação e conteúdo da caixa de entrada.</div>
                <div class="ee-field"><label>Nome interno</label><input name="name" id="name" value="<?=email_h($template['name'])?>" required></div>
                <div class="ee-field"><label>Assunto <span id="subjectCount"></span></label><input name="subject" id="subject" maxlength="250" value="<?=email_h($template['subject'])?>" required></div>
                <div class="ee-field"><label>Preheader <span id="preheaderCount"></span></label><input name="preheader" id="preheader" maxlength="250" value="<?=email_h($template['preheader']??'')?>"></div>
                <button class="btn btn-primary" type="submit">Salvar nova versão</button>
                <small class="text-muted">O descadastro é validado e incluído automaticamente quando necessário.</small>
            </aside>
        </div>
    </form>
</div>

<div class="ee-modal" id="aiCreateModal"><div class="ee-dialog">
    <div class="ee-dialog-head"><div><h2>Criar modelo com IA</h2><p class="text-muted">Descreva o objetivo, tom, links e se quer e-mail simples ou HTML customizado.</p></div><button class="ee-close" type="button">Fechar ×</button></div>
    <div class="ee-ai-grid">
        <div class="ee-field"><label>Prompt</label><textarea id="aiPrompt" rows="8" placeholder="Ex.: Crie um e-mail de boas-vindas para o aluno que entrou no treinamento gratuito de montagem de quadros elétricos. Quero cara de e-mail corporativo, com link {{link_area_membros}} e descadastro."></textarea></div>
        <div class="ee-field"><label>Inspirar em modelo salvo</label><select id="aiInspiration"><option value="0">Nenhum</option><?php foreach($templateOptions as $opt):?><option value="<?=(int)$opt['id']?>"><?=email_h($opt['name'].' · '.$opt['subject'])?></option><?php endforeach?></select></div>
    </div>
    <div class="ee-status" id="aiCreateStatus">A IA vai preencher nome, assunto, preheader e HTML. Depois você pode pedir ajuste ou aprovar.</div>
    <div class="ee-ai-actions">
        <button class="btn btn-primary" type="button" id="aiGenerate">Gerar rascunho</button>
        <button class="btn btn-ghost" type="button" id="aiAdjust">Pedir ajuste</button>
        <button class="btn btn-primary" type="button" id="aiApprove">Aprovar e usar</button>
    </div>
</div></div>
<div class="ee-modal" id="tipsModal"><div class="ee-dialog"><div class="ee-dialog-head"><div><h2>Boas práticas e verificação rápida</h2><p class="text-muted">Indicadores ajudam, mas não garantem entrega na caixa de entrada.</p></div><button class="ee-close" type="button">Fechar ×</button></div><div class="ee-checks" id="checks"></div><div style="margin-top:14px"><button class="btn btn-primary" type="button" id="tipsAi">Analisar também com IA</button></div></div></div>
<div class="ee-modal" id="testModal"><div class="ee-dialog" style="max-width:500px"><div class="ee-dialog-head"><h2>Enviar e-mail de teste</h2><button class="ee-close" type="button">Fechar ×</button></div><div class="em-form"><label>Destinatário<input type="email" id="testEmail" placeholder="voce@dominio.com"></label><div class="ee-status" id="testStatus">O teste usa dados fictícios e não entra nas métricas de campanha.</div><button class="btn btn-primary" type="button" id="sendTest" <?=$sendConfigured?'':'disabled'?>>Enviar pelo provedor ativo</button><?php if(!$sendConfigured):?><small class="em-bad">Configure o provedor de envio antes de enviar testes.</small><?php endif?></div></div></div>
<div class="ee-modal" id="aiModal"><div class="ee-dialog"><div class="ee-dialog-head"><div><h2>Revisão do e-mail com IA</h2><p class="text-muted">Aceite somente as alterações que fizerem sentido.</p></div><button class="ee-close" type="button">Fechar ×</button></div><div id="aiSummary" class="ee-status">Clique em analisar para revisar a versão atual.</div><div class="ee-suggestions" id="suggestions" style="margin-top:12px"></div><button class="btn btn-primary" type="button" id="runAi" style="margin-top:14px" <?=$aiConfigured?'':'disabled'?>>Analisar versão atual</button></div></div>

<script>
(()=>{const v=visual,code=document.getElementById('code'),htmlField=document.getElementById('htmlField'),subject=document.getElementById('subject'),preheader=document.getElementById('preheader'),nameInput=document.getElementById('name');const blocks={h2:'<h2 style="font-size:22px;line-height:1.25;margin:0 0 14px">Novo título</h2>',p:'<p style="font-size:16px;line-height:1.6">Digite seu texto aqui.</p>',button:'<p><a href="{{link_area_membros}}" style="display:inline-block;padding:11px 16px;background:#facc15;color:#111827;border-radius:6px;text-decoration:none;font-weight:bold">Acessar área de membros</a></p><p style="font-size:14px;line-height:1.5">Link direto: <a href="{{link_area_membros}}">{{link_area_membros}}</a></p>',image:'<p><img src="https://" alt="Descrição da imagem" style="display:block;max-width:100%;height:auto"></p>',divider:'<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0">',space:'<div style="height:28px"></div>',columns:'<table role="presentation" width="100%"><tr><td width="50%" style="padding:8px">Coluna 1</td><td width="50%" style="padding:8px">Coluna 2</td></tr></table>',unsubscribe:'<p style="font-size:12px;color:#64748b;text-align:center">Se não quiser mais receber nossos e-mails, clique aqui para se descadastrar: <a href="{{link_descadastro}}">{{link_descadastro}}</a></p>'};
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));function safeHtml(raw){const t=document.createElement('template');t.innerHTML=String(raw??'');t.content.querySelectorAll('script,iframe,object,embed,form').forEach(x=>x.remove());t.content.querySelectorAll('*').forEach(x=>[...x.attributes].forEach(a=>{const n=a.name.toLowerCase(),val=String(a.value).toLowerCase();if(n.startsWith('on')||val.includes('javascript:'))x.removeAttribute(a.name)}));return t.innerHTML}
const sync=()=>{if(!code.hidden)v.innerHTML=code.value;htmlField.value=v.innerHTML;const words=(v.innerText.trim().match(/\S+/g)||[]).length;contentStats.textContent=words+' palavras · '+Math.round(new Blob([v.innerHTML]).size/1024)+' KB';subjectCount.textContent=subject.value.length+'/250';preheaderCount.textContent=preheader.value.length+'/250'};v.addEventListener('input',sync);subject.addEventListener('input',sync);preheader.addEventListener('input',sync);document.querySelectorAll('[data-block]').forEach(b=>b.onclick=()=>{v.insertAdjacentHTML('beforeend',blocks[b.dataset.block]);v.focus();sync()});document.querySelectorAll('[data-var]').forEach(b=>b.onclick=()=>{v.focus();document.execCommand('insertText',false,b.dataset.var);sync()});document.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>{document.querySelectorAll('[data-view]').forEach(x=>x.classList.remove('active'));b.classList.add('active');stage.classList.toggle('mobile',b.dataset.view==='mobile')});toggleCode.onclick=()=>{if(code.hidden){code.value=v.innerHTML;code.hidden=false;stage.hidden=true}else{v.innerHTML=safeHtml(code.value);code.hidden=true;stage.hidden=false;sync()}};editorForm.onsubmit=()=>{sync();return true};
const open=id=>document.getElementById(id).classList.add('open');document.querySelectorAll('.ee-close').forEach(b=>b.onclick=()=>b.closest('.ee-modal').classList.remove('open'));document.querySelectorAll('.ee-modal').forEach(m=>m.onclick=e=>{if(e.target===m)m.classList.remove('open')});tipsBtn.onclick=()=>{runChecks();open('tipsModal')};testBtn.onclick=()=>open('testModal');aiBtn.onclick=()=>open('aiModal');aiCreateBtn.onclick=()=>open('aiCreateModal');tipsAi.onclick=()=>{tipsModal.classList.remove('open');open('aiModal')};
function runChecks(){sync();const html=v.innerHTML,text=v.innerText,upper=(subject.value+' '+text).toUpperCase(),links=[...v.querySelectorAll('a')],imgs=[...v.querySelectorAll('img')],hasUnsub=html.includes('{{amazonSESUnsubscribeUrl}}')||html.includes('{{link_descadastro}}'),promo=/\b(GR[ÁA]TIS|URGENTE|ATEN[ÇC][ÃA]O|[ÚU]LTIMA CHANCE|IMPERD[ÍI]VEL|PROMO[ÇC][ÃA]O|COMPRE AGORA|GANHE DINHEIRO|RENDA GARANTIDA|CLIQUE IMEDIATAMENTE|OPORTUNIDADE [ÚU]NICA)\b/.test(upper),upperRatio=(text.replace(/[^A-ZÁÉÍÓÚÂÊÔÃÕÇ]/g,'').length/Math.max(1,text.replace(/\s/g,'').length));const items=[['Descadastro visível',hasUnsub,'Inclua {{link_descadastro}} ou {{amazonSESUnsubscribeUrl}} com texto claro.'],['Assunto natural',subject.value.length>=18&&subject.value.length<=70&&!promo,'Evite assunto curto demais, caixa alta, urgência ou termos promocionais.'],['Preheader preenchido',preheader.value.length>=25&&preheader.value.length<=140,'Use de 25 a 140 caracteres para complementar o assunto.'],['Sem imagem quebrada',!imgs.some(i=>!i.getAttribute('src')||i.getAttribute('src')==='https://'||/placeholder|logo do professor/i.test(i.getAttribute('alt')||'')),'Remova placeholders e use imagem real HTTPS somente quando necessária.'],['Links em quantidade baixa',links.filter(a=>!String(a.href).includes('link_descadastro')&&!String(a.href).includes('amazonSESUnsubscribeUrl')).length<=2,'No aquecimento, use no máximo 1 link principal e o descadastro.'],['Links seguros',!links.some(a=>a.href&&!a.href.startsWith('https:')&&!a.href.includes('{{')),'Prefira HTTPS e evite encurtadores.'],['Linguagem sem pressão',!promo&&!/[!]{3,}/.test(text),'Evite promessas fortes, urgência artificial, emojis e pontuação excessiva.'],['Caixa alta controlada',upperRatio<0.18,'Reduza palavras em maiúsculas no assunto e corpo.'],['Origem do contato explicada',/recebeu este e-mail|se cadastrou|solicitou acesso|nossos conteúdos/i.test(text),'Explique por que a pessoa está recebendo o e-mail.'],['Assinatura presente',/Professor Emerson Leite/i.test(text)&&/professoremersonleite\.com/i.test(text),'Inclua assinatura simples com nome e site.'],['HTML leve',new Blob([html]).size<100*1024,'Mantenha o HTML abaixo de 100 KB.'],['Botão com link alternativo',!v.querySelector('a[style*="background"]')||text.includes('{{link_area_membros}}')||/Link direto/i.test(text),'Quando usar botão, mantenha também o link em texto.']];checks.innerHTML=items.map(x=>`<div class="ee-check ${x[1]?'ok':'warn'}"><strong>${x[1]?'✓':'⚠'} ${x[0]}</strong><div class="text-muted">${x[1]?'Verificação aprovada.':x[2]}</div></div>`).join('')}
async function post(action,extra={}){sync();const body=new URLSearchParams({csrf:csrf.value,action,name:nameInput.value,subject:subject.value,preheader:preheader.value,html:v.innerHTML,...extra});const r=await fetch('email_editor.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Falha na operação.');return d}
sendTest.onclick=async()=>{testStatus.className='ee-status';testStatus.textContent='Enviando...';sendTest.disabled=true;try{const d=await post('send_test',{test_email:testEmail.value});testStatus.textContent='Teste aceito pelo provedor. ID: '+d.message_id}catch(e){testStatus.className='ee-status error';testStatus.textContent=e.message}finally{sendTest.disabled=false}};
function showAiPanel(message){aiDraftStatus.textContent=message||'Revise o e-mail abaixo, peça ajustes ou valide antes de salvar.';aiDraftPanel.classList.add('open');aiDraftPanel.scrollIntoView({behavior:'smooth',block:'start'})}
function applyDraft(d){nameInput.value=d.name||nameInput.value;subject.value=d.subject||subject.value;preheader.value=d.preheader||preheader.value;v.innerHTML=safeHtml(d.html||v.innerHTML);if(!code.hidden)code.value=v.innerHTML;sync();runChecks();showAiPanel(d.notes||'Rascunho criado e aplicado no editor.')}
async function runAiDraft(action,source='modal'){const status=source==='inline'?aiDraftStatus:aiCreateStatus;status.className=source==='inline'?'text-muted':'ee-status';status.textContent='Gerando ajuste...';aiGenerate.disabled=aiAdjust.disabled=aiInlineAdjust.disabled=true;try{const prompt=source==='inline'?aiInlinePrompt.value:aiPrompt.value;const d=await post(action,{prompt,inspiration_id:aiInspiration.value});applyDraft(d.draft);if(source==='modal')aiCreateModal.classList.remove('open');if(source==='inline')aiInlinePrompt.value=''}catch(e){if(source==='inline'){aiDraftStatus.textContent=e.message}else{aiCreateStatus.className='ee-status error';aiCreateStatus.textContent=e.message}}finally{aiGenerate.disabled=aiAdjust.disabled=aiInlineAdjust.disabled=false}}
aiGenerate.onclick=()=>runAiDraft('ai_generate');aiAdjust.onclick=()=>runAiDraft('ai_adjust');aiApprove.onclick=()=>{aiCreateModal.classList.remove('open');showAiPanel('Rascunho aprovado para revisão final. Valide e salve quando estiver pronto.')};aiInlineAdjust.onclick=()=>runAiDraft('ai_adjust','inline');aiInlineValidate.onclick=()=>{runChecks();open('tipsModal')};aiInlineReview.onclick=()=>open('aiModal');aiInlineSave.onclick=()=>editorForm.requestSubmit();aiDraftHide.onclick=()=>aiDraftPanel.classList.remove('open');
runAi.onclick=async()=>{aiSummary.className='ee-status';aiSummary.textContent='Analisando conteúdo, ortografia e entregabilidade...';suggestions.innerHTML='';runAi.disabled=true;try{const d=await post('ai_review'),review=d.review;aiSummary.innerHTML=`<span class="ee-score">${review.score}/100</span><div>${esc(review.summary)}</div>`;suggestions.innerHTML=review.suggestions.length?review.suggestions.map((s,i)=>`<article class="ee-suggestion" data-i="${i}"><div class="ee-suggestion-head"><strong>${esc(s.title)}</strong><span class="ee-severity">${esc(s.severity)} · ${esc(s.category)}</span></div><p class="text-muted">${esc(s.explanation)}</p><div class="ee-diff"><div><b>Atual</b><br>${esc(s.original)}</div><div><b>Sugestão</b><br>${esc(s.replacement)}</div></div><button class="btn btn-primary btn-xs" type="button" data-apply="${i}">Aceitar esta alteração</button></article>`).join(''):'<div class="em-msg">Nenhuma alteração objetiva foi sugerida.</div>';suggestions.querySelectorAll('[data-apply]').forEach(b=>b.onclick=()=>applySuggestion(review.suggestions[+b.dataset.apply],b.closest('.ee-suggestion')))}catch(e){aiSummary.className='ee-status error';aiSummary.textContent=e.message}finally{runAi.disabled=false}};
function applySuggestion(s,card){const el=s.target==='subject'?subject:s.target==='preheader'?preheader:null,replacement=s.target==='html'?safeHtml(s.replacement):String(s.replacement??'');if(el){if(s.original&&el.value.includes(s.original))el.value=el.value.replace(s.original,replacement);else el.value=replacement}else{if(s.original&&!v.innerHTML.includes(s.original)){alert('O trecho original não foi encontrado. Aplique manualmente.');return}v.innerHTML=v.innerHTML.replace(s.original,replacement)}card.innerHTML='<strong>✓ Alteração aplicada</strong>';sync()}sync();<?php if(isset($_GET['ai'])):?>open('aiCreateModal');<?php endif?>})();
</script>
<?php include __DIR__.'/_footer.php'; ?>
