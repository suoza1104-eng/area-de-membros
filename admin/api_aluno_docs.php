<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/student_api.php';

proteger_admin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

function apid_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$endpoint = rtrim(BASE_URL, '/') . '/api/v1/aluno.php';

// [campo, tipo, descricao]
$sections = [
    'Resposta (topo)' => [
        ['ok', 'boolean', 'true quando a consulta foi processada (mesmo que o aluno não exista). false só em erro (ver "Erros").'],
        ['api_version', 'string', 'Versão da API. Hoje "1".'],
        ['status', 'string', '"found" (achou um aluno), "not_found" (nenhum cadastro) ou "ambiguous" (mais de um cadastro bate com os dados).'],
        ['encontrado', 'boolean', 'Atalho: true somente quando status = "found".'],
        ['busca.email_informado / telefone_informado', 'string|null', 'O que foi enviado na consulta.'],
        ['busca.telefone_normalizado', 'string|null', 'Telefone com DDD, só dígitos e sem o 55 (ex.: 11999998888).'],
        ['busca.encontrado_por_email / encontrado_por_telefone', 'int', 'Quantos cadastros bateram por cada critério.'],
        ['busca.metodo', 'string|null', 'Como o aluno foi identificado: "email_e_telefone", "email" ou "telefone".'],
        ['busca.avisos', 'array', 'Alertas, ex.: o telefone informado pertence a outro cadastro.'],
        ['mensagem', 'string', 'Explicação legível quando status é "not_found" ou "ambiguous".'],
        ['candidatos', 'array', 'Só em "ambiguous": id, nome, email, telefone, cadastrado_em e encontrado_por de cada cadastro possível.'],
        ['gerado_em', 'datetime', 'Momento da consulta.'],
    ],
    'aluno: cadastro' => [
        ['id', 'int', 'ID interno do aluno.'],
        ['nome / email / telefone', 'string', 'Dados do cadastro como estão gravados.'],
        ['telefone_normalizado', 'string|null', 'Telefone do cadastro com DDD, só dígitos, sem 55.'],
        ['cadastrado_em', 'datetime', 'Data do primeiro cadastro.'],
        ['primeiro_login_em / ultimo_login_em', 'datetime|null', 'Primeiro e último acesso à área de membros.'],
        ['total_logins', 'int', 'Quantidade de logins bem-sucedidos.'],
        ['ja_acessou_area_de_membros', 'boolean', 'Se o aluno já entrou alguma vez.'],
        ['utm_cadastro.*', 'string|null', 'UTMs (source, medium, campaign, term, content) do cadastro.'],
    ],
    'aluno: turma e inscrições' => [
        ['turma_atual.codigo', 'string|null', 'Código da turma em que o aluno está agora.'],
        ['turma_atual.codigo_live', 'string|null', 'Código da live dessa turma.'],
        ['turma_atual.data_live', 'datetime|null', 'Data/hora da live do aluno (considera reagendamento).'],
        ['turma_atual.live_ja_aconteceu', 'boolean|null', 'Se a data da live já passou.'],
        ['inscricoes[]', 'array', 'Todas as inscrições, em ordem: turma, inscrito_em, tipo_acesso ("free" ou "lifetime"), origem, novo_cadastro (true se foi a primeira vez), utm_source, utm_campaign.'],
        ['total_inscricoes', 'int', 'Quantas vezes o aluno se inscreveu (reinscrições contam).'],
    ],
    'aluno: acesso (bloqueio e vitalício)' => [
        ['acesso.bloqueado', 'boolean', 'Se o aluno está sem acesso ao curso agora (bloqueio manual OU prazo expirado).'],
        ['acesso.motivo_bloqueio', 'string|null', '"bloqueio_manual" ou "prazo_expirado".'],
        ['acesso.bloqueio_manual / bloqueado_em / desbloqueado_em', 'boolean / datetime', 'Bloqueio feito pela equipe/automação e suas datas.'],
        ['acesso.prazo_de_acesso_ativo', 'boolean', 'Se a turma do aluno tem prazo de acesso (curso que expira).'],
        ['acesso.dias_de_acesso', 'int|null', 'Duração do acesso em dias.'],
        ['acesso.acesso_iniciado_em / acesso_expira_em', 'datetime|null', 'Início e fim do prazo de acesso.'],
        ['acesso.segundos_restantes', 'int|null', 'Tempo que falta para expirar (0 se já expirou).'],
        ['acesso.prazo_expirado', 'boolean', 'Se o prazo já acabou.'],
        ['acesso.vitalicio', 'boolean', 'Se o aluno tem acesso vitalício (nunca expira).'],
        ['acesso.vitalicio_desde / vitalicio_tipo / vitalicio_origem', 'datetime / string', 'Quando ganhou, se foi "pago" ou "cortesia", e por onde (ex.: pagarme, hotmart, manual).'],
        ['acesso.vitalicio_transacao', 'string|null', 'Código da transação que liberou o vitalício.'],
        ['acesso.link_compra_vitalicio', 'string|null', 'Link de checkout do vitalício já preenchido com os dados do aluno.'],
    ],
    'aluno: progresso nas aulas' => [
        ['progresso.aulas_obrigatorias', 'int', 'Aulas que contam para a conclusão.'],
        ['progresso.aulas_concluidas', 'int', 'Quantas dessas o aluno concluiu.'],
        ['progresso.percentual', 'int', '0 a 100, igual ao mostrado na trilha do aluno.'],
        ['progresso.curso_concluido', 'boolean', 'Se concluiu todas as obrigatórias.'],
        ['progresso.aulas[]', 'array', 'Cada aula ativa: id, titulo, ordem, aula_ao_vivo, obrigatoria, status ("concluida", "iniciada", "nao_iniciada"), iniciada_em, concluida_em, segundos_assistidos, visualizacoes, ultima_visualizacao_em.'],
    ],
    'aluno: live' => [
        ['live.acessou_live', 'boolean', 'Se o aluno entrou na live (evento de live do tipo "acessou").'],
        ['live.acessou_live_em', 'datetime|null', 'Primeira vez que entrou na live.'],
        ['live.eventos[]', 'array', 'Cada evento de live configurado (acessou, oferta, compra...): evento, tipo, tag, ocorreu, em.'],
        ['live.reagendamentos[]', 'array', 'Trocas de turma/live: de_turma, para_turma, live_anterior, nova_live, origem, status, em.'],
    ],
    'aluno: certificado' => [
        ['certificado.emitido', 'boolean', 'Se o aluno já gerou o certificado do curso.'],
        ['certificado.emitido_em / codigo', 'datetime / string', 'Data e código de validação do certificado.'],
        ['certificado.link_pdf', 'string|null', 'Link direto do PDF do certificado.'],
        ['certificado.link_pagina_certificado', 'string', 'Página onde o aluno emite/baixa o certificado.'],
        ['certificado.historico[]', 'array', 'Todos os certificados do curso (curso, codigo, status, emitido_em, link_pdf).'],
        ['certificado.certificados_individuais[]', 'array', 'Certificados avulsos emitidos para o mesmo e-mail.'],
    ],
    'aluno: outros' => [
        ['tags[]', 'array', 'Todas as tags do aluno, em ordem: nome, origem, em. Ex.: INSCRITO, PRIMEIRO_LOGIN, VIU_AULA_3, ACESSOU_LIVE.'],
        ['email_marketing.*', 'boolean/string', 'status, descadastrado, descadastrado_em, bounce, marcou_spam, bloqueado.'],
        ['whatsapp.*', 'boolean/int', 'esta_em_grupo, grupos_atuais, grupos_historico, entrou_primeira_vez_em, visto_por_ultimo_em.'],
        ['suporte.*', 'int/string', 'conversas, conversas_abertas, ultima_mensagem_em, ultimo_assunto (chat de suporte).'],
        ['compras.*', 'object', 'Só para chaves com escopo "Completo": total_registros, compras_aprovadas, valor_total_aprovado (R$) e itens[] com plataforma, transacao, produto, status (APPROVED, PENDING, CANCELED, REFUNDED...), status_original, valor, moeda, forma_pagamento, parcelas, data.'],
    ],
];

