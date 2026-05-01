<?php
// FILE: admin/cursos_recomendados.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

proteger_admin();
$pdo = getPDO();

// qual item do menu fica ativo
$menu = 'cursos';

$mensagemOk = '';
$mensagemErro = '';

// pasta de upload das thumbs
$uploadDir = __DIR__ . '/../uploads/recommended_courses';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// Carregar curso pra editar (se houver ?edit=ID)
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$cursoEdit = null;

if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM recommended_courses WHERE id = :id LIMIT 1");
    $st->execute(['id' => $editId]);
    $cursoEdit = $st->fetch() ?: null;
}

// Excluir (GET delete=ID)
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId > 0) {
        try {
            $del = $pdo->prepare("DELETE FROM recommended_courses WHERE id = :id");
            $del->execute(['id' => $delId]);
            $mensagemOk = 'Curso removido com sucesso.';
            $cursoEdit = null;
            $editId = 0;
        } catch (Throwable $e) {
            $mensagemErro = 'Erro ao excluir: ' . $e->getMessage();
        }
    }
}

// Salvar (create/update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idPost     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $titulo     = trim((string)($_POST['titulo'] ?? ''));
    $descricao  = trim((string)($_POST['descricao'] ?? ''));
    // valor atual (hidden)
    $thumbUrl   = trim((string)($_POST['thumb_url'] ?? ''));
    $checkout   = trim((string)($_POST['checkout_url'] ?? ''));
    $ordem      = (int)($_POST['ordem'] ?? 1);
    $ativo      = isset($_POST['ativo']) ? 1 : 0;

    // ====== upload da imagem (se enviada) ======
    if (
        isset($_FILES['thumb_file']) &&
        is_array($_FILES['thumb_file']) &&
        ($_FILES['thumb_file']['error'] === UPLOAD_ERR_OK) &&
        $_FILES['thumb_file']['size'] > 0
    ) {
        $tmpName  = $_FILES['thumb_file']['tmp_name'];
        $origName = $_FILES['thumb_file']['name'] ?? '';
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        $extPermitidas = ['png','jpg','jpeg','webp'];
        if (!in_array($ext, $extPermitidas, true)) {
            $mensagemErro = 'A imagem do curso deve ser PNG, JPG ou WEBP.';
        } else {
            $nomeArquivo = 'course_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath    = $uploadDir . '/' . $nomeArquivo;

            if (!move_uploaded_file($tmpName, $destPath)) {
                $mensagemErro = 'Não foi possível salvar a imagem enviada.';
            } else {
                // Monta URL pública
                $base     = rtrim(BASE_URL, '/');                 // .../area_membros/public
                $baseRoot = preg_replace('#/public$#', '', $base); // .../area_membros
                $thumbUrl = $baseRoot . '/uploads/recommended_courses/' . $nomeArquivo;
            }
        }
    }

    if ($mensagemErro === '' && ($titulo === '' || $checkout === '')) {
        $mensagemErro = 'Título e link de checkout são obrigatórios.';
    }

    if ($mensagemErro === '') {
        try {
            if ($idPost > 0) {
                $up = $pdo->prepare("
                    UPDATE recommended_courses
                    SET titulo = :titulo,
                        descricao = :descricao,
                        thumb_url = :thumb,
                        checkout_url = :checkout,
                        ordem = :ordem,
                        ativo = :ativo,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $up->execute([
                    'titulo'   => $titulo,
                    'descricao'=> $descricao,
                    'thumb'    => $thumbUrl,
                    'checkout' => $checkout,
                    'ordem'    => $ordem,
                    'ativo'    => $ativo,
                    'id'       => $idPost,
                ]);
                $mensagemOk = 'Curso atualizado com sucesso.';
                $editId = $idPost;
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO recommended_courses (titulo, descricao, thumb_url, checkout_url, ordem, ativo, created_at)
                    VALUES (:titulo, :descricao, :thumb, :checkout, :ordem, :ativo, NOW())
                ");
                $ins->execute([
                    'titulo'   => $titulo,
                    'descricao'=> $descricao,
                    'thumb'    => $thumbUrl,
                    'checkout' => $checkout,
                    'ordem'    => $ordem,
                    'ativo'    => $ativo,
                ]);
                $mensagemOk = 'Curso criado com sucesso.';
                $editId = (int)$pdo->lastInsertId();
            }

            // Recarrega cursoEdit
            $st = $pdo->prepare("SELECT * FROM recommended_courses WHERE id = :id LIMIT 1");
            $st->execute(['id' => $editId]);
            $cursoEdit = $st->fetch() ?: null;

        } catch (Throwable $e) {
            $mensagemErro = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}

// Lista completa
$listStmt = $pdo->query("
    SELECT *
    FROM recommended_courses
    ORDER BY ordem ASC, id ASC
");
$cursos = $listStmt->fetchAll();

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// inclui o header com o menu
include __DIR__ . '/_header.php';
?>

<style>
    .page{
        max-width:980px;
        margin:0 auto;
        padding:16px 12px 32px;
    }
    .title{
        font-size:18px;
        font-weight:bold;
        margin-bottom:4px;
    }
    .subtitle{
        font-size:12px;
        color:#9ca3af;
        margin-bottom:14px;
    }
    .grid{
        display:grid;
        grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.4fr);
        gap:14px;
    }
    @media (max-width:800px){
        .grid{
            grid-template-columns:1fr;
        }
    }
    .card{
        background:var(--bg-card);
        border-radius:16px;
        border:1px solid #1f2937;
        padding:14px 12px 16px;
        box-shadow:0 14px 32px rgba(0,0,0,.45);
    }
    .section-title{
        font-size:14px;
        font-weight:bold;
        margin-bottom:4px;
    }
    .section-sub{
        font-size:11px;
        color:#9ca3af;
        margin-bottom:8px;
    }
    .field{
        margin-bottom:9px;
    }
    label{
        display:block;
        font-size:12px;
        color:#9ca3af;
        margin-bottom:3px;
    }
    input[type="text"],
    textarea{
        width:100%;
        padding:7px 9px;
        border-radius:10px;
        border:1px solid var(--border-light);
        background:var(--bg);
        color:var(--text);
        font-size:13px;
    }
    input[type="file"]{
        width:100%;
        font-size:12px;
        color:#9ca3af;
    }
    textarea{
        min-height:60px;
        resize:vertical;
    }
    .row{
        display:flex;
        gap:10px;
    }
    .col-2{flex:1;}
    .check-line{
        display:flex;
        align-items:center;
        gap:6px;
        font-size:12px;
        color:#9ca3af;
        margin-top:4px;
    }
    .btn{
        margin-top:10px;
        padding:9px 14px;
        border-radius:999px;
        border:none;
        background:var(--primary);
        color:#111827;
        font-weight:bold;
        font-size:13px;
        cursor:pointer;
    }
    .btn:hover{
        filter:brightness(1.05);
    }
    .msg-ok{
        margin-bottom:8px;
        padding:8px 10px;
        border-radius:10px;
        background:rgba(34,197,94,.08);
        border:1px solid rgba(34,197,94,.25);
        color:#15803d;
        font-size:12px;
    }
    .msg-erro{
        margin-bottom:8px;
        padding:8px 10px;
        border-radius:10px;
        background:rgba(239,68,68,.08);
        border:1px solid rgba(239,68,68,.25);
        color:#dc2626;
        font-size:12px;
    }
    table{
        width:100%;
        border-collapse:collapse;
        font-size:12px;
    }
    th,td{
        padding:6px 6px;
        border-bottom:1px solid var(--border);
    }
    th{
        text-align:left;
        color:var(--muted);
        font-weight:600;
        font-size:10.5px;
        text-transform:uppercase;
        letter-spacing:.05em;
    }
    tr:hover td{
        background:var(--bg);
    }
    .pill{
        padding:2px 6px;
        border-radius:999px;
        font-size:11px;
    }
    .pill-on{
        background:rgba(34,197,94,.12);
        color:#15803d;
    }
    .pill-off{
        background:rgba(100,116,139,.1);
        color:var(--muted);
    }
    .actions a{
        color:var(--primary);
        margin-right:8px;
    }
    .thumb-mini{
        width:72px;
        height:40px;
        border-radius:8px;
        overflow:hidden;
        background:#020617;
        border:1px dashed #1f2937;
        display:flex;
        align-items:center;
        justify-content:center;
        color:#9ca3af;
        font-size:10px;
    }
    .thumb-mini img{
        width:100%;
        height:100%;
        object-fit:cover;
    }
    .thumb-preview{
        margin-top:4px;
        font-size:11px;
        color:#9ca3af;
        display:flex;
        align-items:center;
        gap:6px;
    }
    .thumb-preview img{
        width:60px;
        height:34px;
        border-radius:6px;
        object-fit:cover;
        border:1px solid #1f2937;
    }
</style>

<div class="page">
    <div class="title">Cursos Recomendados (Pagos)</div>
    <div class="subtitle">
        Estes cursos aparecem na seção "Conheça nossos cursos pagos" da trilha de aulas, com cadeado e link para checkout.
    </div>

    <?php if ($mensagemOk): ?>
        <div class="msg-ok"><?= h($mensagemOk) ?></div>
    <?php endif; ?>
    <?php if ($mensagemErro): ?>
        <div class="msg-erro"><?= h($mensagemErro) ?></div>
    <?php endif; ?>

    <div class="grid">
        <!-- Formulário de criação/edição -->
        <div class="card">
            <div class="section-title">
                <?= $cursoEdit ? 'Editar curso recomendado' : 'Novo curso recomendado' ?>
            </div>
            <?php if ($cursoEdit): ?>
                <div class="section-sub">
                    Editando: <strong><?= h($cursoEdit['titulo'] ?? '') ?></strong> &nbsp;•&nbsp;
                    <a href="cursos_recomendados.php" style="color:var(--primary);">
                        + Criar novo curso
                    </a>
                </div>
            <?php else: ?>
                <div class="section-sub">
                    Preencha os dados abaixo para adicionar um novo curso recomendado.
                </div>
            <?php endif; ?>

            <form method="post" action="" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= $cursoEdit ? (int)$cursoEdit['id'] : 0 ?>">
                <input type="hidden" name="thumb_url" value="<?= h($cursoEdit['thumb_url'] ?? '') ?>">

                <div class="field">
                    <label for="titulo">Título do curso</label>
                    <input type="text" id="titulo" name="titulo"
                           value="<?= h($cursoEdit['titulo'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="descricao">Descrição curta</label>
                    <textarea id="descricao" name="descricao"><?= h($cursoEdit['descricao'] ?? '') ?></textarea>
                </div>

                <div class="field">
                    <label for="thumb_file">Imagem (PNG/JPG/WEBP) do curso</label>
                    <input type="file" id="thumb_file" name="thumb_file" accept="image/png,image/jpeg,image/webp">
                    <?php if (!empty($cursoEdit['thumb_url'])): ?>
                        <div class="thumb-preview">
                            <img src="<?= h($cursoEdit['thumb_url']) ?>" alt="">
                            <span>Imagem atual</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="checkout_url">Link de checkout / página do curso</label>
                    <input type="text" id="checkout_url" name="checkout_url"
                           value="<?= h($cursoEdit['checkout_url'] ?? '') ?>">
                </div>

                <div class="row">
                    <div class="field col-2">
                        <label for="ordem">Ordem na lista</label>
                        <input type="text" id="ordem" name="ordem"
                               value="<?= h(isset($cursoEdit['ordem']) ? (string)$cursoEdit['ordem'] : '1') ?>">
                    </div>
                    <div class="field col-2">
                        <label>&nbsp;</label>
                        <div class="check-line">
                            <input type="checkbox" id="ativo" name="ativo"
                                   <?= ($cursoEdit ? ((int)$cursoEdit['ativo'] === 1 ? 'checked' : '') : 'checked') ?>>
                            <label for="ativo" style="margin:0;">Ativo (aparecer na trilha)</label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn">
                    <?= $cursoEdit ? 'Salvar alterações' : 'Criar curso' ?>
                </button>
            </form>
        </div>

        <!-- Lista de cursos -->
        <div class="card">
            <div class="section-title">Lista de cursos cadastrados</div>
            <?php if (!$cursos): ?>
                <div style="font-size:12px;color:#9ca3af;margin-top:4px;">
                    Nenhum curso recomendado cadastrado ainda.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Thumb</th>
                        <th>Título</th>
                        <th>Ordem</th>
                        <th>Ativo</th>
                        <th style="width:140px;">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cursos as $c): ?>
                        <tr>
                            <td><?= (int)$c['id'] ?></td>
                            <td>
                                <div class="thumb-mini">
                                    <?php if (!empty($c['thumb_url'])): ?>
                                        <img src="<?= h($c['thumb_url']) ?>" alt="">
                                    <?php else: ?>
                                        sem imagem
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= h($c['titulo']) ?></td>
                            <td><?= (int)$c['ordem'] ?></td>
                            <td>
                                <?php if ((int)$c['ativo'] === 1): ?>
                                    <span class="pill pill-on">Ativo</span>
                                <?php else: ?>
                                    <span class="pill pill-off">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <a href="?edit=<?= (int)$c['id'] ?>">Editar</a>
                                <a href="?delete=<?= (int)$c['id'] ?>"
                                   onclick="return confirm('Tem certeza que deseja excluir este curso recomendado?');">
                                    Excluir
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

</main>
</div>
</body>
</html>
