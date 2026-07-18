<?php
declare(strict_types=1);

function automation_trigger_seed_catalog(): array
{
    return [
        ['code'=>'INSCRITO','label'=>'Aluno inscrito','category'=>'Aluno','badge'=>'Aluno','description'=>'Disparado quando um novo aluno se cadastra na area de membros pela primeira vez.'],
        ['code'=>'INSCRICAO_GRATUITA','label'=>'Inscricao gratuita','category'=>'Aluno','badge'=>'Aluno','description'=>'Aluno recebeu acesso temporario conforme o prazo configurado na turma.'],
        ['code'=>'INSCRICAO_VITALICIA','label'=>'Inscricao vitalicia','category'=>'Aluno','badge'=>'Aluno','description'=>'Aluno recebeu acesso vitalicio por pagamento ou concessao.'],
        ['code'=>'ACESSO_VITALICIO_LIBERADO','label'=>'Acesso vitalicio liberado','category'=>'Aluno','badge'=>'Aluno','description'=>'O acesso vitalicio foi efetivamente liberado para o aluno.'],
        ['code'=>'REINSCRITO','label'=>'Aluno reinscrito','category'=>'Aluno','badge'=>'Aluno','description'=>'Disparado quando um aluno ja existente se inscreve novamente.'],
        ['code'=>'PRIMEIRO_LOGIN','label'=>'Primeiro login','category'=>'Aluno','badge'=>'Aluno','description'=>'Disparado uma unica vez, na primeira vez que o aluno acessa a plataforma.'],
        ['code'=>'ASSISTIU_ALGUMA_AULA','label'=>'Assistiu alguma aula','category'=>'Aluno','badge'=>'Aluno','description'=>'Disparado quando o aluno assiste pelo menos 10 segundos de qualquer aula.'],
        ['code'=>'CONCLUIU_TRILHA','label'=>'Concluiu a trilha','category'=>'Aluno','badge'=>'Aluno','description'=>'Disparado quando o aluno finaliza todas as aulas obrigatorias.'],
        ['code'=>'RETORNO_AGENDADO','label'=>'Retorno agendado','category'=>'Aluno','badge'=>'Aluno','description'=>'Disparado pelo cron quando um retorno de contato chega na data e hora marcada.'],
        ['code'=>'APP_INSTALADO','label'=>'Aplicativo instalado','category'=>'Aplicativo','badge'=>'App','description'=>'Aplicativo instalado pelo aluno.'],
        ['code'=>'APP_NOTIFICACOES_AUTORIZADAS','label'=>'Notificacoes autorizadas','category'=>'Aplicativo','badge'=>'App','description'=>'Aluno autorizou notificacoes do aplicativo.'],
        ['code'=>'APP_DESINSTALADO_DETECTADO','label'=>'Desinstalacao detectada','category'=>'Aplicativo','badge'=>'App','description'=>'Aplicativo desinstalado ou inativo detectado.'],
        ['code'=>'CERT_EMITIDO','label'=>'Certificado emitido','category'=>'Certificado','badge'=>'Certificado','description'=>'Disparado quando o aluno acerta a senha e o certificado e gerado.'],
        ['code'=>'REENVIO_CERTIFICADO','label'=>'Reenvio de certificado','category'=>'Certificado','badge'=>'Certificado','description'=>'Disparado quando o certificado e reenviado pelo admin ou por webhook de entrada.'],
        ['code'=>'CERT_SENHA_ERRADA','label'=>'Senha de certificado incorreta','category'=>'Certificado','badge'=>'Certificado','description'=>'Disparado quando o aluno tenta uma senha invalida.'],
        ['code'=>'LIVE_TURMA','label'=>'Evento da turma ao vivo','category'=>'Live','badge'=>'Live','description'=>'Regra global: disparada para cada aluno da turma quando a data/hora de disparo chega.'],
        ['code'=>'LIVE_LEMBRETE_AGENDADO','label'=>'X tempo antes da live','category'=>'Live','badge'=>'Live','description'=>'Agenda o inicio do fluxo com antecedencia em relacao a live da turma.'],
        ['code'=>'LIVE_REAGENDADA','label'=>'Live reagendada','category'=>'Live','badge'=>'Live','description'=>'Disparado quando o aluno ou suporte confirma uma nova data de live de repescagem.'],
        ['code'=>'LIVE_REAGENDAMENTO_LEMBRETE','label'=>'Lembrete de live reagendada','category'=>'Live','badge'=>'Live','description'=>'Disparado pelo cron no horario configurado antes/depois da live reagendada.'],
        ['code'=>'LIVE_REAGENDAMENTO_EXPIRADO','label'=>'Reagendamento expirado','category'=>'Live','badge'=>'Live','description'=>'Disparado quando a live reagendada passou e o aluno nao entrou.'],
        ['code'=>'LIVE_ACESSOU','label'=>'Acessou a live','category'=>'Live','badge'=>'Live','description'=>'Aluno acessou a sala da live via webhook externo configurado em Eventos Live.'],
        ['code'=>'LIVE_OFERTA','label'=>'Oferta da live','category'=>'Live','badge'=>'Live','description'=>'Aluno ficou ate o momento da oferta.'],
        ['code'=>'LIVE_COMPRA','label'=>'Compra na live','category'=>'Live','badge'=>'Live','description'=>'Aluno clicou no botao de compra durante a live.'],
        ['code'=>'LIVE_EVENTO','label'=>'Evento de live','category'=>'Live','badge'=>'Live','description'=>'Evento customizado vindo de Eventos Live.'],
        ['code'=>'WHATSAPP_GRUPO_ENTROU','label'=>'Entrou no grupo de WhatsApp','category'=>'WhatsApp Grupos','badge'=>'WhatsApp','description'=>'Aluno identificado entrou em grupo monitorado.'],
        ['code'=>'WHATSAPP_GRUPO_SAIU','label'=>'Saiu do grupo de WhatsApp','category'=>'WhatsApp Grupos','badge'=>'WhatsApp','description'=>'Aluno identificado saiu por conta propria de grupo monitorado.'],
        ['code'=>'WHATSAPP_GRUPO_REMOVIDO_ADMIN','label'=>'Removido do grupo por admin','category'=>'WhatsApp Grupos','badge'=>'WhatsApp','description'=>'Aluno identificado foi removido por admin de grupo monitorado.'],
        ['code'=>'WHATSAPP_BLACKLIST_DETECTADO','label'=>'Blacklist detectada no WhatsApp','category'=>'WhatsApp Grupos','badge'=>'WhatsApp','description'=>'Numero em blacklist entrou no grupo monitorado.'],
        ['code'=>'TAG_ADICIONADA','label'=>'Recebeu tag','category'=>'Tags','badge'=>'Tag','description'=>'Aluno recebeu uma tag configurada.'],
        ['code'=>'TAG_REMOVIDA','label'=>'Perdeu tag','category'=>'Tags','badge'=>'Tag','description'=>'Aluno perdeu uma tag configurada.'],
        ['code'=>'AVANCO_CURSO','label'=>'Alcancou avanco no curso','category'=>'Curso','badge'=>'Curso','description'=>'Aluno alcancou um percentual minimo de avanco no curso.'],
        ['code'=>'BOTAO_SUPORTE_CLICADO','label'=>'Clicou no suporte','category'=>'Interacoes','badge'=>'Suporte','description'=>'Aluno clicou no botao de suporte.'],
        ['code'=>'WEBHOOK_RECEBIDO','label'=>'Webhook recebido','category'=>'API','badge'=>'API','description'=>'Evento externo recebido pela central de automacoes.'],
    ];
}