$errors = [
    ['400', 'parametros_ausentes / email_invalido', 'Não enviou email nem telefone, ou o e-mail é inválido.'],
    ['401', 'chave_invalida', 'Header X-API-Key ausente ou chave errada.'],
    ['403', 'chave_revogada', 'A chave foi revogada no painel.'],
    ['405', 'metodo_nao_permitido', 'Use POST.'],
    ['429', 'limite_excedido', 'Passou do limite de consultas por minuto da chave. Aguarde 60s.'],
    ['500 / 503', 'erro_interno / indisponivel', 'Falha temporária. Tente de novo.'],
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>API de Consulta de Aluno · Documentação</title>
<style>
:root{--bg:#020617;--card:#0b1220;--border:#1f2937;--text:#e5e7eb;--muted:#9ca3af;--primary:#facc15}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:980px;margin:0 auto;padding:32px 16px 64px}
h1{font-size:26px;margin:0 0 4px}h2{font-size:18px;margin:36px 0 10px;color:var(--primary)}h3{font-size:15px;margin:22px 0 8px}
p,li{color:#d1d5db}.muted{color:var(--muted)}
code,pre{font-family:ui-monospace,Consolas,monospace;font-size:12.5px}
code{background:#0f172a;border:1px solid var(--border);border-radius:5px;padding:1px 6px}
pre{background:#0f172a;border:1px solid var(--border);border-radius:10px;padding:14px;overflow:auto;white-space:pre}
table{width:100%;border-collapse:collapse;margin:8px 0 4px}
th,td{border-bottom:1px solid var(--border);padding:8px 10px;text-align:left;vertical-align:top}
th{font-size:11px;text-transform:uppercase;color:var(--muted)}
td:first-child{font-family:ui-monospace,Consolas,monospace;font-size:12.5px;white-space:nowrap}
td:nth-child(2){color:var(--muted);white-space:nowrap;font-size:12px}
.box{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin:12px 0}
.print{float:right;background:var(--primary);color:#111;border:0;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer}
@media(max-width:700px){td:first-child{white-space:normal;word-break:break-word}.scroll{overflow-x:auto}}
@media print{:root{--bg:#fff;--card:#fff;--border:#ccc;--text:#111;--muted:#555;--primary:#111}p,li{color:#222}code,pre{background:#f5f5f5}.print{display:none}}
</style>
</head>
<body>
<main>
  <button class="print" onclick="window.print()">Imprimir / PDF</button>
  <h1>API de Consulta de Aluno</h1>
  <p class="muted">Versão <?= apid_h(STUDENT_API_VERSION) ?> · Área de Membros Professor Emerson Leite</p>

  <h2>1. Visão geral</h2>
  <p>Você envia o <strong>e-mail</strong> e/ou o <strong>telefone</strong> de uma pessoa e recebe tudo o que a área de membros sabe sobre ela: cadastro, turma, data da live, se viu a live, aulas assistidas, progresso, se o acesso está bloqueado ou é vitalício, certificado, tags e compras.</p>

  <h2>2. Autenticação</h2>
  <p>Cada plataforma recebe uma chave própria (começa com <code>amk_</code>). Envie-a em <strong>todo</strong> pedido no header:</p>
  <pre>X-API-Key: amk_sua_chave_aqui</pre>
  <p class="muted">Também é aceito <code>Authorization: Bearer amk_...</code>, mas alguns servidores descartam esse header; prefira <code>X-API-Key</code>. Nunca coloque a chave em código de navegador/página pública: ela dá acesso aos dados de todos os alunos.</p>

  <h2>3. Requisição</h2>
  <div class="box">
    <strong>POST</strong> <code><?= apid_h($endpoint) ?></code><br>
    <span class="muted">Content-Type: application/json</span>
  </div>
  <table>
    <thead><tr><th>Campo</th><th>Tipo</th><th>Descrição</th></tr></thead>
    <tbody>
      <tr><td>email</td><td>string</td><td>E-mail do aluno (maiúsculas/minúsculas não importam).</td></tr>
      <tr><td>telefone</td><td>string</td><td>Celular em qualquer formato: <code>+55 (11) 99999-8888</code>, <code>5511999998888</code>, <code>11999998888</code>, com ou sem o 9º dígito. Também aceito como <code>phone</code> ou <code>celular</code>.</td></tr>
    </tbody>
  </table>
  <p>Envie pelo menos um dos dois. <strong>Recomendado: envie os dois</strong>; isso desempata quando um telefone aparece em mais de um cadastro.</p>

  <h3>Como o aluno é localizado</h3>
  <ol>
    <li>Se o e-mail e o telefone apontam para o mesmo cadastro, ele é usado (<code>metodo: "email_e_telefone"</code>).</li>
    <li>Senão, se o e-mail encontra exatamente um cadastro, ele é usado. Se o telefone não conferir, vem um aviso em <code>busca.avisos</code>.</li>
    <li>Senão, se o e-mail não existe e o telefone encontra exatamente um cadastro, ele é usado.</li>
    <li>Se sobrar mais de um cadastro possível, a resposta é <code>status: "ambiguous"</code> com a lista em <code>candidatos</code>: nenhum aluno é escolhido no chute.</li>
    <li>Se nada for encontrado: <code>status: "not_found"</code>.</li>
  </ol>

  <h3>Exemplo (curl)</h3>
  <pre>curl -X POST "<?= apid_h($endpoint) ?>" \
  -H "X-API-Key: amk_sua_chave_aqui" \
  -H "Content-Type: application/json" \
  -d '{"email":"aluno@exemplo.com","telefone":"(11) 99999-8888"}'</pre>

  <h3>Exemplo (n8n / Make / ManyChat)</h3>
  <p>Use um bloco <em>HTTP Request</em>: método <strong>POST</strong>, URL acima, header <code>X-API-Key</code> com a chave, corpo JSON com <code>email</code> e <code>telefone</code> vindos do contato. Depois, use os campos da resposta (ex.: <code>aluno.progresso.percentual</code>, <code>aluno.acesso.bloqueado</code>) nas suas condições.</p>

  <h2>4. Respostas</h2>
  <h3>Aluno encontrado (resumido)</h3>
  <pre>{
  "ok": true,
  "api_version": "1",
  "status": "found",
  "encontrado": true,
  "busca": { "metodo": "email_e_telefone", "telefone_normalizado": "11999998888", "avisos": [] },
  "aluno": {
    "id": 12345,
    "nome": "Maria Silva",
    "cadastrado_em": "2026-09-25T21:09:24-03:00",
    "turma_atual": { "codigo": "230926", "data_live": "2026-10-05T19:00:00-03:00", "live_ja_aconteceu": true },
    "acesso": { "bloqueado": false, "vitalicio": true, "vitalicio_tipo": "pago", "acesso_expira_em": null },
    "progresso": { "aulas_obrigatorias": 6, "aulas_concluidas": 6, "percentual": 100, "curso_concluido": true, "aulas": [ ... ] },
    "live": { "acessou_live": true, "acessou_live_em": "2026-10-05T18:57:08-03:00", "eventos": [ ... ] },
    "certificado": { "emitido": true, "link_pdf": "https://.../certificado.pdf" },
    "tags": [ ... ],
    "compras": { "compras_aprovadas": 1, "valor_total_aprovado": 24.9, "itens": [ ... ] }
  }
}</pre>
  <h3>Não encontrado</h3>
  <pre>{
  "ok": true,
  "status": "not_found",
  "encontrado": false,
  "mensagem": "Nenhum aluno encontrado com o e-mail/telefone informado.",
  "busca": { "email_informado": "x@y.com", "telefone_normalizado": "11999998888", "encontrado_por_email": 0, "encontrado_por_telefone": 0 }
}</pre>
  <p class="muted">Datas vêm no formato ISO 8601 com fuso de Brasília (ex.: <code>2026-10-05T19:00:00-03:00</code>). Campos sem informação vêm como <code>null</code>.</p>

  <h2>5. Dicionário de campos</h2>
  <?php foreach ($sections as $title => $fields): ?>
    <h3><?= apid_h($title) ?></h3>
    <div class="scroll"><table>
      <thead><tr><th>Campo</th><th>Tipo</th><th>Significado</th></tr></thead>
      <tbody>
      <?php foreach ($fields as [$f, $t, $d]): ?>
        <tr><td><?= apid_h($f) ?></td><td><?= apid_h($t) ?></td><td><?= apid_h($d) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endforeach; ?>

  <h2>6. Erros</h2>
  <p>Em erro, a resposta tem <code>"ok": false</code>, um código em <code>erro</code> e o texto em <code>mensagem</code>.</p>
  <table>
    <thead><tr><th>HTTP</th><th>erro</th><th>Quando acontece</th></tr></thead>
    <tbody>
    <?php foreach ($errors as [$c, $e, $d]): ?>
      <tr><td><?= apid_h($c) ?></td><td><?= apid_h($e) ?></td><td><?= apid_h($d) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>7. Limites e segurança</h2>
  <ul>
    <li>Cada chave tem um limite de consultas por minuto (padrão 60). Ao passar dele, a resposta é HTTP 429 com header <code>Retry-After: 60</code>.</li>
    <li>Toda consulta fica registrada (plataforma, data, quem foi consultado, resultado).</li>
    <li>Chaves com escopo "Sem compras" não recebem o bloco <code>compras</code>.</li>
    <li>Se uma chave vazar, ela pode ser revogada no painel na hora, sem afetar as outras plataformas.</li>
  </ul>
</main>
</body>
</html>
