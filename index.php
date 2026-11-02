<?php
// Session Cookie 安全設定（必須在 session_start() 之前）
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => true,       // 僅透過 HTTPS 傳送
    'httponly' => true,       // JavaScript 無法讀取
    'samesite' => 'Lax',      // 防止 CSRF
]);
session_start();

// ---- 拿真實客戶端 IP ----
function getClientIP() {
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim($_SERVER[$key]);
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    // 退而求其次：回傳第一個非空值
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim($_SERVER[$key]);
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            return $ip;
        }
    }
    return '127.0.0.1';
}

$clientIP = getClientIP();
$conf = require __DIR__ . '/config.php';

// ---- 開門狀態持久化（JSON 檔，登出不清除）----
$stateFile = $conf['state_file'] ?? '/var/www/html/knock/knocked_state.json';

function loadKnockedState(string $file): array {
    if (!file_exists($file)) {
        return [];
    }
    $content = @file_get_contents($file);
    if ($content === false) {
        return [];
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

function saveKnockedState(string $file, array $state): bool {
    $dir = dirname($file);
    if (!is_writable($dir)) {
        return false;
    }
    $tmp = $file . '.tmp.' . getmypid();
    $result = file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($result === false) {
        @unlink($tmp);
        return false;
    }
    return @rename($tmp, $file);
}

// ---- 操作日誌輪轉與寫入 ----
function rotateLogIfNeeded(string $file, int $maxSize): void {
    if ($maxSize <= 0 || !file_exists($file)) {
        return;
    }
    if (@filesize($file) < $maxSize) {
        return;
    }
    $rotated = $file . '.' . date('Ymd_His') . '.gz';
    $content = @file_get_contents($file);
    if ($content === false) {
        return;
    }
    $gz = @gzopen($rotated, 'wb9');
    if ($gz === false) {
        return;
    }
    gzwrite($gz, $content);
    gzclose($gz);
    @unlink($file);
}

function writeKnockLog(string $file, string $line, int $maxSize): void {
    if (!$file || !is_writable(dirname($file))) {
        return;
    }
    rotateLogIfNeeded($file, $maxSize);
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// ---- 登入失敗記錄（IP 級別，檔案持久化）----
function loadLoginFailures(string $file): array {
    if (!file_exists($file)) {
        return [];
    }
    $content = @file_get_contents($file);
    if ($content === false) {
        return [];
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

function saveLoginFailures(string $file, array $data): bool {
    $dir = dirname($file);
    if (!is_writable($dir)) {
        return false;
    }
    $tmp = $file . '.tmp.' . getmypid();
    $result = file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($result === false) {
        @unlink($tmp);
        return false;
    }
    return @rename($tmp, $file);
}

// ---- CSRF token ----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---- 登出 ---
// 注意：只銷毀 Session，不刪除 knocked_state.json，因此登出後再登入仍可看到已開門狀態
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /knock/');
    exit;
}

// ---- 已登入檢查 ----
$authed = false;
if (isset($_SESSION['auth']) && $_SESSION['auth'] === true) {
    $elapsed = time() - ($_SESSION['login_time'] ?? 0);
    if ($elapsed < $conf['session_ttl']) {
        // 綁 IP：換 IP 就掉登
        if (($_SESSION['client_ip'] ?? '') === $clientIP) {
            $authed = true;
        }
    }
}

// ---- 載入開門狀態（檔案）----
$knocked = [];
if ($authed) {
    $knocked = loadKnockedState($stateFile);
}

// ---- 處理登入 POST ----
// 登入失敗封鎖設定（IP 級別，檔案持久化；換瀏覽器仍會繼續計數）
$failureFile = $conf['failure_file'] ?? '/var/www/html/knock/login_failures.json';
$maxFailures = 5;
$lockDuration = 900; // 15 分鐘

// 載入該 IP 的失敗狀態
$loginFailures = loadLoginFailures($failureFile);
$ipKey = $clientIP;
$ipData = $loginFailures[$ipKey] ?? ['failures' => 0, 'locked_until' => 0];
$loginLocked = false;
$loginLockedRemaining = 0;
if (!empty($ipData['locked_until']) && time() < $ipData['locked_until']) {
    $loginLocked = true;
    $loginLockedRemaining = $ipData['locked_until'] - time();
}

$error = '';
if (!$authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user'], $_POST['pass'])) {
    if ($loginLocked) {
        $error = "帳號已被暫時鎖定，請 {$loginLockedRemaining} 秒後再試";
    } elseif ($_POST['user'] === $conf['user']
        && password_verify($_POST['pass'], $conf['passhash'])) {
        $_SESSION['auth']        = true;
        $_SESSION['login_time']  = time();
        $_SESSION['client_ip']   = $clientIP;   // 存真實 IP
        $_SESSION['csrf_token']  = bin2hex(random_bytes(32));
        session_regenerate_id(true); // 防止會話固定攻擊，產生新 Session ID
        $authed = true;
        // 登入成功重置該 IP 失敗計數
        if (isset($loginFailures[$ipKey])) {
            unset($loginFailures[$ipKey]);
            saveLoginFailures($failureFile, $loginFailures);
        }
        // 登入後重新載入最新狀態
        $knocked = loadKnockedState($stateFile);
    } else {
        $failures = ($ipData['failures'] ?? 0) + 1;
        $loginFailures[$ipKey] = ['failures' => $failures, 'locked_until' => 0];
        if ($failures >= $maxFailures) {
            $loginFailures[$ipKey]['locked_until'] = time() + $lockDuration;
            saveLoginFailures($failureFile, $loginFailures);
            $error = "帳號已被暫時鎖定，請 {$lockDuration} 秒後再試";
        } else {
            saveLoginFailures($failureFile, $loginFailures);
            $remaining = $maxFailures - $failures;
            $error = "帳號或密碼錯誤（還可嘗試 {$remaining} 次）";
        }
    }
}

// ---- 處理敲門動作（POST + CSRF + 重試 + 防重複開門）----
$knock_msg = '';
$knock_err = '';
$current_task = preg_replace('/[^a-z0-9_-]/i', '', $_GET['task'] ?? ($conf['task'] ?? 'sshd'));

if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $knock_err = 'CSRF 驗證失敗';
    } else {
        $action = $_POST['action'];

        // P1 #6：一鍵關閉所有已開門
        if ($action === 'all_off') {
            $offResults = [];
            foreach (array_keys($knocked) as $t) {
                $url = $conf['labean_url'] . '/' . $t . '/off';
                $success = false;
                $lastBody = '';
                $lastHttp = 0;
                $lastErr = '';
                for ($try = 0; $try < 3; $try++) {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_HTTPHEADER     => [
                            'X-Real-IP: ' . $clientIP,
                        ],
                    ]);
                    $body = curl_exec($ch);
                    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curl_err = curl_error($ch);
                    curl_close($ch);
                    if ($http >= 200 && $http < 300) {
                        $success = true;
                        break;
                    }
                    $lastBody = $body;
                    $lastHttp = $http;
                    $lastErr = $curl_err;
                    if ($try < 2) {
                        usleep(500000);
                    }
                }
                // 操作日誌
                $logFile = $conf['log_file'] ?? '';
                $logMaxSize = $conf['log_max_size'] ?? 0;
                $line = sprintf("[%s] IP=%s task=%s action=off result=%s http=%s err=%s\n",
                    date('Y-m-d H:i:s'), $clientIP, $t, $success ? 'ok' : 'fail', $lastHttp, $lastErr ?: $lastBody);
                writeKnockLog($logFile, $line, $logMaxSize);
                $offResults[$t] = $success ? 'ok' : 'fail';
            }
            $knocked = [];
            saveKnockedState($stateFile, []);
            $okCount = array_count_values($offResults)['ok'] ?? 0;
            $totalCount = count($offResults);
            if ($totalCount === 0) {
                $knock_msg = 'ℹ️ 目前沒有已開放的門';
            } elseif ($okCount === $totalCount) {
                $knock_msg = "🔒 已關閉全部 {$totalCount} 個門";
            } else {
                $knock_msg = "⚠️ 關閉全部 {$totalCount} 個門中，成功 {$okCount}，失敗 " . ($totalCount - $okCount);
            }
        } elseif (!in_array($action, ['on', 'off'], true)) {
            $knock_err = '無效動作';
        } else {
            // P0 #1：若該 task 已在倒數中，不重複發送 labean 指令（避免 iptables 重複規則）
            $shouldCurl = true;
            $taskTimeout = $conf['task_timeouts'][$current_task] ?? $conf['door_open_sec'];
            if ($action === 'on' && isset($knocked[$current_task])) {
                if ($taskTimeout > 0) {
                    $remaining = max(0, $taskTimeout - (time() - $knocked[$current_task]));
                    if ($remaining > 0) {
                        $knock_msg = "ℹ️ [{$current_task}] 已開放中，剩餘 {$remaining} 秒";
                        $shouldCurl = false;
                    } else {
                        // 已到期，清除殘留，允許重新開門
                        unset($knocked[$current_task]);
                        saveKnockedState($stateFile, $knocked);
                    }
                } else {
                    // timeout = 0，永久開門，不允許重複開
                    $knock_msg = "ℹ️ [{$current_task}] 已開放中（永久，請手動關閉）";
                    $shouldCurl = false;
                }
            }

            if ($shouldCurl) {
                $url = $conf['labean_url'] . '/' . $current_task . '/' . $action;

                $success = false;
                $lastBody = '';
                $lastHttp = 0;
                $lastErr = '';
                for ($try = 0; $try < 3; $try++) {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_HTTPHEADER     => [
                            'X-Real-IP: ' . $clientIP,
                        ],
                    ]);
                    $body = curl_exec($ch);
                    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curl_err = curl_error($ch);
                    curl_close($ch);
                    if ($http >= 200 && $http < 300) {
                        $success = true;
                        break;
                    }
                    $lastBody = $body;
                    $lastHttp = $http;
                    $lastErr = $curl_err;
                    if ($try < 2) {
                        usleep(500000); // 0.5 秒後重試
                    }
                }

                if ($success) {
                    // 紀錄開門狀態（檔案，每個 task 獨立倒數）
                    if ($action === 'on') {
                        $knocked[$current_task] = time();
                    } else {
                        unset($knocked[$current_task]);
                    }
                    saveKnockedState($stateFile, $knocked);
                    $taskTimeout = $conf['task_timeouts'][$current_task] ?? $conf['door_open_sec'];
                    $knock_msg = $action === 'on'
                        ? ($taskTimeout > 0
                            ? "✅ [{$current_task}] 門已開，{$taskTimeout} 秒內請連線"
                            : "✅ [{$current_task}] 門已開（永久，請手動關閉）")
                        : "🔒 [{$current_task}] 門已關閉";
                    // 操作日誌
                    $logFile = $conf['log_file'] ?? '';
                    $logMaxSize = $conf['log_max_size'] ?? 0;
                    $line = sprintf("[%s] IP=%s task=%s action=%s result=ok\n",
                        date('Y-m-d H:i:s'), $clientIP, $current_task, $action);
                    writeKnockLog($logFile, $line, $logMaxSize);
                } else {
                    $knock_err = '敲門失敗，請稍後再試';
                    $logFile = $conf['log_file'] ?? '';
                    $logMaxSize = $conf['log_max_size'] ?? 0;
                    $line = sprintf("[%s] IP=%s task=%s action=%s result=fail http=%s err=%s\n",
                        date('Y-m-d H:i:s'), $clientIP, $current_task, $action, $lastHttp, $lastErr ?: $lastBody);
                    writeKnockLog($logFile, $line, $logMaxSize);
                }
            }
        }
    }
}

