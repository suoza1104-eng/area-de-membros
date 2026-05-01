<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';

$pdo = getPDO();

// ===== Proteção simples de admin =====
if (empty($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header('Location: ' . BASE_URL_ADMIN . '/index.php');
    exit;
}

// ===== Helper para escapar HTML de forma segura =====
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ===== Helpers =====
function gerar_slug(string $titulo): string {
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $titulo);
    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $slug);
    $slug = strtolower(trim($slug, '-'));
    return $slug ?: 'aula';
}

// ===== Mensagens =====
$mensagemSucesso = '';
$mensagemErro    = '';

// ===== Remover aula =====
// Por padrão, o parâmetro ?delete=ID apaga de verdade (hard delete).
// Se você quiser apenas desativar (soft delete), use ?disable=ID.
if (isset($_GET['disable'])) {
    $idDis = (int)$_GET['disable'];
    if ($idDis > 0) {
        $stmt = $pdo->prepare("UPDATE lessons SET ativo = 0 WHERE id = :id");
        $stmt->execute(['id' => $idDis]);
        $mensagemSucesso = 'Aula desativada.';
    }
}

if (isset($_GET['delete'])) {
    $idDel = (int)$_GET['delete'];
    if ($idDel > 0) {
        try {
            $pdo->beginTransaction();

            // Remove progressos relacionados (evita "lixo" e possíveis erros de FK)
            $st1 = $pdo->prepare("DELETE FROM lesson_progress WHERE lesson_id = :id");
            $st1->execute(['id' => $idDel]);

            // (Opcional) apagar thumbnail local, se for da pasta uploads/thumbs
            $stThumb = $pdo->prepare("SELECT thumb_url FROM lessons WHERE id = :id");
            $stThumb->execute(['id' => $idDel]);
            $thumbUrlRow = $stThumb->fetch();
            if ($thumbUrlRow && !empty($thumbUrlRow['thumb_url'])) {
                $thumbUrl = (string)$thumbUrlRow['thumb_url'];
                $path = parse_url($thumbUrl, PHP_URL_PATH);
                if (is_string($path) && strpos($path, '/uploads/thumbs/') !== false) {
                    $local = realpath(__DIR__ . '/..' . $path);
                    $uploadsDir = realpath(__DIR__ . '/../uploads/thumbs');
                    // Só apaga se for realmente dentro da pasta de thumbs do projeto
                    if ($local && $uploadsDir && strpos($local, $uploadsDir) === 0 && is_file($local)) {
                        @unlink($local);
                    }
                }
            }

            // Apaga a aula
            $st2 = $pdo->prepare("DELETE FROM lessons WHERE id = :id");
            $st2->execute(['id' => $idDel]);

            $pdo->commit();
            $mensagemSucesso = 'Aula removida definitivamente.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensagemErro = 'Erro ao remover a aula: ' . $e->getMessage();
        }
    }
}

