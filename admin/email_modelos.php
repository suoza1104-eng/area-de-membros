<?php
declare(strict_types=1);
require_once __DIR__.'/../app/email_marketing.php';
proteger_admin();
$pdo=getPDO();
email_marketing_ensure_schema($pdo);
$csrf=email_admin_csrf();
$message=$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        email_check_csrf();
        $action=(string)($_POST['action']??'');
        $id=(int)($_POST['id']??0);
        $admin=(string)($_SESSION['equipe_nome']??'Administrador');
        if($action==='clone'){
            $newId=email_template_clone($pdo,$id,$admin);
            header('Location: email_editor.php?id='.$newId.'&cloned=1');
            exit;
        }
        if($action==='delete'){
            email_template_delete($pdo,$id);
            $message='Modelo excluído.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$templates=$pdo->query("SELECT t.*,v.version_number,(SELECT COUNT(*) FROM email_messages m WHERE m.template_version_id=t.current_version_id) sends,(SELECT COUNT(*) FROM email_messages m WHERE m.template_version_id=t.current_version_id AND m.delivered_at IS NOT NULL) delivered,(SELECT COUNT(*) FROM email_messages m WHERE m.template_version_id=t.current_version_id AND m.first_clicked_at IS NOT NULL) clicks FROM email_templates t LEFT JOIN email_template_versions v ON v.id=t.current_version_id WHERE t.status<>'deleted' ORDER BY t.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC)?:[];
$aiConfigured=trim((string)get_setting('whatsapp_ai_openai_api_key',''))!=='';
$menu='email_marketing';
$page_title='Modelos de e-mail';
include __DIR__.'/_header.php';
echo email_admin_styles();
?>
<div class="em">
    <div class="em-head">
        <div>
            <h1>Modelos de e-mail</h1>
            <p class="text-muted">Conteúdos reutilizáveis, versionados e revisados para entregabilidade.</p>
        </div>
        <div class="em-actions">
            <a class="btn btn-ghost" href="email_editor.php?ai=1" <?=$aiConfigured?'':'aria-disabled="true"'?>>✦ Criar com IA</a>
            <a class="btn btn-primary" href="email_editor.php">+ Criar modelo</a>
        </div>
    </div>
    <?=email_admin_nav('templates')?>
    <?php if($message):?><div class="em-msg"><?=email_h($message)?></div><?php endif?>
    <?php if($error):?><div class="em-msg em-error"><?=email_h($error)?></div><?php endif?>
    <?php if(!$aiConfigured):?><div class="em-msg em-error">A criação com IA fica disponível após configurar a chave OpenAI.</div><?php endif?>
    <section class="em-card">
        <div class="em-table">
            <table>
                <thead>
                    <tr><th>Modelo</th><th>Assunto</th><th>Versão</th><th>Envios</th><th>Entregues</th><th>Cliques</th><th>Ações</th></tr>
                </thead>
                <tbody>
                    <?php foreach($templates as $t):?>
                    <tr>
                        <td><strong><?=email_h($t['name'])?></strong><div class="text-muted"><?=email_h(date('d/m/Y H:i',strtotime($t['updated_at'])))?></div></td>
                        <td><?=email_h($t['subject'])?></td>
                        <td><?=$t['version_number']?'v'.(int)$t['version_number']:'-'?></td>
                        <td><?=(int)$t['sends']?></td>
                        <td><?=(int)$t['delivered']?></td>
                        <td><?=(int)$t['clicks']?></td>
                        <td>
                            <div class="em-actions">
                                <a class="btn btn-ghost btn-xs" href="email_editor.php?id=<?=(int)$t['id']?>">Editar</a>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?=email_h($csrf)?>">
                                    <input type="hidden" name="action" value="clone">
                                    <input type="hidden" name="id" value="<?=(int)$t['id']?>">
                                    <button class="btn btn-ghost btn-xs" type="submit">Clonar</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Excluir este modelo da lista? Campanhas já enviadas continuam no histórico.')">
                                    <input type="hidden" name="csrf" value="<?=email_h($csrf)?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?=(int)$t['id']?>">
                                    <button class="btn btn-danger btn-xs" type="submit">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach?>
                    <?php if(!$templates):?><tr><td colspan="7" class="text-muted">Nenhum modelo criado.</td></tr><?php endif?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__.'/_footer.php'; ?>
