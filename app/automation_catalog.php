<?php
declare(strict_types=1);

/** Catálogo único: push e e-mail devem consumir esta mesma lista. */
function automation_trigger_options(): array
{
    return [
        'INSCRITO'=>'Aluno inscrito','INSCRICAO_GRATUITA'=>'Inscrição gratuita','INSCRICAO_VITALICIA'=>'Inscrição vitalícia',
        'REINSCRITO'=>'Aluno reinscrito','PRIMEIRO_LOGIN'=>'Primeiro login','ASSISTIU_ALGUMA_AULA'=>'Assistiu alguma aula',
        'CONCLUIU_TRILHA'=>'Concluiu a trilha','APP_INSTALADO'=>'Aplicativo instalado','APP_NOTIFICACOES_AUTORIZADAS'=>'Notificações autorizadas',
        'APP_DESINSTALADO_DETECTADO'=>'Desinstalação detectada','CERT_EMITIDO'=>'Certificado emitido','LIVE_TURMA'=>'Evento da turma ao vivo',
        'LIVE_LEMBRETE_AGENDADO'=>'X tempo antes da live','LIVE_ACESSOU'=>'Acessou a live','LIVE_OFERTA'=>'Oferta da live',
        'LIVE_COMPRA'=>'Compra na live','WHATSAPP_GRUPO_ENTROU'=>'Entrou no grupo de WhatsApp','WHATSAPP_GRUPO_SAIU'=>'Saiu do grupo de WhatsApp',
    ];
}