// ===== Salvar aula (create / update) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_aula'])) {
    $id        = (int)($_POST['id'] ?? 0);
    $titulo    = trim((string)($_POST['titulo'] ?? ''));
    $slug      = trim((string)($_POST['slug'] ?? ''));
    $ordem     = (int)($_POST['ordem'] ?? 0);
    $videoType = (string)($_POST['video_type'] ?? 'youtube');
    $videoUrl  = trim((string)($_POST['video_url'] ?? ''));
    $thumbUrl  = trim((string)($_POST['thumb_url'] ?? ''));

    // Upload opcional de thumbnail
    if (!empty($_FILES['thumb_file']['name']) && is_uploaded_file($_FILES['thumb_file']['tmp_name'])) {
        $dir = __DIR__ . '/../uploads/thumbs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9\._-]/', '', $_FILES['thumb_file']['name']);
        $name = 'thumb_' . time() . '_' . $safeName;
        $dest = $dir . '/' . $name;

        if (move_uploaded_file($_FILES['thumb_file']['tmp_name'], $dest)) {
            $urlBase = rtrim(dirname(BASE_URL_ADMIN, 1), '/');
            $thumbUrl = $urlBase . '/uploads/thumbs/' . $name;
        }
    }

    $htmlExtra = trim((string)($_POST['html_extra'] ?? ''));
    $conta     = isset($_POST['conta_para_conclusao']) ? 1 : 0;
    $ativo     = isset($_POST['ativo']) ? 1 : 0;
    $isLive    = isset($_POST['is_live']) ? 1 : 0;

    if ($titulo === '') {
        $mensagemErro = 'Preencha o título da aula.';
    } else {
        if ($slug === '') {
            $slug = gerar_slug($titulo);
        }

        if ($id > 0) {
            // UPDATE
            $sql = "
                UPDATE lessons
                   SET titulo = :titulo,
                       slug   = :slug,
                       ordem  = :ordem,
                       video_type = :video_type,
                       video_url  = :video_url,
                       thumb_url  = :thumb_url,
                       html_extra = :html_extra,
                       conta_para_conclusao = :conta,
                       ativo     = :ativo,
                       is_live   = :is_live
                 WHERE id = :id
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'titulo'    => $titulo,
                'slug'      => $slug,
                'ordem'     => $ordem,
                'video_type'=> $videoType,
                'video_url' => $videoUrl,
                'thumb_url' => $thumbUrl,
                'html_extra'=> $htmlExtra,
                'conta'     => $conta,
                'ativo'     => $ativo,
                'is_live'   => $isLive,
                'id'        => $id,
            ]);
            $mensagemSucesso = 'Aula atualizada com sucesso.';
        } else {
            // INSERT
            $sql = "
                INSERT INTO lessons
                    (titulo, slug, ordem, video_type, video_url, thumb_url, html_extra,
                     conta_para_conclusao, ativo, is_live)
                VALUES
                    (:titulo, :slug, :ordem, :video_type, :video_url, :thumb_url, :html_extra,
                     :conta, :ativo, :is_live)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'titulo'    => $titulo,
                'slug'      => $slug,
                'ordem'     => $ordem,
                'video_type'=> $videoType,
                'video_url' => $videoUrl,
                'thumb_url' => $thumbUrl,
                'html_extra'=> $htmlExtra,
                'conta'     => $conta,
                'ativo'     => $ativo,
                'is_live'   => $isLive,
            ]);
        }

        // Depois de salvar, volta para a lista de aulas
        header('Location: ' . BASE_URL_ADMIN . '/aulas.php');
        exit;
    }
}

// ===== Carregar aula para edição =====
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$aula = [
    'id'        => 0,
    'titulo'    => '',
    'slug'      => '',
    'ordem'     => 0,
    'video_type'=> 'youtube',
    'video_url' => '',
    'thumb_url' => '',
    'html_extra'=> '',
    'conta_para_conclusao' => 1,
    'ativo'     => 1,
    'is_live'   => 0,
];

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = :id");
    $stmt->execute(['id' => $editId]);
    $row = $stmt->fetch();
    if ($row) {
        $aula = array_merge($aula, $row);
    }
}

