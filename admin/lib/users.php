<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * CRUD utilisateurs + helpers reset password + envoi de mail.
 *
 * Rôles supportés : 'admin' (tous droits) et 'moderator' (droits restreints — cf. auth.php).
 */

function user_all(): array
{
    $stmt = db()->query('SELECT id, username, email, role, created_at FROM users ORDER BY id ASC');
    return $stmt->fetchAll();
}

function user_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, username, email, role, created_at FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :e');
    $stmt->execute([':e' => $email]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function user_create(string $username, string $email, string $password, string $role): int
{
    $allowed = ['admin', 'moderator'];
    if (!in_array($role, $allowed, true)) {
        throw new RuntimeException('Ce rôle n\'est pas autorisé.');
    }
    if (!preg_match('/^[a-zA-Z0-9_.\-]{3,32}$/', $username)) {
        throw new RuntimeException('L\'identifiant doit faire entre 3 et 32 caractères (lettres, chiffres, _, ., -).');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Cet email n\'est pas valide.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Le mot de passe doit faire au moins 8 caractères.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = db()->prepare('INSERT INTO users (username, email, pass_hash, role) VALUES (:u, :e, :p, :r)');
        $stmt->execute([':u' => $username, ':e' => $email, ':p' => $hash, ':r' => $role]);
    } catch (PDOException $e) {
        throw greffe_humanize_pdo($e);
    }
    return (int) db()->lastInsertId();
}

function user_update(int $id, ?string $email = null, ?string $role = null, ?string $newPassword = null): void
{
    $sets = [];
    $params = [':id' => $id];
    if ($email !== null) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Cet email n\'est pas valide.');
        $sets[] = 'email = :e';
        $params[':e'] = $email;
    }
    if ($role !== null) {
        if (!in_array($role, ['admin', 'moderator'], true)) throw new RuntimeException('Ce rôle n\'est pas autorisé.');
        $sets[] = 'role = :r';
        $params[':r'] = $role;
    }
    if ($newPassword !== null && $newPassword !== '') {
        if (strlen($newPassword) < 8) throw new RuntimeException('Le mot de passe doit faire au moins 8 caractères.');
        $sets[] = 'pass_hash = :p';
        $params[':p'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }
    if (!$sets) return;
    try {
        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
        db()->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        throw greffe_humanize_pdo($e);
    }
}

function user_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

/**
 * Compte le nombre d'admins. Sert à empêcher la suppression du dernier admin.
 */
function user_admin_count(): int
{
    $row = db()->query("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")->fetch();
    return (int) ($row['n'] ?? 0);
}

/* ---------- Reset password ---------- */

function reset_token_create(int $userId): string
{
    $token = bin2hex(random_bytes(16));
    // Timestamp UNIX entier : pas de TZ, pas de datetime() côté SQLite, pas de gmdate vs date.
    // Validité 5h pour laisser le temps au mail d'arriver et à l'utilisateur de cliquer.
    $expires = (string) (time() + 5 * 3600);
    $stmt = db()->prepare('UPDATE users SET reset_token = :t, reset_expires = :e WHERE id = :id');
    $stmt->execute([':t' => $token, ':e' => $expires, ':id' => $userId]);
    $rowCount = $stmt->rowCount();

    // Diagnostic : vérifie post-write que la ligne est bien là. Fingerprint = 6 premiers/4 derniers.
    // Si created != relu, on a un souci de WAL / chemin DB / connexion qui apparaît immédiatement.
    if (defined('GREFFE_DATA_DIR')) {
        $r = db()->prepare('SELECT id, reset_token, reset_expires FROM users WHERE id = :id');
        $r->execute([':id' => $userId]);
        $row = $r->fetch();
        $storedTok = (string) ($row['reset_token'] ?? '');
        $match = ($storedTok === $token) ? 'OK' : 'MISMATCH';
        $short = substr($token, 0, 6) . '…' . substr($token, -4);
        $storedShort = $storedTok === '' ? '(empty)' : substr($storedTok, 0, 6) . '…' . substr($storedTok, -4);
        @file_put_contents(
            GREFFE_DATA_DIR . '/mail.log',
            sprintf(
                "[%s] reset_token_create uid=%d rows=%d issued=%s stored=%s readback=%s db=%s\n",
                date('Y-m-d H:i:s'),
                $userId,
                $rowCount,
                $short,
                $storedShort,
                $match,
                GREFFE_DB_PATH
            ),
            FILE_APPEND
        );
    }
    return $token;
}

