# knock 面板新增 task 標準作業程序 (SOP)

增加新的 port-knock task 時，需同時修改兩個設定檔，並同步到 apache 公開目錄。以下是完整步驟。

---

## 步驟 1：修改 /etc/labean.conf

在 `/etc/labean.conf` 的 `tasks` 陣列中加入新 task 定義。每個 task 需指定：
- `name`：task 識別名稱（需與 config.php 一致）
- `timeout`：開門自動關閉秒數（0 表示不自動關閉，需手動關門）
- `on_command`：開門時執行的 iptables / systemctl 指令，使用 `{clientIP}` 佔位符
- `off_command`：關門時執行的指令

範例：新增 `wireguard` task，開放 UDP 51820，60 秒後自動關閉

```json
{
  "name": "wireguard",
  "timeout": 60,
  "on_command": "/usr/sbin/iptables -I INPUT -s {clientIP} -p udp --dport 51820 -j ACCEPT",
  "off_command": "/usr/sbin/iptables -D INPUT -s {clientIP} -p udp --dport 51820 -j ACCEPT"
}
```

修改後重啟 labean 服務：

```bash
systemctl restart labean
```

---

## 步驟 2：修改 config.php

在 workspace 版 `/home/tony/.hermes/workspace/knock/config.php` 中：

1. 將新 task 名稱加入 `tasks` 陣列（下拉選單會自動顯示）
2. 在 `task_timeouts` 中加入對應的 timeout 秒數（需與 labean.conf 一致）

範例：

```php
'tasks'          => ['sshd', 'hermes-gui', 'openlist', 'wireguard'],
'task_timeouts'  => [
    'sshd'       => 60,
    'hermes-gui' => 0,
    'openlist'   => 0,
    'wireguard'  => 60,
],
```

---

## 步驟 3：同步到 apache 公開目錄

將修改後的 `config.php` 與 `index.php` 複製到 `/var/www/html/knock/`：

```bash
cd /home/tony/.hermes/workspace/knock
sudo cp -a index.php config.php /var/www/html/knock/
```

PHP 檔改動即時生效，**不需重載 apache**。

---

## 步驟 4：驗證

1. 開啟瀏覽器前往 `https://your-site.com/knock/` 並登入
2. 下拉選單應該出現新 task
3. 選擇新 task 並點「開門」，應該出現對應訊息
4. 檢查 iptables 規則是否正確加入：
   ```bash
   iptables -L INPUT -n --line-numbers | grep <訪客IP>
   ```
5. 等待 timeout 到期後，確認規則自動移除（若 timeout > 0）

---

## 注意事項

- **task 名稱必須在 config.php 與 labean.conf 中完全一致**，否則 knock 面板會送出 404。
- **`timeout = 0` 表示永久開門**，不會自動關閉，需手動點「關門」。
- **`config.php` 被 apache `<Files config.php>` 擋住直接 HTTP 存取**，但 PHP `require` 可正常讀取，無須移出 document root。
- 每次修改後建議備份：
  ```bash
  ts=$(date +%Y%m%d_%H%M%S)
  cp -a /home/tony/.hermes/workspace/knock "/home/tony/.hermes/workspace/knock.bak.$ts"
  ```

---

## 現有 task 對照表

| task | labean.conf timeout | 行為 |
|------|---------------------|------|
| sshd | 60 秒 | 開放 TCP 22，60 秒後自動關閉 |
| hermes-gui | 0 (永久) | 開放 TCP 8787, 442，需手動關閉 |
| openlist | 0 (永久) | `systemctl start/stop openlist`，需手動停止 |