// ===== Lista de aulas =====
$listaAulas = $pdo->query("
    SELECT id, titulo, slug, ordem, is_live, ativo
    FROM lessons
    ORDER BY ordem ASC, id ASC
")->fetchAll();

?>
<?php
$menu = 'aulas';
include __DIR__ . '/_header.php';
?>
<style>
:root{
            --bg-main:#020617;
            --bg-card:#020617;
            --bg-sidebar:#020617;
            --border:#1f2937;
            --primary:#facc15;
            --primary-soft:rgba(250,204,21,.15);
            --text-main:#e5e7eb;
            --text-muted:#9ca3af;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{
            font-family:Arial, sans-serif;
            background:var(--bg-main);
            color:var(--text-main);
            min-height:100vh;
        }
        a{text-decoration:none;color:inherit;}
        .layout{
            display:flex;
            min-height:100vh;
        }
        .sidebar{
            width:220px;
            background:var(--bg-sidebar);
            border-right:1px solid var(--border);
            padding:20px 16px;
        }
        .logo{
            font-weight:bold;
            font-size:18px;
            margin-bottom:12px;
        }
        .logo span{
            color:var(--primary);
        }
        .menu-title{
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.08em;
            color:var(--text-muted);
            margin:18px 0 6px;
        }
        .menu a{
            display:block;
            padding:8px 10px;
            border-radius:8px;
            font-size:14px;
            color:var(--text-muted);
        }
        .menu a:hover{
            background:var(--primary-soft);
            color:var(--primary);
        }
        .menu a.active{
            background:var(--primary);
            color:#111827;
            font-weight:bold;
        }
        .main{
            flex:1;
            padding:20px 24px 40px;
        }
        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            margin-bottom:18px;
        }
        .topbar-title{
            font-size:20px;
            font-weight:bold;
        }
        .topbar-right{
            font-size:13px;
            color:var(--text-muted);
        }
        .logout-link{
            color:var(--primary);
            font-size:13px;
        }
        .panel{
            background:var(--bg-card);
            border-radius:14px;
            border:1px solid var(--border);
            padding:16px;
            margin-bottom:18px;
        }
        .panel-title{
            font-size:18px;
            margin-bottom:10px;
        }
        .form-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:12px 16px;
        }
        .form-group{
            display:flex;
            flex-direction:column;
            gap:4px;
            font-size:13px;
        }
        .form-group label{
            color:var(--text-muted);
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select,
        .form-group textarea{
            padding:7px 9px;
            border-radius:8px;
            border:1px solid var(--border);
            background:#020617;
            color:var(--text-main);
            font-size:13px;
        }
        .form-group textarea{
            min-height:80px;
            resize:vertical;
        }
        .checkbox-row{
            display:flex;
            align-items:center;
            gap:6px;
            font-size:13px;
        }
        .checkbox-row input{
            width:16px;
            height:16px;
        }
        .btn-row{
            margin-top:14px;
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .btn-primary{
            padding:8px 16px;
            border-radius:999px;
            border:none;
            background:var(--primary);
            color:#111827;
            font-weight:bold;
            font-size:13px;
            cursor:pointer;
        }
        .btn-secondary{
            padding:8px 16px;
            border-radius:999px;
            border:1px solid var(--border);
            background:#020617;
            color:var(--text-muted);
            font-size:13px;
            cursor:pointer;
        }
        .msg{
            font-size:13px;
            margin-bottom:10px;
        }
        .msg.ok{color:#22c55e;}
        .msg.err{color:#f97316;}
        .table-wrapper{
            overflow-x:auto;
        }
        table{
            width:100%;
            border-collapse:collapse;
            font-size:13px;
        }
        th,td{
            padding:8px 6px;
            border-bottom:1px solid var(--border);
        }
        th{
            text-align:left;
            color:var(--text-muted);
            font-weight:600;
        }
        tr:hover td{
            background:#020818;
        }
        .link-small{
            font-size:13px;
            color:var(--primary);
        }
</style>
<div class="topbar">
            <div class="topbar-title">Aulas</div>
            <div class="topbar-right">
                Admin logado ·
                <a class="logout-link" href="<?= h(BASE_URL_ADMIN . '/index.php?logout=1') ?>">Sair</a>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">
                <?= $aula['id'] ? 'Editar aula #' . (int)$aula['id'] : 'Nova aula' ?>
            </div>

            <?php if ($mensagemSucesso): ?>
                <div class="msg ok"><?= h($mensagemSucesso) ?></div>
            <?php endif; ?>
            <?php if ($mensagemErro): ?>
                <div class="msg err"><?= h($mensagemErro) ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= (int)$aula['id'] ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="titulo">Título</label>
                        <input type="text" id="titulo" name="titulo"
                               value="<?= h($aula['titulo']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="slug">Slug (URL amigável)</label>
                        <input type="text" id="slug" name="slug"
                               value="<?= h($aula['slug']) ?>"
                               placeholder="aula-1-introducao">
                    </div>
                    <div class="form-group">
                        <label for="ordem">Ordem na trilha</label>
                        <input type="number" id="ordem" name="ordem" min="1"
                               value="<?= (int)$aula['ordem'] ?>">
                    </div>
                    <div class="form-group">
                        <label for="video_type">Tipo de vídeo</label>
                        <select id="video_type" name="video_type">
                            <option value="youtube" <?= $aula['video_type'] === 'youtube' ? 'selected' : '' ?>>
                                YouTube (URL ou ID do vídeo)
                            </option>
                            <option value="embed" <?= $aula['video_type'] === 'embed' ? 'selected' : '' ?>>
                                Embed / iframe (colar código completo)
                            </option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="video_url">
                            URL do vídeo (YouTube) ou código embed
                        </label>
                        <textarea id="video_url" name="video_url"
                                  placeholder="https://www.youtube.com/embed/xxxxx ou código &lt;iframe&gt;..."><?= h($aula['video_url']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="thumb_url">URL da thumbnail (imagem do carrossel)</label>
                        <input type="text" id="thumb_url" name="thumb_url"
                               value="<?= h($aula['thumb_url']) ?>"
                               placeholder="https://.../imagem.jpg">

                        <div style="margin-top:6px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <input type="file" id="thumb_file" name="thumb_file" accept="image/*" style="font-size:12px;">
                            <?php if (!empty($aula['thumb_url'])): ?>
                                <span style="font-size:11px;color:var(--text-muted);">
                                    Imagem atual:
                                </span>
                                <img src="<?= h($aula['thumb_url']) ?>" alt="Thumb atual"
                                     style="height:32px;border-radius:6px;border:1px solid var(--border);">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label for="html_extra">HTML extra (conteúdo abaixo do carrossel)</label>
                        <textarea id="html_extra" name="html_extra"
                                  placeholder="Você pode colocar textos, botões e banners em HTML"><?= h($aula['html_extra']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Opções</label>
                        <div class="checkbox-row">
                            <input type="checkbox" id="conta_para_conclusao" name="conta_para_conclusao"
                                <?= !empty($aula['conta_para_conclusao']) ? 'checked' : '' ?>>
                            <label for="conta_para_conclusao">Conta para conclusão do curso</label>
                        </div>
                        <div class="checkbox-row">
                            <input type="checkbox" id="ativo" name="ativo"
                                <?= !empty($aula['ativo']) ? 'checked' : '' ?>>
                            <label for="ativo">Aula ativa</label>
                        </div>
                        <div class="checkbox-row">
                            <input type="checkbox" id="is_live" name="is_live"
                                <?= !empty($aula['is_live']) ? 'checked' : '' ?>>
                            <label for="is_live">Esta é a aula ao vivo</label>
                        </div>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" name="salvar_aula" class="btn-primary">
                        Salvar aula e voltar para lista
                    </button>
                    <a href="<?= h(BASE_URL_ADMIN . '/aulas.php') ?>" class="btn-secondary">
                        Cancelar / voltar
                    </a>
                </div>
            </form>
        </div>

        <div class="panel">
            <div class="panel-title">Lista de aulas</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>Ordem</th>
                        <th>Título</th>
                        <th>Slug</th>
                        <th>Live?</th>
                        <th>Ativa</th>
                        <th>Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($listaAulas as $row): ?>
                        <tr>
                            <td><?= (int)$row['ordem'] ?></td>
                            <td><?= h($row['titulo']) ?></td>
                            <td><?= h($row['slug']) ?></td>
                            <td><?= !empty($row['is_live']) ? 'Sim' : 'Não' ?></td>
                            <td><?= !empty($row['ativo']) ? 'Sim' : 'Não' ?></td>
                            <td>
                                <a class="link-small"
                                   href="<?= h(BASE_URL_ADMIN . '/aulas.php?edit=' . (int)$row['id']) ?>">editar</a>
                                ·
                                <a class="link-small"
                                   href="<?= h(BASE_URL_ADMIN . '/aulas.php?delete=' . (int)$row['id']) ?>"
                                   onclick="return confirm('ATENÇÃO: isso APAGA a aula do banco e os progressos relacionados. Deseja continuar?');">remover</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

<?php
// logout simples
if (isset($_GET['logout'])) {
    $_SESSION['admin_logado'] = false;
    session_destroy();
    header('Location: ' . BASE_URL_ADMIN . '/index.php');
    exit;
}
?>

<?php include __DIR__ . '/_footer.php'; ?>
