<?php
declare(strict_types=1);
require_once __DIR__.'/../app/email_marketing.php';
proteger_admin();

$pdo=getPDO();
email_marketing_ensure_schema($pdo);
$message=$error='';
$connectionResult=null;
$csrf=email_admin_csrf();

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        email_check_csrf();
        $action=(string)($_POST['action']??'save_settings');
        if($action==='save_credentials'){
            email_save_aws_credentials((string)($_POST['aws_access_key_id']??''),(string)($_POST['aws_secret_access_key']??''),(string)($_POST['aws_session_token']??''));
            $message='Credenciais AWS salvas no cofre privado.';
        }elseif($action==='delete_credentials'){
            email_delete_aws_credentials();
            $message='Credenciais AWS locais removidas.';
        }elseif($action==='test_connection'){
            $connectionResult=email_test_aws_connection((string)($_POST['region']??'us-east-1'));
            $message='Conexão com Amazon SES validada.';
        }elseif($action==='save_resend_credentials'){
            email_save_resend_credentials((string)($_POST['resend_api_key']??''));
            $message='API key Resend salva no cofre privado.';
        }elseif($action==='delete_resend_credentials'){
            email_delete_resend_credentials();
            $message='API key Resend local removida.';
        }elseif($action==='save_resend_webhook_secret'){
            email_save_resend_webhook_secret((string)($_POST['resend_webhook_secret']??''));
            $message='Signing secret do webhook Resend salvo no cofre privado.';
        }elseif($action==='delete_resend_webhook_secret'){
            email_delete_resend_webhook_secret();
            $message='Signing secret do webhook Resend removido.';
        }elseif($action==='test_resend_send'){
            $s=email_settings($pdo);
            $s['provider_active']='resend';
            $id=email_send_test($s,(string)($_POST['resend_test_email']??''),'Teste Resend','Mensagem de teste','<p>Envio de teste pelo Resend.</p><p><a href="{{amazonSESUnsubscribeUrl}}">Descadastrar</a></p>');
            email_save_settings($pdo,['resend_last_test_status'=>'success','resend_last_error'=>'','resend_last_success_at'=>date('Y-m-d H:i:s')]);
            $message='Teste Resend enviado. ID: '.$id;
        }else{
            email_save_settings($pdo,[
                'engine_enabled'=>isset($_POST['engine_enabled'])?'1':'0',
                'provider_active'=>$_POST['provider_active']??'aws_ses',
                'region'=>$_POST['region']??'',
                'configuration_set'=>$_POST['configuration_set']??'',
                'contact_list'=>$_POST['contact_list']??'',
                'default_topic'=>$_POST['default_topic']??'',
                'from_email'=>$_POST['from_email']??'',
                'from_name'=>$_POST['from_name']??'',
                'reply_to'=>$_POST['reply_to']??'',
                'max_per_minute'=>(string)max(1,(int)($_POST['max_per_minute']??10)),
                'batch_size'=>(string)max(1,min(100,(int)($_POST['batch_size']??25))),
                'resend_domain'=>$_POST['resend_domain']??'',
                'resend_from_name'=>$_POST['resend_from_name']??'',
                'resend_from_email'=>$_POST['resend_from_email']??'',
                'resend_reply_to'=>$_POST['resend_reply_to']??'',
                'resend_rate_limit_per_minute'=>(string)max(1,(int)($_POST['resend_rate_limit_per_minute']??5)),
                'resend_batch_size'=>(string)max(1,min(100,(int)($_POST['resend_batch_size']??25))),
                'review_prompt'=>$_POST['review_prompt']??''
            ]);
            $message='Configurações salvas.';
        }
    }catch(Throwable $e){
        if(($action??'')==='test_resend_send')email_save_settings($pdo,['resend_last_test_status'=>'error','resend_last_error'=>mb_substr($e->getMessage(),0,500)]);
        $error=$e->getMessage();
    }
}

