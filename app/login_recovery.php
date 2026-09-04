<?php
// FILE: app/login_recovery.php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/enrollment_service.php';

function login_recovery_ensure_schema(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_recovery_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(40) NOT NULL,
                email_typed VARCHAR(190) NULL,
                email_corrected VARCHAR(190) NULL,
                user_id INT NULL,
                name_provided VARCHAR(190) NULL,
                phone_provided VARCHAR(60) NULL,
                logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_lre_type (event_type),
                INDEX idx_lre_logged (logged_at),
                INDEX idx_lre_email (email_typed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {}
}

function login_recovery_get_config(PDO $pdo): array {
    return [
        'enabled' => (int)get_setting('login_recovery_enabled', '0') === 1,
        'title' => trim((string)get_setting('login_recovery_title', 'Tem certeza de que este é o seu e-mail?')) ?: 'Tem certeza de que este é o seu e-mail?',
        'subtitle' => trim((string)get_setting('login_recovery_subtitle', 'Confira o endereço digitado abaixo para evitar erros de acesso.')) ?: 'Confira o endereço digitado abaixo para evitar erros de acesso.',
        'btn_confirm' => trim((string)get_setting('login_recovery_btn_confirm', 'Sim, meu e-mail está certo')) ?: 'Sim, meu e-mail está certo',
        'btn_fix' => trim((string)get_setting('login_recovery_btn_fix', 'Não, digitei errado (Corrigir)')) ?: 'Não, digitei errado (Corrigir)',
        'step2_title' => trim((string)get_setting('login_recovery_step2_title', 'Fique tranquilo, vamos liberar seu acesso!')) ?: 'Fique tranquilo, vamos liberar seu acesso!',
        'step2_desc' => trim((string)get_setting('login_recovery_step2_desc', 'Informe seu nome e WhatsApp para localizarmos sua inscrição e redirecioná-lo para as aulas.')) ?: 'Informe seu nome e WhatsApp para localizarmos sua inscrição e redirecioná-lo para as aulas.',
    ];
}

function login_recovery_log_event(PDO $pdo, string $eventType, ?string $emailTyped, ?string $emailCorrected = null, ?int $userId = null, ?string $name = null, ?string $phone = null): void {
    try {
        login_recovery_ensure_schema($pdo);
        $st = $pdo->prepare("
            INSERT INTO login_recovery_events (event_type, email_typed, email_corrected, user_id, name_provided, phone_provided, logged_at)
            VALUES (:type, :etyped, :ecorrected, :uid, :name, :phone, NOW())
        ");
        $st->execute([
            ':type' => substr($eventType, 0, 40),
            ':etyped' => $emailTyped !== null && $emailTyped !== '' ? substr(strtolower(trim($emailTyped)), 0, 190) : null,
            ':ecorrected' => $emailCorrected !== null && $emailCorrected !== '' ? substr(strtolower(trim($emailCorrected)), 0, 190) : null,
            ':uid' => $userId && $userId > 0 ? $userId : null,
            ':name' => $name !== null && $name !== '' ? substr(trim($name), 0, 190) : null,
            ':phone' => $phone !== null && $phone !== '' ? substr(trim($phone), 0, 60) : null,
        ]);
    } catch (Throwable $e) {}
}

function login_recovery_format_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if (strlen($digits) >= 10 && strlen($digits) <= 11) {
        $ddd = substr($digits, 0, 2);
        $rest = substr($digits, 2);
        if (strlen($rest) === 9) {
            return sprintf('(%s) %s-%s', $ddd, substr($rest, 0, 5), substr($rest, 5));
        } elseif (strlen($rest) === 8) {
            return sprintf('(%s) %s-%s', $ddd, substr($rest, 0, 4), substr($rest, 4));
        }
    }
    return $phone;
}

function login_recovery_auto_register(PDO $pdo, string $email, string $nome, string $telefone): array {
    login_recovery_ensure_schema($pdo);
    
    $cleanEmail = strtolower(trim($email));
    $cleanNome  = trim($nome);
    $cleanTel   = login_recovery_format_phone(trim($telefone));
    
    if ($cleanEmail === '' || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Por favor, informe um e-mail válido.'];
    }
    if (mb_strlen($cleanNome) < 2) {
        return ['ok' => false, 'message' => 'Por favor, informe seu nome completo.'];
    }
    
    $digitsTel = preg_replace('/\D+/', '', $cleanTel) ?: '';
    if (strlen($digitsTel) < 10) {
        return ['ok' => false, 'message' => 'Por favor, informe seu WhatsApp com DDD (mínimo 10 dígitos).'];
    }
    
    // 1. Verificar se usuário já existe por email
    $stU = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stU->execute([':email' => $cleanEmail]);
    $user = $stU->fetch(PDO::FETCH_ASSOC);
    
    $userId = 0;
    if ($user) {
        $userId = (int)$user['id'];
        // Atualizar nome/telefone se estiverem vazios ou incompletos
        $updCols = [];
        $updParams = [':id' => $userId];
        if (empty($user['nome']) || trim((string)$user['nome']) === '') {
            $updCols[] = "nome = :nome";
            $updParams[':nome'] = $cleanNome;
        }
        if (empty($user['telefone']) || trim((string)$user['telefone']) === '') {
            $updCols[] = "telefone = :tel";
            $updParams[':tel'] = $cleanTel;
        }
        if ($updCols) {
            $sqlUpd = "UPDATE users SET " . implode(', ', $updCols) . " WHERE id = :id";
            $pdo->prepare($sqlUpd)->execute($updParams);
        }
    } else {
        // Verificar se usuário existe por telefone
        $stTel = $pdo->prepare("SELECT * FROM users WHERE telefone IS NOT NULL AND telefone <> '' AND REPLACE(REPLACE(REPLACE(REPLACE(telefone, '(', ''), ')', ''), '-', ''), ' ', '') LIKE :digits LIMIT 1");
        $stTel->execute([':digits' => '%' . $digitsTel . '%']);
        $userTel = $stTel->fetch(PDO::FETCH_ASSOC);
        
        if ($userTel) {
            $userId = (int)$userTel['id'];
            // Atualiza email se o cadastrado for genérico
            $pdo->prepare("UPDATE users SET email = :email, nome = COALESCE(NULLIF(nome, ''), :nome) WHERE id = :id")
                ->execute([':email' => $cleanEmail, ':nome' => $cleanNome, ':id' => $userId]);
        } else {
            // Criar novo usuário no banco
            $defaultPassword = bin2hex(random_bytes(6));
            $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
            $criadoEm = date('Y-m-d H:i:s');
            
            $ins = $pdo->prepare("
                INSERT INTO users (nome, email, telefone, senha_hash, criado_em, created_at, status)
                VALUES (:nome, :email, :tel, :hash, :criado, :created, 'ativo')
            ");
            $ins->execute([
                ':nome' => $cleanNome,
                ':email' => $cleanEmail,
                ':tel' => $cleanTel,
                ':hash' => $hash,
                ':criado' => $criadoEm,
                ':created' => $criadoEm,
            ]);
            $userId = (int)$pdo->lastInsertId();
        }
    }
    
    if ($userId <= 0) {
        return ['ok' => false, 'message' => 'Não foi possível concluir o cadastro automático. Fale com o suporte.'];
    }
    
    // 2. Garantir turma e acesso ao curso ativo
    try {
        if (function_exists('enrollment_ensure_active_course_access')) {
            enrollment_ensure_active_course_access($pdo, $userId);
        }
    } catch (Throwable $e) {}
    
    // 3. Adicionar Tag RECUPE_CADASTRO_LOGIN
    try {
        if (function_exists('adicionar_tag')) {
            adicionar_tag($userId, 'RECUPE_CADASTRO_LOGIN', 'login_recovery', null);
        }
    } catch (Throwable $e) {}
    
    // 4. Iniciar sessão e cookies de login
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
    $_SESSION['aluno_id'] = $userId;
    $_SESSION['just_recovered_login'] = true; // Trava para ignorar PWA/Push popups no primeiro acesso
    
    // Registrar último login
    try {
        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")->execute([':id' => $userId]);
    } catch (Throwable $e) {}
    
    // Definir token de autologin no cookie
    try {
        if (function_exists('am_set_token')) {
            am_set_token($pdo, $userId);
        }
    } catch (Throwable $e) {}
    
    // Cookie am_email
    if (function_exists('am_cookie_options')) {
        setcookie('am_email', $cleanEmail, am_cookie_options(time() + 60 * 60 * 24 * 365, false));
    }
    
    // Registrar evento de recuperação concluída
    login_recovery_log_event($pdo, 'auto_registered', $cleanEmail, $cleanEmail, $userId, $cleanNome, $cleanTel);
    
    return [
        'ok' => true,
        'user_id' => $userId,
        'redirect' => 'trilha.php',
        'message' => 'Acesso liberado com sucesso!'
    ];
}