function automation_triggers_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_triggers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(100) NOT NULL,
        label VARCHAR(180) NOT NULL,
        description VARCHAR(500) NULL,
        category VARCHAR(80) NOT NULL DEFAULT 'Geral',
        badge VARCHAR(40) NOT NULL DEFAULT 'Evento',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_system TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 1000,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_automation_triggers_code (code),
        KEY idx_automation_triggers_active (is_active, category, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $st = $pdo->prepare("INSERT INTO automation_triggers(code,label,description,category,badge,is_active,is_system,sort_order)
        VALUES(:code,:label,:description,:category,:badge,1,1,:sort_order)
        ON DUPLICATE KEY UPDATE
            label=VALUES(label),
            description=VALUES(description),
            category=VALUES(category),
            badge=VALUES(badge),
            is_system=1,
            sort_order=VALUES(sort_order)");
    foreach (automation_trigger_seed_catalog() as $i => $row) {
        $st->execute([
            'code'=>$row['code'],
            'label'=>$row['label'],
            'description'=>$row['description'],
            'category'=>$row['category'],
            'badge'=>$row['badge'],
            'sort_order'=>($i + 1) * 10,
        ]);
    }
}

function automation_trigger_rows(?PDO $pdo = null, bool $includeLessons = true): array
{
    $rows = [];
    if ($pdo) {
        automation_triggers_ensure_schema($pdo);
        $rows = $pdo->query("SELECT code,label,description,category,badge FROM automation_triggers WHERE is_active=1 ORDER BY category,sort_order,label")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $rows = automation_trigger_seed_catalog();
    }
    if ($pdo && $includeLessons) {
        try {
            $st = $pdo->query("SELECT id,titulo FROM lessons ORDER BY ordem ASC,id ASC");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lesson) {
                $id = (int)($lesson['id'] ?? 0);
                if ($id <= 0) continue;
                $code = 'VIU_AULA_' . $id;
                $title = trim((string)($lesson['titulo'] ?? 'Aula sem titulo'));
                $rows[] = ['code'=>$code,'label'=>$code . ' - ' . $title,'description'=>$title,'category'=>'Aulas','badge'=>'Aula'];
            }
        } catch (Throwable $e) {}
    }
    return $rows;
}

function automation_trigger_options(?PDO $pdo = null): array
{
    if (!$pdo && function_exists('getPDO')) {
        try { $pdo = getPDO(); } catch (Throwable $e) { $pdo = null; }
    }
    $out = [];
    foreach (automation_trigger_rows($pdo) as $row) $out[(string)$row['code']] = (string)$row['label'];
    return $out;
}

function automation_trigger_groups(?PDO $pdo = null): array
{
    if (!$pdo && function_exists('getPDO')) {
        try { $pdo = getPDO(); } catch (Throwable $e) { $pdo = null; }
    }
    $groups = [];
    foreach (automation_trigger_rows($pdo) as $row) {
        $category = (string)($row['category'] ?? 'Geral');
        if (!isset($groups[$category])) $groups[$category] = ['label'=>$category,'items'=>[]];
        $groups[$category]['items'][] = [
            'code'=>(string)$row['code'],
            'label'=>(string)$row['label'],
            'tag'=>(string)($row['badge'] ?? 'Evento'),
            'desc'=>(string)($row['description'] ?? $row['label']),
        ];
    }
    return array_values($groups);
}