$s=email_settings($pdo);
$aws=email_aws_credentials();
$resend=email_resend_credentials();
$resendWebhook=email_resend_webhook_secret();
$hasOpenAI=trim((string)get_setting('whatsapp_ai_openai_api_key',''))!=='';
$sourceLabels=['environment'=>'Variáveis do servidor','private_file'=>'Cofre privado do painel','none'=>'Não configuradas'];
$menu='email_marketing';
$page_title='Configurações de e-mail';
include __DIR__.'/_header.php';
echo email_admin_styles();
?>
<style>
.provider-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.status-box{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px;border:1px solid var(--border);border-radius:10px;background:var(--bg)}.dot{width:9px;height:9px;border-radius:50%;background:var(--danger)}.dot.ok{background:var(--success)}.secret-note{padding:10px;border-radius:9px;background:var(--info-dim);color:#bae6fd;font-size:11px;line-height:1.45}.result{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.result>div{padding:10px;border:1px solid var(--border);border-radius:9px;background:var(--bg)}.code-line{display:block;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--bg);overflow:auto}@media(max-width:700px){.result{grid-template-columns:1fr}}
</style>
<div class="em">
  <div class="em-head"><div><h1>Configurações</h1><p class="text-muted">Motor de envios, provedores, credenciais privadas e webhooks.</p></div></div>
  <?=email_admin_nav('settings')?>
  <?php if($message):?><div class="em-msg"><?=email_h($message)?></div><?php endif?>
  <?php if($error):?><div class="em-msg em-error"><?=email_h($error)?></div><?php endif?>

  <form method="post" class="em-form">
    <input type="hidden" name="csrf" value="<?=email_h($csrf)?>">
    <input type="hidden" name="action" value="save_settings">
    <section class="em-card">
      <h2>Motor de envio</h2>
      <div class="provider-row">
        <label><span><input type="checkbox" name="engine_enabled" value="1" <?=($s['engine_enabled']??'0')==='1'?'checked':''?>> Ativar disparos</span></label>
        <label>Provedor ativo
          <select name="provider_active">
            <option value="aws_ses" <?=($s['provider_active']??'aws_ses')==='aws_ses'?'selected':''?>>Amazon SES</option>
            <option value="resend" <?=($s['provider_active']??'aws_ses')==='resend'?'selected':''?>>Resend</option>
          </select>
        </label>
      </div>
    </section>

    <div class="provider-row">
      <section class="em-card">
        <h2>Amazon SES</h2>
        <label>Região AWS<input name="region" value="<?=email_h($s['region']??'sa-east-1')?>" required></label>
        <label>Configuration set<input name="configuration_set" value="<?=email_h($s['configuration_set']??'')?>"></label>
        <label>Contact list<input name="contact_list" value="<?=email_h($s['contact_list']??'')?>"></label>
        <label>Tópico padrão<input name="default_topic" value="<?=email_h($s['default_topic']??'marketing')?>"></label>
        <label>Nome do remetente<input name="from_name" value="<?=email_h($s['from_name']??'')?>"></label>
        <label>E-mail remetente<input type="email" name="from_email" value="<?=email_h($s['from_email']??'')?>"></label>
        <label>Responder para<input type="email" name="reply_to" value="<?=email_h($s['reply_to']??'')?>"></label>
        <label>Máximo por minuto<input type="number" min="1" name="max_per_minute" value="<?=email_h($s['max_per_minute']??'10')?>"></label>
        <label>Tamanho do lote<input type="number" min="1" max="100" name="batch_size" value="<?=email_h($s['batch_size']??'25')?>"></label>
        <p class="text-muted">Webhook SES:</p><code class="code-line"><?=email_h(BASE_URL)?>/email_ses_webhook.php</code>
      </section>

      <section class="em-card">
        <h2>Resend</h2>
        <label>Domínio<input name="resend_domain" value="<?=email_h($s['resend_domain']??'professoremersonleite.site')?>"></label>
        <label>Nome do remetente<input name="resend_from_name" value="<?=email_h($s['resend_from_name']??'Professor Emerson Leite')?>"></label>
        <label>E-mail remetente<input type="email" name="resend_from_email" value="<?=email_h($s['resend_from_email']??'contato@professoremersonleite.site')?>"></label>
        <label>Responder para<input type="email" name="resend_reply_to" value="<?=email_h($s['resend_reply_to']??'marketingemersonleite@gmail.com')?>"></label>
        <label>Limite por minuto<input type="number" min="1" name="resend_rate_limit_per_minute" value="<?=email_h($s['resend_rate_limit_per_minute']??'5')?>"></label>
        <label>Tamanho do lote<input type="number" min="1" max="100" name="resend_batch_size" value="<?=email_h($s['resend_batch_size']??'25')?>"></label>
        <p class="text-muted">Webhook Resend:</p><code class="code-line"><?=email_h(BASE_URL)?>/email_resend_webhook.php</code>
        <p class="text-muted">Signing secret: <strong class="<?=$resendWebhook['configured']?'em-ok':'em-bad'?>"><?=$resendWebhook['configured']?'configurado':'não configurado'?></strong> <?=!empty($resendWebhook['masked'])?'('.email_h($resendWebhook['masked']).')':''?></p>
        <p class="text-muted">Último webhook: <?=email_h($s['resend_last_webhook_at']??'-')?> <?=email_h($s['resend_last_webhook_event']??'')?> <?=!empty($s['resend_last_webhook_error'])?'Erro: '.email_h($s['resend_last_webhook_error']):''?></p>
      </section>
    </div>

    <section class="em-card">
      <h2>Revisão de e-mail com IA</h2>
      <p class="text-muted">OpenAI: <strong class="<?=$hasOpenAI?'em-ok':'em-bad'?>"><?=$hasOpenAI?'configurada':'não configurada'?></strong></p>
      <label>Instruções de revisão<textarea name="review_prompt" rows="8"><?=email_h($s['review_prompt']??'')?></textarea></label>
    </section>
    <div><button class="btn btn-primary" type="submit">Salvar configurações</button></div>
  </form>

  <div class="provider-row">
    <section class="em-card">
      <div class="status-box"><div><strong>Credenciais AWS</strong><div class="text-muted"><?=email_h($sourceLabels[$aws['source']]??$aws['source'])?></div></div><span class="dot <?=$aws['configured']?'ok':''?>"></span></div>
      <form method="post" class="em-form" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?=email_h($csrf)?>"><input type="hidden" name="action" value="save_credentials">
        <label>Access Key ID<input name="aws_access_key_id" autocomplete="off" placeholder="<?=$aws['configured']?'Configurada - informe para substituir':'AKIA...'?>"></label>
        <label>Secret Access Key<input type="password" name="aws_secret_access_key" autocomplete="new-password" placeholder="<?=$aws['configured']?'Configurada - informe para substituir':'Secret key'?>"></label>
        <label>Session Token<textarea name="aws_session_token" rows="3" autocomplete="off"></textarea></label>
        <div class="secret-note">As chaves ficam em arquivo privado ou em variáveis de ambiente. Elas nunca são exibidas de volta no painel.</div>
        <button class="btn btn-primary" type="submit">Salvar credenciais AWS</button>
      </form>
      <form method="post" class="em-actions" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?=email_h($csrf)?>"><input type="hidden" name="action" value="test_connection"><input type="hidden" name="region" value="<?=email_h($s['region']??'sa-east-1')?>">
        <button class="btn btn-ghost" type="submit" <?=$aws['configured']?'':'disabled'?>>Testar SES</button>
      </form>
      <?php if($aws['source']==='private_file'):?><form method="post" style="margin-top:10px" onsubmit="return confirm('Remover as credenciais AWS salvas pelo painel?')"><input type="hidden" name="csrf" value="<?=email_h($csrf)?>"><input type="hidden" name="action" value="delete_credentials"><button class="btn btn-danger" type="submit">Remover credenciais AWS</button></form><?php endif?>
      <?php if($connectionResult):?><div class="result" style="margin-top:10px"><div><small>Envio</small><strong><?=$connectionResult['sending_enabled']?'Habilitado':'Desabilitado'?></strong></div><div><small>Acesso</small><strong><?=$connectionResult['production_access']?'Produção':'Sandbox'?></strong></div><div><small>Status</small><strong><?=email_h($connectionResult['enforcement_status']?:'Normal')?></strong></div></div><?php endif?>
    </section>

    <section class="em-card">
      <div class="status-box"><div><strong>API key Resend</strong><div class="text-muted"><?=email_h($sourceLabels[$resend['source']]??$resend['source'])?> <?=$resend['masked']?'('.email_h($resend['masked']).')':''?></div></div><span class="dot <?=$resend['configured']?'ok':''?>"></span></div>
      <form method="post" class="em-form" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?=email_h($csrf)?>"><input type="hidden" name="action" value="save_resend_credentials">
        <label>API key<input type="password" name="resend_api_key" autocomplete="new-password" placeholder="<?=$resend['configured']?'Configurada - informe para substituir':'re_...'?>"></label>
        <div class="secret-note">A API key é mascarada no painel e gravada fora da pasta pública. Use variáveis de ambiente se preferir prioridade no servidor.</div>
        <button class="btn btn-primary" type="submit">Salvar API key Resend</button>
      </form>
      <form method="post" class="em-form" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?=email_h($csrf)?>"><input type="hidden" name="action" value="test_resend_send">
        <label>E-mail de teste<input type="email" name="resend_test_email" placeholder="voce@dominio.com"></label>
        <button class="btn btn-ghost" type="submit" <?=$resend['configured']?'':'disabled'?>>Enviar teste Resend</button>
      </form>
      <?php if($resend['source']==='private_file'):?><form method="post" style="margin-top:10px" onsubmit="return confirm('Remover a API key Resend salva pelo painel?')"><input type="hidden" name="csrf" value="<?=email_h($csrf)?>"><input type="hidden" name="action" value="delete_resend_credentials"><button class="btn btn-danger" type="submit">Remover API key Resend</button></form><?php endif?>
      <?php if(!empty($s['resend_last_test_status'])):?><p class="text-muted" style="margin-top:10px">Último teste: <?=email_h($s['resend_last_test_status'])?> <?=email_h($s['resend_last_success_at']??'')?> <?=email_h($s['resend_last_error']??'')?></p><?php endif?>
      <form method="post" class="em-form" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?=email_h($csrf)?>"><input type="hidden" name="action" value="save_resend_webhook_secret">
        <label>Signing secret do webhook<input type="password" name="resend_webhook_secret" autocomplete="new-password" placeholder="<?=$resendWebhook['configured']?'Configurado - informe para substituir':'whsec_...'?>"></label>
        <div class="secret-note">Use o Signing Secret do endpoint no painel Resend. O valor não é exibido no HTML nem salvo em logs.</div>
        <button class="btn btn-primary" type="submit">Salvar signing secret</button>
      </form>
      <?php if($resendWebhook['source']==='private_file'):?><form method="post" style="margin-top:10px" onsubmit="return confirm('Remover o signing secret Resend salvo pelo painel?')"><input type="hidden" name="csrf" value="<?=email_h($csrf)?>"><input type="hidden" name="action" value="delete_resend_webhook_secret"><button class="btn btn-danger" type="submit">Remover signing secret</button></form><?php endif?>
    </section>
  </div>
</div>
<?php include __DIR__.'/_footer.php';
