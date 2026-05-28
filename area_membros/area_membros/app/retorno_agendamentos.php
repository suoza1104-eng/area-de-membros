<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

function retorno_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS retorno_agendamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        tipo VARCHAR(40) NOT NULL DEFAULT 'vendas',
        scheduled_at DATETIME NOT NULL,
        mensagem TEXT NULL,
        status ENUM('aguardando','enviado','erro','cancelado') NOT NULL DEFAULT 'aguardando',
        origem VARCHAR(80) NULL,
        payload_json LONGTEXT NULL,
        tentativas INT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_retorno_user (user_id),
        INDEX idx_retorno_status_data (status, scheduled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS retorno_modelos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(120) NOT NULL,
        tipo VARCHAR(40) NOT NULL DEFAULT 'vendas',
        mensagem TEXT NOT NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_retorno_modelo_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function retorno_tipos(): array {
    return [
        'vendas' => 'Vendas',
        'cobranca' => 'Cobranca',
        'reaquecimento' => 'Reaquecimento',
        'suporte' => 'Suporte',
        'outro' => 'Outro',
    ];
}

function retorno_status_label(string $status): string {
    $map = [
        'aguardando' => 'Aguardando',
        'enviado' => 'Enviado',
        'erro' => 'Erro',
        'cancelado' => 'Cancelado',
    ];
    return $map[$status] ?? $status;
}

function retorno_normalizar_tipo(string $tipo): string {
    $tipo = strtolower(trim($tipo));
    return array_key_exists($tipo, retorno_tipos()) ? $tipo : 'outro';
}

function retorno_parse_data_hora(string $valor): string {
    $valor = trim($valor);
    if ($valor === '') {
        throw new RuntimeException('Data e hora do agendamento sao obrigatorias.');
    }

    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $valor);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $ts = strtotime($valor);
    if ($ts === false) {
        throw new RuntimeException('Data e hora do agendamento invalidas.');
    }
    return date('Y-m-d H:i:s', $ts);
}

function retorno_primeiro_nome(?string $nome): string {
    $nome = trim((string)$nome);
    if ($nome === '') return '';
    $partes = preg_split('/\s+/', $nome);
    return (string)($partes[0] ?? $nome);
}

function retorno_render_mensagem(string $mensagem, array $user, array $agendamento): string {
    $replaces = [
        '{primeiro_nome}' => retorno_primeiro_nome((string)($user['nome'] ?? '')),
        '{nome}' => (string)($user['nome'] ?? ''),
        '{email}' => (string)($user['email'] ?? ''),
        '{telefone}' => (string)($user['telefone'] ?? ''),
        '{tipo}' => (string)($agendamento['tipo'] ?? ''),
        '{data_agendamento}' => (string)($agendamento['scheduled_at'] ?? ''),
    ];
    return strtr($mensagem, $replaces);
}

function retorno_buscar_usuario(PDO $pdo, int $userId): array {
    $st = $pdo->prepare("SELECT id, nome, email, telefone FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id' => $userId]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException('Aluno nao encontrado.');
    }
    return $user;
}

function retorno_criar_agendamento(PDO $pdo, int $userId, string $tipo, string $scheduledAt, string $mensagem, string $origem = 'manual', array $payload = []): int {
    retorno_ensure_tables($pdo);
    retorno_buscar_usuario($pdo, $userId);
    $tipo = retorno_normalizar_tipo($tipo);
    $scheduledAt = retorno_parse_data_hora($scheduledAt);
    $mensagem = trim($mensagem);

    $st = $pdo->prepare("INSERT INTO retorno_agendamentos
        (user_id, tipo, scheduled_at, mensagem, status, origem, payload_json, created_at)
        VALUES (:u, :t, :d, :m, 'aguardando', :o, :p, NOW())");
    $st->execute([
        ':u' => $userId,
        ':t' => $tipo,
        ':d' => $scheduledAt,
        ':m' => $mensagem !== '' ? $mensagem : null,
        ':o' => $origem,
        ':p' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function retorno_salvar_modelo(PDO $pdo, string $nome, string $tipo, string $mensagem, int $id = 0): int {
    retorno_ensure_tables($pdo);
    $nome = trim($nome);
    $mensagem = trim($mensagem);
    if ($nome === '' || $mensagem === '') {
        throw new RuntimeException('Nome e mensagem do modelo sao obrigatorios.');
    }
    $tipo = retorno_normalizar_tipo($tipo);
    if ($id > 0) {
        $pdo->prepare("UPDATE retorno_modelos SET nome=:n,tipo=:t,mensagem=:m WHERE id=:id")
            ->execute([':n' => $nome, ':t' => $tipo, ':m' => $mensagem, ':id' => $id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO retorno_modelos (nome,tipo,mensagem,created_at) VALUES (:n,:t,:m,NOW())")
        ->execute([':n' => $nome, ':t' => $tipo, ':m' => $mensagem]);
    return (int)$pdo->lastInsertId();
}

function retorno_listar_modelos(PDO $pdo): array {
    retorno_ensure_tables($pdo);
    return $pdo->query("SELECT id,nome,tipo,mensagem,is_default FROM retorno_modelos ORDER BY tipo ASC, nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function retorno_disparar_agendamento(PDO $pdo, array $agendamento): void {
    $id = (int)($agendamento['id'] ?? 0);
    $userId = (int)($agendamento['user_id'] ?? 0);
    if ($id <= 0 || $userId <= 0) {
        throw new RuntimeException('Agendamento invalido.');
    }

    $pdo->prepare("UPDATE retorno_agendamentos SET tentativas = tentativas + 1, last_error = NULL WHERE id = :id")
        ->execute([':id' => $id]);

    try {
        $user = retorno_buscar_usuario($pdo, $userId);
        $mensagem = (string)($agendamento['mensagem'] ?? '');
        $mensagemRenderizada = retorno_render_mensagem($mensagem, $user, $agendamento);
        disparar_webhooks('RETORNO_AGENDADO', $userId, [
            'agendamento_id' => $id,
            'tipo' => (string)($agendamento['tipo'] ?? ''),
            'scheduled_at' => (string)($agendamento['scheduled_at'] ?? ''),
            'mensagem' => $mensagem,
            'mensagem_renderizada' => $mensagemRenderizada,
            'origem' => (string)($agendamento['origem'] ?? ''),
        ]);
        $pdo->prepare("UPDATE retorno_agendamentos SET status='enviado', sent_at=NOW(), last_error=NULL WHERE id=:id")
            ->execute([':id' => $id]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE retorno_agendamentos SET status='erro', last_error=:e WHERE id=:id")
            ->execute([':e' => $e->getMessage(), ':id' => $id]);
        throw $e;
    }
}

function retorno_processar_devidos(PDO $pdo, int $limit = 50): array {
    retorno_ensure_tables($pdo);
    $limit = max(1, min(200, $limit));
    $rows = $pdo->query("SELECT * FROM retorno_agendamentos WHERE status='aguardando' AND scheduled_at <= NOW() ORDER BY scheduled_at ASC, id ASC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ok = 0;
    $erro = 0;
    foreach ($rows as $row) {
        try {
            retorno_disparar_agendamento($pdo, $row);
            $ok++;
        } catch (Throwable $e) {
            $erro++;
        }
    }
    return ['total' => count($rows), 'enviados' => $ok, 'erros' => $erro];
}
