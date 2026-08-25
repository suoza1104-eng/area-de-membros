<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/dom_pagamentos.php';
proteger_admin();
$pdo = getPDO();

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Gatilho manual, protegido por login admin: sincroniza via API da DOM todos
 * os dias de um intervalo (padrao: mes atual ate hoje), cobrindo vendas que
 * o webhook em tempo real deixou passar. So roda quando o admin clica no
 * botao (POST) — nunca automaticamente em GET/crawler. Idempotente: pode ser
 * clicado mais de uma vez sem duplicar nada (upsert por transaction_code).
 */

$resultado = null;
$erroGeral = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmar'] ?? '') === '1') {
    $inicio = trim((string)($_POST['data_inicio'] ?? ''));
    $fim = trim((string)($_POST['data_fim'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) $inicio = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) $fim = date('Y-m-d');

    try {
        $dias = [];
        $cursor = new DateTimeImmutable($inicio);
        $limite = new DateTimeImmutable($fim);
        if ($cursor > $limite) throw new InvalidArgumentException('Data inicial nao pode ser depois da data final.');

        $resultado = ['dias' => [], 'total_listado' => 0, 'total_sincronizado' => 0, 'total_erros' => 0];
        while ($cursor <= $limite) {
            $data = $cursor->format('Y-m-d');
            try {
                $r = dom_sync_transactions_for_date($pdo, $data, 'backfill_manual_admin');
                $resultado['dias'][] = [
                    'data' => $data,
                    'listado' => $r['listed'] ?? 0,
                    'sincronizado' => $r['synced'] ?? 0,
                    'erros' => count($r['errors'] ?? []),
                    'mensagens_erro' => $r['errors'] ?? [],
                ];
                $resultado['total_listado'] += (int)($r['listed'] ?? 0);
                $resultado['total_sincronizado'] += (int)($r['synced'] ?? 0);
                $resultado['total_erros'] += count($r['errors'] ?? []);
            } catch (Throwable $e) {
                $resultado['dias'][] = [
                    'data' => $data, 'listado' => 0, 'sincronizado' => 0, 'erros' => 1,
                    'mensagens_erro' => [$e->getMessage()],
                ];
                $resultado['total_erros']++;
            }
            $cursor = $cursor->modify('+1 day');
        }
    } catch (Throwable $e) {
        $erroGeral = $e->getMessage();
    }
}

$menu = 'dom_backfill';
include __DIR__ . '/_header.php';
?>
<div class="card">
    <h2>Backfill DOM Pagamentos (via API)</h2>
    <p>Sincroniza, dia a dia, as vendas registradas na API da DOM Pagamentos para o banco local — cobre vendas que o webhook em tempo real nao capturou. Seguro rodar mais de uma vez: cada venda e gravada por <code>transaction_code</code>, sem duplicar.</p>

    <?php if ($erroGeral): ?>
        <div class="alert">Erro: <?= h($erroGeral) ?></div>
    <?php endif; ?>

    <?php if ($resultado): ?>
        <div class="alert alert-ok">
            Concluido. Total listado na API: <strong><?= (int)$resultado['total_listado'] ?></strong>
            &middot; Sincronizado: <strong><?= (int)$resultado['total_sincronizado'] ?></strong>
            &middot; Erros: <strong><?= (int)$resultado['total_erros'] ?></strong>
        </div>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Data</th><th>Listado</th><th>Sincronizado</th><th>Erros</th></tr></thead>
            <tbody>
            <?php foreach ($resultado['dias'] as $dia): ?>
                <tr>
                    <td><?= h($dia['data']) ?></td>
                    <td><?= (int)$dia['listado'] ?></td>
                    <td><?= (int)$dia['sincronizado'] ?></td>
                    <td>
                        <?= (int)$dia['erros'] ?>
                        <?php if ($dia['mensagens_erro']): ?>
                            <br><small><?= h(implode(' | ', array_slice($dia['mensagens_erro'], 0, 3))) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <form method="post" style="margin-top:20px">
        <div class="form-group">
            <label class="form-label">Data inicial</label>
            <input type="date" name="data_inicio" value="2026-08-01" style="background:var(--bg)">
        </div>
        <div class="form-group">
            <label class="form-label">Data final</label>
            <input type="date" name="data_fim" value="<?= h(date('Y-m-d')) ?>" style="background:var(--bg)">
        </div>
        <input type="hidden" name="confirmar" value="1">
        <button type="submit" class="btn btn-primary">Rodar sincronizacao</button>
    </form>
</div>
<?php
include __DIR__ . '/_footer.php';
