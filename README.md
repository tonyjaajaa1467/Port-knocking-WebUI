# knock 面板 配置 & 使用說明

本文檔說明 knock PHP 面板如何與本機 `labean.service` 和 `apache2` 協同工作，包含架構、各組件職責、配置文件、操作流程、安全機制與故障排除。

---

## 1. 系統架構

```
訪客 ──HTTPS 443──> apache2 (labean-ssl.conf) ──PHP──> /knock/index.php
                                                    │
                                                    │ curl (直連 127.0.0.1:8181)
                                                    ▼
                                          labean.service (127.0.0.1:8181)
                                                    │
                                                    │ 讀取 X-Real-IP，執行 iptables 或 systemctl start/stop
                                                    ▼
                                              iptables INPUT 鏈
                                                    │
                                                    └── 放行訪客 IP 對應埠 (22 / 8787 / 442 / …)
```

### 三層分工

| 層級 | 程式 / 服務 | 職責 |
|------|------------|------|
| 前端 | apache2 (`labean-ssl.conf`, `*:443`) | 提供 `/knock` PHP 敲門頁，設定 `X-Real-IP` 為訪客真實 IP，`/secret/` 反代僅限 `127.0.0.1` |
| 中介 | `labean.service` (`/etc/labean.conf`) | 監聽 `127.0.0.1:8181`，URL 前綴 `a70a39af561fde90`，收到 `/on`/`/off` 後執行 iptables 加入/刪除規則，支援超時自動關門 |
| 執行 | iptables `INPUT` 或 systemctl start/stop 'service' | 對特定訪客 IP 放行 22 / 8787 / 442 等埠 或 systemctl start/stop 服務 |

---

## 2. 檔案位置

| 檔案 | 路径 | 說明 |
|------|------|------|
| 敲門面板 | `/var/www/html/knock/` | apache 實際提供服務的目錄 |
| labean 設定 | `/etc/labean.conf` | labean.service 的 JSON 設定 |
| apache knock 虛擬主機 | `/etc/apache2/sites-available/labean-ssl.conf` | `*:443`，提供 `/knock` 與 `/secret/` 反代 |
| apache 埠設定 | `/etc/apache2/ports.conf` | `Listen 443`、`Listen 442` |

---

## 3. 配置文件說明

### 3.1 `/home/$USER/knock/config.php`（需同步到 `/var/www/html/knock/`）

```php
return [
    'user'     => 'username',        // 登入帳號
    'passhash' => '$2y$10$...',           // 登入密碼雜湊 (password_hash)
    'session_ttl'    => 300,               // Session 過期秒數 (300 = 5 分鐘)
    'labean_url'     => 'http://127.0.0.1:8181/a70a39..........', // labean 端點
    'task'           => 'sshd',            // 預設顯示的 task
    'tasks'          => ['sshd', 'hermes-gui', 'openlist'], // 下拉選單列表
    'task_timeouts'  => [                  // 各 task 開門秒數，對應 labean.conf timeout
        'sshd'       => 60,
        'hermes-gui' => 0,                 // 0 = 永久，需手動關門
        'openlist'   => 0,
    ],
    'door_open_sec'  => 60,                // 預設開門秒數 (fallback)
    'log_file'       => '/var/www/html/knock/knock.log', // 操作日誌路徑
    'log_max_size'   => 10 * 1024 * 1024,  // 日誌輪轉上限 (10MB，0 = 不輪轉)
    'failure_file'   => '/var/www/html/knock/login_failures.json', // 登入失敗記錄
    'state_file'     => '/var/www/html/knock/knocked_state.json', // 開門狀態持久化
];
```

> `config.php` 被 apache `<Files config.php>` 設為 `Require all denied`，外部無法直接 HTTP 存取，但 PHP `require` 可正常讀取。

### 3.2 `/etc/labean.conf`