// 更新最後活動時間（續命）
if ($authed) {
    $_SESSION['login_time'] = time();
}

// 開門倒數剩餘秒數（每個 task 獨立），並清理已過期項目（僅清理 timeout > 0 的項目）
foreach ($knocked as $t => $at) {
    $tTimeout = $conf['task_timeouts'][$t] ?? $conf['door_open_sec'];
    if ($tTimeout > 0 && time() - $at >= $tTimeout) {
        unset($knocked[$t]);
    }
}
// 僅在登入狀態下才持久化（避免登出後 redirect 時把 JSON 覆寫回 []）
if ($authed) {
    saveKnockedState($stateFile, $knocked);
}

$knock_remaining = 0;
$knockPermanent = false;
if ($authed && isset($knocked[$current_task])) {
    $taskTimeout = $conf['task_timeouts'][$current_task] ?? $conf['door_open_sec'];
    if ($taskTimeout > 0) {
        $knock_remaining = max(0, $taskTimeout - (time() - $knocked[$current_task]));
    } else {
        $knockPermanent = true;
    }
}
$doorDisabled = $knock_remaining > 0 || $knockPermanent;
$taskTimeout = $conf['task_timeouts'][$current_task] ?? $conf['door_open_sec'];
$isServiceTask = in_array($current_task, ['openlist'], true);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Port Knock</title>
<style>
    body { font-family: monospace; background: #0d1117; color: #c9d1d9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
    .box { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 1rem; max-width: 380px; width: 90vw; box-sizing: border-box; }
    h2 { margin-top: 0; color: #58a6ff; }
    input, button, select { width: 100%; padding: 0.6rem; margin: 0.4rem 0; border-radius: 4px; border: 1px solid #30363d; background: #0d1117; color: #c9d1d9; box-sizing: border-box; }
    select { cursor: pointer; }
    button { background: #238636; border-color: #2ea043; cursor: pointer; font-weight: bold; }
    button:hover { background: #2ea043; }
    button:disabled { cursor: not-allowed; opacity: 0.6; background: #2f363d; border-color: #30363d; color: #8b949e; }
    .btn-off { background: #da3633; border-color: #f85149; }
    .btn-off:hover { background: #f85149; }
    .msg { padding: 0.6rem; border-radius: 4px; margin: 0.6rem 0; }
    .ok { background: #0f3a1f; border: 1px solid #2ea043; }
    .err { background: #3a0f0f; border: 1px solid #f85149; }
    .meta { font-size: 0.8rem; color: #8b949e; margin-top: 1rem; }
    a { color: #58a6ff; text-decoration: none; }
    .row { display: flex; gap: 0.4rem; }
    .row a { flex: 1; }
    .countdown { color: #58a6ff; font-weight: bold; }
    .opened-list { font-size: 0.85rem; margin: 0.6rem 0; padding: 0.5rem; background: #0d1117; border: 1px solid #30363d; border-radius: 4px; }
    .opened-list strong { color: #58a6ff; }
    .opened-list ul { margin: 0.3rem 0 0 0; padding-left: 1.2rem; }
    .opened-list li { margin: 0.15rem 0; color: #c9d1d9; }
    .opened-list li .rem { color: #58a6ff; font-weight: bold; }
</style>
</head>
<body>
<div class="box">
<?php if (!$authed): ?>
    <h2>🔐 登入</h2>
    <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input name="user" placeholder="帳號" autofocus required>
        <input name="pass" type="password" placeholder="密碼" required>
        <button type="submit">登入</button>
    </form>
    <div class="meta">登入後 <?= $conf['session_ttl'] ?> 秒無操作自動登出</div>
<?php else: ?>
    <h2>🚪 敲門面板</h2>
    <?php if ($knock_msg): ?><div class="msg ok"><?= htmlspecialchars($knock_msg) ?></div><?php endif; ?>
    <?php if ($knock_err): ?><div class="msg err"><?= htmlspecialchars($knock_err) ?></div><?php endif; ?>

    <p style="font-size:0.85rem">
        IP:
        <code id="clientIp" style="vertical-align:middle;"><?= htmlspecialchars($clientIP) ?></code>
        <button type="button" onclick="copyToClipboard('clientIp')" title="複製 IP" style="width:auto;vertical-align:middle;margin-left:0.4rem;padding:0.2rem 0.6rem;">📋</button>
    </p>

    <!-- 服務選擇下拉 -->
    <form method="GET">
        <select name="task" onchange="this.form.submit()">
            <?php
            $task_list = $conf['tasks'] ?? [$conf['task'] ?? 'sshd'];
            foreach ($task_list as $t) {
                $sel = ($t === $current_task) ? 'selected' : '';
                echo "<option value=\"{$t}\" {$sel}>{$t}</option>";
            }
            ?>
        </select>
    </form>

    <div class="row" style="margin-top:0.6rem">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="task" value="<?= htmlspecialchars($current_task) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="on">
            <button type="submit" id="openBtn" <?= $doorDisabled ? 'disabled' : '' ?>>
                <?php if ($isServiceTask): ?>
                    啟動 <?= htmlspecialchars($current_task) ?>
                <?php else: ?>
                    開門（<?= $taskTimeout > 0 ? $taskTimeout . '秒' : '永久'; ?>）
                <?php endif; ?>
                <?php if (!$isServiceTask && $knock_remaining > 0): ?> <span class="countdown">剩 <?= $knock_remaining ?> 秒</span><?php endif; ?>
                <?php if (!$isServiceTask && $knockPermanent): ?> <span class="countdown">永久</span><?php endif; ?>
            </button>
        </form>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="task" value="<?= htmlspecialchars($current_task) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="off">
            <button type="submit" class="btn-off">
                <?php if ($isServiceTask): ?>
                    停止 <?= htmlspecialchars($current_task) ?>
                <?php else: ?>
                    關門
                <?php endif; ?>
            </button>
        </form>
    </div>

    <!-- P1 #5：顯示目前已開放的門（多 task 獨立倒數，登出後仍保留） -->
    <?php if ($authed && !empty($knocked)): ?>
    <div class="opened-list">
        <strong>🔓 已開放的門：</strong>
        <ul>
        <?php foreach ($knocked as $t => $at): 
            $tTimeout = $conf['task_timeouts'][$t] ?? $conf['door_open_sec'];
            if ($tTimeout > 0) {
                $rem = max(0, $tTimeout - (time() - $at));
                if ($rem <= 0) continue;
                $remText = '<span class="rem">' . $rem . '</span> 秒';
            } else {
                $remText = '<span class="rem">永久</span>';
            }
        ?>
            <li><?= htmlspecialchars($t) ?>：
                <?php if (in_array($t, ['openlist'], true)): ?>
                    <span class="rem">運行中（服務）</span>
                <?php else: ?>
                    剩餘 <?= $remText ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- P1 #6：已開門列表自動刷新（每 5 秒重載，確保多 tab 狀態同步） -->
    <?php if ($authed && !empty($knocked)): ?>
    <script>
    (function() {
        setInterval(function() {
            location.reload();
        }, 5000);
    })();
    </script>
    <?php endif; ?>

    <!-- 一鍵關閉全部已開門 -->
    <?php if ($authed && !empty($knocked)): ?>
    <div class="row" style="margin-top:0.6rem;">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="all_off">
            <button type="submit" class="btn-off" onclick="return confirm('確定要關閉所有已開放的門嗎？')">關閉全部門</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($knock_remaining > 0): ?>
    <div class="meta" id="countdown">自動關門倒數：<span id="sec"><?= $knock_remaining ?></span> 秒</div>
    <script>
    (function() {
        var sec = parseInt(document.getElementById('sec').textContent, 10) || 0;
        var btn = document.getElementById('openBtn');
        var timer = setInterval(function() {
            sec -= 1;
            if (sec <= 0) {
                document.getElementById('sec').textContent = '0';
                document.getElementById('countdown').textContent = '自動關門倒數：已到期';
                if (btn) btn.disabled = false;
                clearInterval(timer);
                return;
            }
            document.getElementById('sec').textContent = sec;
        }, 1000);
    })();
    </script>
    <?php endif; ?>

    <script>
    function copyToClipboard(elementId) {
        var text = document.getElementById(elementId).textContent;
        navigator.clipboard.writeText(text).then(function() {
            // 複製成功可在此加反饋，目前靜默
        }, function(err) {
            console.error('複製失敗: ', err);
        });
    }
    </script>

    <a href="?logout=1"><button style="background:#30363d;margin-top:0.4rem">登出</button></a>

    <div class="meta">
        Session 剩餘: <?= max(0, $conf['session_ttl'] - (time() - $_SESSION['login_time'])) ?> 秒<br>
        <a href="/">← 回到首頁</a>
    </div>
<?php endif; ?>
</div>
</body>
</html>