function reset_token_verify(string $token): ?array
{
    // -------- DEBUG TRACE --------
    if (defined('GREFFE_DATA_DIR')) {
        @file_put_contents(
            GREFFE_DATA_DIR . '/reset-debug.log',
            sprintf(
                "[%s] verify ENTRY token_len=%d fp=%s\n",
                date('Y-m-d H:i:s'),
                strlen($token),
                $token === '' ? '(empty)' : substr($token, 0, 6) . '…' . substr($token, -4)
            ),
            FILE_APPEND
        );
    }
    // -----------------------------
    $token = trim($token);
    // Token = hex pur (bin2hex(random_bytes(16)) -> [0-9a-f]{32}).
    // On strippe TOUT char non-hex pour absorber les saletés que les clients mail
    // collent au bout (cf Gmail qui ajoute le 'e' de 'expire' à la zone clickable,
    // outlook qui ajoute '>', des newlines, etc).
    $token = preg_replace('/[^0-9a-f]/i', '', $token) ?? '';
    if ($token === '') { reset_token_log_fail('empty', $token, null); return null; }
    $stmt = db()->prepare('SELECT * FROM users WHERE reset_token = :t');
    $stmt->execute([':t' => $token]);
    $r = $stmt->fetch();

    // -------- DEBUG TRACE --------
    if (defined('GREFFE_DATA_DIR')) {
        @file_put_contents(
            GREFFE_DATA_DIR . '/reset-debug.log',
            sprintf(
                "[%s] verify SELECT result=%s row_id=%s row_expires=%s\n",
                date('Y-m-d H:i:s'),
                $r ? 'FOUND' : 'NOT_FOUND',
                $r ? (int) ($r['id'] ?? 0) : '-',
                $r ? (string) ($r['reset_expires'] ?? '') : '-'
            ),
            FILE_APPEND
        );
    }
    // -----------------------------

    if (!$r) { reset_token_log_fail('not_found', $token, null); return null; }
    // Comparaison en PHP, en timestamp UNIX. Rétro-compat : si reset_expires est encore
    // au format 'YYYY-MM-DD HH:MM:SS' (anciens tokens), strtotime gère les deux.
    $exp = (string) ($r['reset_expires'] ?? '');
    $expTs = ctype_digit($exp) ? (int) $exp : (int) strtotime($exp . ' UTC');
    if ($expTs <= 0 || $expTs <= time()) {
        reset_token_log_fail('expired', $token, ['stored_expires' => $exp, 'parsed_ts' => $expTs, 'now' => time()]);
        return null;
    }
    return $r;
}

/**
 * Diagnostic des échecs de verify : aide à comprendre pourquoi un lien échoue
 * (token obsolète vs absent vs expiré). Token tronqué dans le log pour éviter le take-over.
 */
function reset_token_log_fail(string $reason, string $token, ?array $extra): void
{
    if (!defined('GREFFE_DATA_DIR')) return;
    $short = $token === '' ? '(empty)' : substr($token, 0, 6) . '…' . substr($token, -4);
    $line = sprintf(
        "[%s] reset_verify FAIL reason=%s token=%s%s\n",
        date('Y-m-d H:i:s'),
        $reason,
        $short,
        $extra ? ' extra=' . json_encode($extra) : ''
    );
    @file_put_contents(GREFFE_DATA_DIR . '/mail.log', $line, FILE_APPEND);
}

function reset_token_clear(int $userId): void
{
    $stmt = db()->prepare('UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE id = :id');
    $stmt->execute([':id' => $userId]);
}

/* ---------- Mail ---------- */

/**
 * Envoie un mail texte simple via PHP mail().
 * Si l'envoi échoue (cas local dev sans MTA), on log dans admin/data/mail.log pour debug.
 * Renvoie true si mail() a renvoyé true, sinon false (le log existe quand même).
 */
function greffe_mail(string $to, string $subject, string $body): bool
{
    // From: dérivé du public_url STOCKÉ (capturé à l'install / login admin),
    // PAS de HTTP_HOST qui est attaquant-contrôlable sur la route publique /forgot.
    $publicUrl = function_exists('greffe_public_url') ? greffe_public_url() : '';
    $host = $publicUrl !== '' ? (string) parse_url($publicUrl, PHP_URL_HOST) : 'localhost';
    $from = 'no-reply@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);

    // Multipart alternative : plaintext (fallback) + HTML.
    // L'HTML met l'URL dans href -> les clients ne coupent jamais une href à 78 chars,
    // contrairement au plaintext où sendmail/postfix wrappent les longues lignes
    // et cassent le token (cas Hostinger + Gmail observé : URL coupée à ~76 chars).
    $boundary = 'g_' . bin2hex(random_bytes(8));
    $headers  = implode("\r\n", [
        'From: Greffe <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: Greffe',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);

    // Split body autour des URLs. Texte non-URL : htmlspecialchars + nl2br.
    // URL : htmlspecialchars UNE SEULE FOIS (donne &amp; dans href, que le browser
    // redécode en & au clic). Sans split, on encodait le body puis re-encodait l'URL
    // dans le callback -> &amp;amp; -> browser décodait en &amp; -> $_GET['amp;token']
    // au lieu de $_GET['token']. Cf log: ?p=reset&amp;token=… reason=empty.
    $parts = preg_split('#(https?://[^\s<>"\']+)#', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
    $htmlBody = '';
    foreach ($parts as $p) {
        if ($p !== '' && preg_match('#^https?://#', $p)) {
            $u = htmlspecialchars($p, ENT_QUOTES);
            $htmlBody .= '<a href="' . $u . '">' . $u . '</a>';
        } else {
            $htmlBody .= nl2br(htmlspecialchars($p, ENT_QUOTES));
        }
    }

    $msg  = "--$boundary\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $msg .= $body . "\r\n";
    $msg .= "--$boundary\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $msg .= "<!doctype html><html><body style=\"font:14px/1.5 -apple-system,Segoe UI,sans-serif\">$htmlBody</body></html>\r\n";
    $msg .= "--$boundary--\r\n";

    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $sent = @mail($to, $subjectEncoded, $msg, $headers);

    // Log pour debug, MAIS on redacte les tokens de reset pour éviter qu'une
    // lecture du log (mauvaise config NGINX, mauvais .htaccess, etc.) ne donne
    // un take-over de compte.
    $bodyForLog = preg_replace('/(token=)[0-9a-f]+/i', '$1[REDACTED]', $body);
    $log = sprintf(
        "[%s] to=%s sent=%s\nSubject: %s\n\n%s\n\n----\n",
        date('Y-m-d H:i:s'),
        $to,
        $sent ? 'OK' : 'FAIL',
        $subject,
        $bodyForLog
    );
    @file_put_contents(GREFFE_DATA_DIR . '/mail.log', $log, FILE_APPEND);
    return (bool) $sent;
}
