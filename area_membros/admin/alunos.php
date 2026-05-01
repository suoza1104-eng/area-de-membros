<?php
// FILE: admin/alunos.php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';

proteger_admin();
$pdo = getPDO();

/**
 * Escapa HTML com UTF-8.
 */
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Detecta se uma coluna existe em uma tabela.
 */
function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
    $st->execute([':c' => $col]);
    return (bool)$st->fetch();
}

/**
 * Tenta pegar a coluna "codigo_turma" (padrão). Se não existir, tenta alternativas.
 */
$colCodigoTurma = col_exists($pdo, 'users', 'codigo_turma') ? 'codigo_turma' :
                 (col_exists($pdo, 'users', 'turma') ? 'turma' :
                 (col_exists($pdo, 'users', 'turma_codigo') ? 'turma_codigo' : ''));

$colCreatedAt = col_exists($pdo, 'users', 'created_at') ? 'created_at' :
               (col_exists($pdo, 'users', 'criado_em') ? 'criado_em' :
               (col_exists($pdo, 'users', 'data_criacao') ? 'data_criacao' : ''));

/**
 * Filtros
 */
$q = trim((string)($_GET['q'] ?? ''));
$turma = trim((string)($_GET['turma'] ?? ''));

/**
 * Lista de turmas para o select
 */
$turmas = [];
if (col_exists($pdo, 'turmas', 'codigo')) {
    $turmas = $pdo->query("SELECT codigo FROM turmas ORDER BY codigo ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Monta query principal
 * - Evita duplicar linhas por tags usando subquery GROUP_CONCAT.
 */
$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(u.nome LIKE :q OR u.email LIKE :q OR u.telefone LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if ($turma !== '' && $colCodigoTurma !== '') {
    $where[] = "u.`$colCodigoTurma` = :turma";
    $params[':turma'] = $turma;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$selectTurma = $colCodigoTurma !== '' ? "u.`$colCodigoTurma` AS turma_codigo," : "NULL AS turma_codigo,";
$selectCreated = $colCreatedAt !== '' ? "u.`$colCreatedAt` AS criado_em," : "NULL AS criado_em,";

$sql = "
SELECT
  u.id,
  u.nome,
  u.email,
  u.telefone,
  $selectTurma
  $selectCreated
  (
    SELECT GROUP_CONCAT(t.nome ORDER BY t.nome SEPARATOR ', ')
    FROM user_tags ut
    JOIN tags t ON t.id = ut.tag_id
    WHERE ut.user_id = u.id
  ) AS tags_lista
FROM users u
$whereSql
ORDER BY u.id DESC
LIMIT 500
";

$st = $pdo->prepare($sql);
$st->execute($params);
$alunos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Header / footer do admin (se existir)
$header = __DIR__ . '/_header.php';
$footer = __DIR__ . '/_footer.php';
if (file_exists($header)) include $header;
?>
<div class="card">
  <h3>Alunos</h3>
  <p style="opacity:.75">Visualize e filtre alunos, turmas e tags.</p>

  <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0 14px;">
    <input
      class="input"
      type="text"
      name="q"
      value="<?= h($q) ?>"
      placeholder="Nome, e-mail ou telefone"
      style="min-width:260px"
    />

    <select class="input" name="turma" style="min-width:180px">
      <option value="">Todas as turmas</option>
      <?php foreach ($turmas as $t): ?>
        <option value="<?= h($t) ?>" <?= ($turma === (string)$t) ? 'selected' : '' ?>>
          <?= h($t) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button class="btn" type="submit">Aplicar filtros</button>

    <?php if ($q !== '' || $turma !== ''): ?>
      <a class="btn" href="alunos.php" style="background:#111827;">Limpar</a>
    <?php endif; ?>
  </form>

  <div style="overflow:auto;border-radius:12px;border:1px solid rgba(255,255,255,.08);">
    <table style="width:100%;border-collapse:collapse;min-width:980px;">
      <thead>
        <tr style="background:rgba(255,255,255,.04)">
          <th style="text-align:left;padding:10px 12px;">ID</th>
          <th style="text-align:left;padding:10px 12px;">Nome / E-mail</th>
          <th style="text-align:left;padding:10px 12px;">Telefone</th>
          <th style="text-align:left;padding:10px 12px;">Turma</th>
          <th style="text-align:left;padding:10px 12px;">Tags</th>
          <th style="text-align:left;padding:10px 12px;">Criado em</th>
          <th style="text-align:left;padding:10px 12px;">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$alunos): ?>
          <tr><td colspan="7" style="padding:14px;opacity:.7">Nenhum aluno encontrado.</td></tr>
        <?php else: ?>
          <?php foreach ($alunos as $a): ?>
            <?php
              $turmaCodigo = trim((string)($a['turma_codigo'] ?? ''));
              $turmaLabel = $turmaCodigo !== '' ? $turmaCodigo : '-';

              $tags = trim((string)($a['tags_lista'] ?? ''));
              $tagsLabel = $tags !== '' ? $tags : 'sem tags';

              $criado = (string)($a['criado_em'] ?? '');
              $criadoLabel = $criado !== '' ? $criado : '-';

              $telefone = (string)($a['telefone'] ?? '');
              $telefoneLabel = $telefone !== '' ? $telefone : '-';
            ?>
            <tr>
              <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)"><?= h($a['id']) ?></td>
              <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)">
                <div style="font-weight:700"><?= h($a['nome'] ?? '-') ?></div>
                <div style="opacity:.7;font-size:12px"><?= h($a['email'] ?? '-') ?></div>
              </td>
              <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)"><?= h($telefoneLabel) ?></td>
              <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)"><?= h($turmaLabel) ?></td>
              <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06);opacity:.85"><?= h($tagsLabel) ?></td>
              <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)"><?= h($criadoLabel) ?></td>
              <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)">
                <a href="aluno_editar.php?id=<?= (int)$a['id'] ?>" style="color:#facc15;font-weight:700;text-decoration:none">Editar</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="margin-top:10px;font-size:12px;opacity:.65">
    Dica: se o banco está com <code>codigo_turma</code> preenchido e aqui aparece “-”, é porque a coluna exibida estava diferente; este arquivo força a leitura do <code>codigo_turma</code> (ou alternativas).
  </div>
</div>
<?php
if (file_exists($footer)) include $footer;