```json
{
  "listen": "127.0.0.1:8181",
  "url_prefix": "a70a39..........",
  "real_ip_header": "X-Real-IP",
  "allow_explicit_ips": false,
  "tasks": [
    {
      "name": "sshd",
      "timeout": 60,
      "on_command": "/usr/sbin/iptables -I INPUT -s {clientIP} -p tcp --dport 22 -j ACCEPT",
      "off_command": "/usr/sbin/iptables -D INPUT -s {clientIP} -p tcp --dport 22 -j ACCEPT"
    },
    {
      "name": "hermes-gui",
      "timeout": 0,
      "on_command": "/usr/sbin/iptables -I INPUT -s {clientIP} -p tcp -m multiport --dports 8787,442 -j ACCEPT",
      "off_command": "/usr/sbin/iptables -D INPUT -s {clientIP} -p tcp -m multiport --dports 8787,442 -j ACCEPT"
    },
    {
      "name": "openlist",
      "timeout": 0,
      "on_command": "sudo systemctl start openlist",
      "off_command": "sudo systemctl stop openlist"
    }
  ]
}
```

關鍵欄位：
- `listen`：labean 只綁定 `127.0.0.1:8181`，外網無法直接連線
- `url_prefix`：必須與 `config.php` 的 `labean_url` 路徑一致 (例如 `a70a39..........`)
- `real_ip_header`：labean 從 `X-Real-IP` 標頭讀取訪客 IP
- `tasks[].timeout`：開門後自動關閉秒數，`0` 表示不自動關閉
- `tasks[].on_command/off_command`：執行 iptables 放行/刪除規則，或 `systemctl start/stop` 服務

### 3.3 apache 虛擬主機

**`labean-ssl.conf`** (`*:443`)：
- 提供 `/knock` PHP 敲門頁
- `RequestHeader set X-Real-IP "expr=%{REMOTE_ADDR}"` 傳遞訪客真實 IP
- `/secret/` 反代到 `127.0.0.1:8181`，僅限 `127.0.0.1` 存取（防外部直接探測 labean）
- `<Files config.php>` 返回 403，防直接下載配置

---

## 4. 工作流程

1. 訪客透過 `https://your-site.com/knock/` (443) 進入登入頁
2. 登入成功後，Session 綁定客戶端 IP（換 IP 掉線），TTL 5 分鐘
3. 選擇 task（預設 `sshd`），點「開門」
4. PHP 以 `POST` + CSRF token 向 `http://127.0.0.1:8181/a70a39af561fde90/{task}/on` 發 curl，攜帶 `X-Real-IP: <訪客IP>`
5. labean 驗證 URL 前綴，讀取 `X-Real-IP`，執行對應的 `on_command` (iptables 放行)
6. PHP 將開門時間寫入 `knocked_state.json`，頁面顯示倒數剩餘秒數
7. 到期後 labean 自動執行 `off_command` (iptables 刪除規則)；PHP 頁面也會自動清理過期狀態
8. 點「關門」可立即執行 `off_command` 提前關閉

---

## 5. 使用說明 (使用者視角)

### 5.1 登入
- 開啟 `https://your-site.com/knock/`
- 輸入帳號與密碼，登入後 5 分鐘無操作自動登出
- 換 IP 會導致 Session 失效，需重新登入

### 5.2 開門
1. 在下拉選單選擇服務（`sshd` / `hermes-gui` / `openlist`）
2. 點擊「開門（X 秒）」或「啟動 openlist」
3. 頁面顯示「✅ [sshd] 門已開，60 秒內請連線」
4. `sshd` 類：60 秒後自動關閉；`hermes-gui` / `openlist` 類：永久開啟，需手動關閉
5. 開門期間「開門」按鈕停用，避免重複發送 iptables 規則

### 5.3 已開放的門
- 頁面中間顯示「🔓 已開放的門」列表，列出每個 task 與剩餘秒數
- `openlist` 顯示「運行中（服務）」
- 列表每 5 秒自動刷新，多開多個分頁時狀態會同步

### 5.4 關門
- 點對應 task 的「關門」按鈕，立即關閉該埠/服務
- 點「關閉全部門」可一次性關閉所有已開放的門（需確認對話框）

### 5.5 複製 IP
- IP 碼右側有「📋」按鈕，點擊可將客戶端 IP 複製到剪貼簿

### 5.6 登出
- 點擊「登出」銷毀 Session，但 `knocked_state.json` 保留，下次登入仍可看到正在進行的開門狀態

---

## 6. 配置步驟 (管理員)

