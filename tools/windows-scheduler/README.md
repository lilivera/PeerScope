# Windows Scheduler

WindowsのタスクスケジューラからPeerScopeのLaravel Schedulerを実行する設定です。

独自にビルドしたexeは、WindowsのApplication Controlや端末管理ソフトにブロックされることがあります。また、`php.exe` を直接タスク実行するとコマンド画面が表示される場合があります。

そのため、通常はXAMPP付属の `php-win.exe` を直接実行するこの方式を使います。PeerScope側では、Symfony Consoleが端末サイズ取得のために `stty` や `mode CON` を起動しないよう、Windowsでは `COLUMNS` と `LINES` をあらかじめ設定します。

また、開発用パッケージのPHPUnit向けautoloadが `git describe --tags` を起動して画面を出す場合があるため、通常実行ではその初期化だけを読み飛ばします。`artisan test` や `phpunit` 実行時は読み飛ばしません。

## タスク登録

PeerScopeのプロジェクトルートで以下を実行します。

```powershell
pwsh -NoProfile -ExecutionPolicy Bypass -File .\tools\windows-scheduler\register-task.ps1
```

登録されるタスクの既定値は以下です。

| 項目 | 値 |
| --- | --- |
| タスク名 | `PeerScope Laravel Scheduler Direct` |
| 実行ファイル | `C:\xampp\php\php-win.exe` |
| 引数 | `artisan schedule:run` |
| 作業フォルダ | PeerScopeプロジェクトルート |
| 実行間隔 | 1分ごと |

既存の `PeerScope Laravel Scheduler` タスクがある場合は、独自Runnerを参照している可能性があるため無効化します。

PeerScope側のスケジュール定義は、毎分の子プロセス起動でコマンド画面が表示されないよう、`Artisan::call` で同じPHPプロセス内に実行します。

## 手動確認

タスク登録後、以下で状態を確認できます。

```powershell
Get-ScheduledTaskInfo -TaskName "PeerScope Laravel Scheduler Direct"
```

`LastTaskResult` が `0` なら正常終了です。
