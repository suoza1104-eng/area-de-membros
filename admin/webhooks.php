<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/webhook_dispatcher.php';

proteger_admin();
$pdo = getPDO();

// deixa o item "Webhooks" ativo no menu
$menu = 'webhooks';

// Salvar / atualizar webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id      = (int)($_POST['id'] ?? 0);
    $nome    = trim($_POST['nome'] ?? '');
    $evento  = trim($_POST['evento'] ?? '');
    $url     = trim($_POST['url'] ?? '');
    $metodo  = $_POST['metodo'] ?? 'POST';
    $headers = trim($_POST['headers_json'] ?? '');
    $ativo   = isset($_POST['ativo']) ? 1 : 0;

    // Formato do payload (json ou form)
    $payloadFormat = strtolower(trim($_POST['payload_format'] ?? 'json'));
    if (!in_array($payloadFormat, ['json', 'form'], true)) {
        $payloadFormat = 'json';
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE webhooks
               SET nome = :n,
                   evento = :e,
                   url = :u,
                   metodo = :m,
                   headers_json = :h,
                   payload_format = :pf,
                   ativo = :a
             WHERE id = :id
        ");
        $stmt->execute([
            ':n'  => $nome,
            ':e'  => $evento,
            ':u'  => $url,
            ':m'  => $metodo,
            ':h'  => $headers,
            ':pf' => $payloadFormat,
            ':a'  => $ativo,
            ':id' => $id,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO webhooks (nome, evento, url, metodo, headers_json, payload_format, ativo)
            VALUES (:n, :e, :u, :m, :h, :pf, :a)
        ");
        $stmt->execute([
            ':n'  => $nome,
            ':e'  => $evento,
            ':u'  => $url,
            ':m'  => $metodo,
            ':h'  => $headers,
            ':pf' => $payloadFormat,
            ':a'  => $ativo,
        ]);
    }

    header('Location: webhooks.php');
    exit;
}

// Remover webhook
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $pdo->prepare("DELETE FROM webhooks WHERE id = :id")->execute([':id' => $id]);
    header('Location: webhooks.php');
    exit;
}

// Ativar / desativar webhook
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $stmt = $pdo->prepare("UPDATE webhooks SET ativo = IF(ativo = 1, 0, 1) WHERE id = :id");
    $stmt->execute([':id' => $id]);
    header('Location: webhooks.php');
    exit;
}

// Disparo de teste manual
if (isset($_GET['test'])) {
    $id = (int)$_GET['test'];
    try {
        disparar_webhook_teste($pdo, $id);
    } catch (Throwable $e) {
        // Evita quebrar a tela de admin se o destino retornar erro
    }
    header('Location: webhooks.php');
    exit;
}