### 6.1 初次部署
```bash
# 複製 knock 目錄到 apache 公開目錄
sudo cp -a /home/$USER/knock/* /var/www/html/knock/

# 設定日誌與狀態檔權限 (www-data 需寫入)
sudo touch /var/www/html/knock/knock.log /var/www/html/knock/knocked_state.json /var/www/html/knock/login_failures.json
sudo chown www-data:www-data /var/www/html/knock/knock.log /var/www/html/knock/knocked_state.json /var/www/html/knock/login_failures.json /var/www/html/knock/

# 確保 labean.service 正在運行
systemctl status labean
systemctl status apache2
```

### 6.2 修改配置後同步
```bash
cd /home/$USER/knock
sudo cp -a index.php config.php /var/www/html/knock/
# PHP 檔即時生效，不需重載 apache
```

### 6.3 修改 labean.conf 後
```bash
sudo systemctl restart labean
```

---

## 7. 安全機制

| 機制 | 說明 |
|------|------|
| Session 綁 IP | 登入後綁定客戶端 IP，換 IP 即掉線 |
| Session TTL | 5 分鐘無操作自動登出 |
| CSRF 保護 | 開門/關門/登入皆需 POST + CSRF token，拒絕 GET 狀態變更 |
| Session Cookie | `secure`、`httponly`、`samesite=Lax` |
| session_regenerate_id | 登入後重新產生 Session ID，防止會話固定攻擊 |
| 登入失敗封鎖 | 同一 IP 連續 5 次失敗，鎖定 15 分鐘，記錄於 `login_failures.json` |
| 錯誤訊息簡化 | 對使用者僅顯示「敲門失敗，請稍後再試」，詳細錯誤僅寫入 `knock.log` |
| labean 本地綁定 | labean 只監聽 `127.0.0.1:8181`，外網無法直接連線 |
| config.php 存取限制 | apache `<Files config.php>` 返回 403，PHP 內部 require 可讀 |
| 操作日誌 | 所有開門/關門動作皆寫入 `knock.log`，含 IP、task、動作、結果 |
| 日誌輪轉 | `knock.log` 超過 10MB 時自動壓縮為 `knock.log.YYYYMMDD_HHMMSS.gz` |

---

## 8. 故障排除

| 現象 | 可能原因 | 解決方式 |
|------|----------|----------|
| 登入後顯示「帳號已被暫時鎖定」 | 同一 IP 連續 5 次登入失敗 | 等待 15 分鐘，或刪除 `/var/www/html/knock/login_failures.json` |
| 點開門後顯示「敲門失敗」 | labean 未運行、URL 前綴不匹配、iptables 權限 | 檢查 `systemctl status labean`、`/var/log/apache2/labean_error.log`、`journalctl -u labean` |
| 開門後 iptables 沒規則 | labean `on_command` 執行失敗 (權限不足) | 確認 labean process 有 sudo/i 權限，或檢查 `journalctl -u labean` |
| 重複開門後 iptables 規則累積 | 重複點擊開門 | 現版已加防重複檢查，同一 task 在倒數中不會重複 curl |
| 登出後看不到已開門 | 舊版 Session 儲存狀態 | 現版改為 `knocked_state.json` 持久化，登出後不清除，登入即可看到 |
| 開門按鈕倒數到 0 仍停用 | JS 倒數未移除 disabled | 現版已修正，倒數歸零自動 `btn.disabled = false` |
| config.php 被直接下載 | apache 未設定 `<Files config.php>` deny | 確認 `labean-ssl.conf` 中有 `<Files "config.php"> Require all denied` |

查看 labean 日誌：
```bash
journalctl -u labean -f
```

查看 apache 錯誤日誌：
```bash
tail -f /var/log/apache2/labean_error.log
```

查看 knock 操作日誌：
```bash
tail -f /var/www/html/knock/knock.log
```

---

## 9. 新增 Task SOP

請參閱同目錄下的 `README_TASK_SOP.md`，摘要如下：

1. 在 `/etc/labean.conf` 的 `tasks` 陣列加入新 task 定義 (name, timeout, on_command, off_command)
2. `systemctl restart labean`
3. 在 `config.php` 的 `tasks` 與 `task_timeouts` 加入對應項目
4. `sudo cp -a index.php config.php /var/www/html/knock/`
5. 開啟瀏覽器驗證新 task 出現在下拉選單且開門正常

---

*文件更新於 2026-11-02，適用於 knock 面板當前版本。*
