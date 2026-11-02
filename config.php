<?php
// 登入帳密（用 password_hash 產生）
// 產生方式：php -r 'echo password_hash("你的密碼", PASSWORD_DEFAULT);'
return [
    'user'     => 'username',
    'passhash' => 'passhash',

    // session 過期秒數（300 = 5 分鐘無操作就掉登）
    'session_ttl'    => 300,
    // Labean 位址 (隨機字串用 openssl rand -hex 8 產生)
    'labean_url'     => 'http://127.0.0.1:8181/隨機字串',
    //'labean_url'     => 'http://127.0.0.1:8181/secret',
    // 預設 task
    'task'           => 'sshd',
    // 下拉選單顯示的列表
    'tasks'          => ['sshd', 'hermes-gui', 'openlist'],
    // 每個 task 的開門秒數（對應 /etc/labean.conf 的 timeout；0 表示不自動關閉，需手動關門）
    'task_timeouts'  => [
        'sshd'       => 60,
        'hermes-gui' => 0,
        'openlist'   => 0,
    ],
    // 預設 door_open_sec（當 task_timeouts 中沒有該 task 時的 fallback）
    'door_open_sec'  => 60,
    // 操作日誌（寫入失敗時靜默忽略；需 www-data 對目錄有寫入權限）
    'log_file'       => '/var/www/html/knock/knock.log',
    // 操作日誌最大檔案大位元組（超過時進行輪轉；0 表示不輪轉）
    'log_max_size'   => 10 * 1024 * 1024, // 10MB
    // 登入失敗記錄檔（IP 級別封鎖，檔案持久化）
    'failure_file'   => '/var/www/html/knock/login_failures.json',
];