// Carregar dados para edição
$editWebhook = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM webhooks WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $editWebhook = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Lista de webhooks cadastrados
$hooks = $pdo->query("SELECT * FROM webhooks ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// ===== Lista base de eventos disponíveis (fixos) =====
$eventOptions = [
    'INSCRITO'              => 'Aluno se cadastrou na área de membros',
    'ASSISTIU_ALGUMA_AULA'  => 'Aluno assistiu pelo menos 10 segundos de qualquer aula',
    'CONCLUIU_TRILHA'       => 'Concluiu todas as aulas obrigatórias',
    'CERT_EMITIDO'          => 'Certificado emitido com sucesso',
    'CERT_SENHA_ERRADA'     => 'Tentativa de senha de certificado incorreta',
];

// ===== Eventos dinâmicos por aula (VIU_AULA_ID) =====
try {
    $stLessons = $pdo->query("
        SELECT id, titulo
        FROM lessons
        ORDER BY ordem ASC, id ASC
    ");
    while ($ls = $stLessons->fetch(PDO::FETCH_ASSOC)) {
        $lessonId   = (int)($ls['id'] ?? 0);
        $lessonName = trim((string)($ls['titulo'] ?? 'Aula sem título'));

        if ($lessonId > 0) {
            $code = 'VIU_AULA_' . $lessonId;
            $eventOptions[$code] = $code . ' – ' . $lessonName;
        }
    }
} catch (Throwable $e) {
    // se der erro pra listar aulas, segue só com os eventos fixos
}

include __DIR__ . '/_header.php';
?>
<style>
    .page-webhooks {
        max-width: 980px;
        margin: 0 auto;
    }

    .evento-wrapper {
        position: relative;
        width: 100%;
    }

    .evento-input-row {
        display: flex;
        gap: 4px;
        align-items: stretch;
    }

    .evento-input-row input[type="text"] {
        flex: 1;
    }

    .evento-toggle-btn {
        border-radius: 8px;
        border: 1px solid #1f2937;
        background: #020617;
        color: #e5e7eb;
        padding: 0 10px;
        font-size: 12px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
    }

    .evento-toggle-btn:hover {
        background: #111827;
    }

    .evento-dropdown {
        position: absolute;
        left: 0;
        right: 0;
        margin-top: 4px;
        background: #020617;
        border-radius: 10px;
        border: 1px solid #1f2937;
        max-height: 220px;
        overflow-y: auto;
        padding: 6px;
        box-shadow: 0 18px 40px rgba(0,0,0,.7);
        display: none;
        z-index: 20;
    }

    .evento-dropdown.aberto {
        display: block;
    }

    .evento-opcao {
        padding: 6px 8px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 11px;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .evento-opcao strong {
        font-size: 11px;
        color: #e5e7eb;
    }

    .evento-opcao span {
        font-size: 10px;
        color: #9ca3af;
    }

    .evento-opcao:hover {
        background: #111827;
    }
</style>

<div class="card page-webhooks">
    <h3 style="margin-top:0;margin-bottom:10px;font-size:16px;">Webhooks</h3>

    <form method="post" style="margin-bottom:16px;">
        <input type="hidden" name="id" value="<?= isset($editWebhook['id']) ? (int)$editWebhook['id'] : '' ?>">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
            <div>
                <label style="font-size:12px;color:#9ca3af;">
                    Nome<br>
                    <input type="text" name="nome" style="width:100%;"
                           value="<?= htmlspecialchars($editWebhook['nome'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </label>
            </div>

            <div>
                <label style="font-size:12px;color:#9ca3af;">
                    Evento(s)<br>
                    <div class="evento-wrapper">
                        <div class="evento-input-row">
                            <input
                                type="text"
                                name="evento"
                                id="evento"
                                placeholder="Ex.: INSCRITO, ASSISTIU_ALGUMA_AULA, VIU_AULA_3"
                                style="width:100%;"
                                value="<?= htmlspecialchars($editWebhook['evento'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            >
                            <button type="button" class="evento-toggle-btn" id="btn-eventos">▼</button>
                        </div>

                        <div class="evento-dropdown" id="lista-eventos">
                            <?php foreach ($eventOptions as $code => $label): ?>
                                <div
                                    class="evento-opcao"
                                    data-value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <strong><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </label>
                <div style="font-size:11px;color:#9ca3af;margin-top:4px;">
                    Clique nas opções da lista para adicioná-las. Você pode combinar vários eventos, separados por vírgula.
                </div>
            </div>

            <div>
                <label style="font-size:12px;color:#9ca3af;">
                    URL<br>
                    <input type="text" name="url" style="width:100%;"
                           value="<?= htmlspecialchars($editWebhook['url'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </label>
            </div>

            <div>
                <label style="font-size:12px;color:#9ca3af;">
                    Método<br>
                    <select name="metodo" style="width:100%;">
                        <?php $m = $editWebhook['metodo'] ?? 'POST'; ?>
                        <option value="POST" <?= ($m === 'POST') ? 'selected' : '' ?>>POST</option>
                        <option value="GET"  <?= ($m === 'GET')  ? 'selected' : '' ?>>GET</option>
                    </select>
                </label>
            </div>

            <div>
                <label style="font-size:12px;color:#9ca3af;">
                    Formato do payload<br>
                    <?php $pf = $editWebhook['payload_format'] ?? 'json'; ?>
                    <select name="payload_format" style="width:100%;">
                        <option value="json" <?= ($pf === 'json') ? 'selected' : '' ?>>
                            JSON (application/json)
                        </option>
                        <option value="form" <?= ($pf === 'form') ? 'selected' : '' ?>>
                            FORM (application/x-www-form-urlencoded)
                        </option>
                    </select>
                </label>
            </div>
        </div>

        <div style="margin-top:8px;">
            <label style="font-size:12px;color:#9ca3af;">
                Headers JSON (opcional)<br>
                <textarea name="headers_json" rows="2" style="width:100%;"><?php
                    echo htmlspecialchars($editWebhook['headers_json'] ?? '', ENT_QUOTES, 'UTF-8');
                ?></textarea>
            </label>
        </div>

        <label style="margin-top:6px;display:inline-block;font-size:12px;color:#9ca3af;">
            <?php $ativoAtual = isset($editWebhook['ativo']) ? (int)$editWebhook['ativo'] : 1; ?>
            <input type="checkbox" name="ativo" <?= ($ativoAtual === 1) ? 'checked' : '' ?>>
            Ativo
        </label>

        <div style="margin-top:8px;">
            <button type="submit">
                <?= $editWebhook ? 'Salvar alterações' : 'Adicionar webhook' ?>
            </button>
        </div>
    </form>



    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Nome</th>
            <th>Evento</th>
            <th>URL</th>
            <th>Método</th>
            <th>Formato</th>
            <th>Ativo</th>
            <th>Ações</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($hooks as $h): ?>
            <tr>
                <td><?= (int)$h['id'] ?></td>
                <td><?= htmlspecialchars($h['nome'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($h['evento'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($h['url'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($h['metodo'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($h['payload_format'] ?? 'json', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= !empty($h['ativo']) ? 'Sim' : 'Não' ?></td>
                <td>
                    <a href="?edit=<?= (int)$h['id'] ?>">editar</a> ·
                    <a href="?toggle=<?= (int)$h['id'] ?>">
                        <?= !empty($h['ativo']) ? 'desativar' : 'ativar' ?>
                    </a> ·
                    <a href="?test=<?= (int)$h['id'] ?>" onclick="return confirm('Disparar um teste para este webhook?');">
                        teste
                    </a> ·
                    <a href="?del=<?= (int)$h['id'] ?>"
                       onclick="return confirm('Remover este webhook?');">
                        remover
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>


</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input   = document.getElementById('evento');
    const btn     = document.getElementById('btn-eventos');
    const drop    = document.getElementById('lista-eventos');

    if (!input || !btn || !drop) return;

    // abre/fecha dropdown
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        drop.classList.toggle('aberto');
    });

    // clicar nas opções adiciona o evento no input
    drop.querySelectorAll('.evento-opcao').forEach(function (opcao) {
        opcao.addEventListener('click', function (e) {
            e.stopPropagation();
            const valor = this.dataset.value;
            if (!valor) return;

            let atual = input.value
                .split(',')
                .map(function (v) { return v.trim(); })
                .filter(function (v) { return v.length > 0; });

            if (atual.indexOf(valor) === -1) {
                atual.push(valor);
                input.value = atual.join(', ');
            }
        });
    });

    // fechar dropdown ao clicar fora
    document.addEventListener('click', function (e) {
        if (!drop.contains(e.target) && e.target !== btn) {
            drop.classList.remove('aberto');
        }
    });
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
