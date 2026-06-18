# PeerScopeSchedulerRunner

WindowsのタスクスケジューラからLaravel Schedulerを実行するための補助Runnerです。

`php artisan schedule:run` を画面表示なしで実行するため、1分ごとにコマンド画面が開く問題を避けられます。

## Git上の扱い

このディレクトリではRunnerのソースとセットアップスクリプトだけを管理します。

生成される `PeerScopeSchedulerRunner.exe` は環境依存の実行ファイルのため、Git管理対象外です。既定では以下に出力します。

```text
C:\xampp\htdocs\PeerScopeSchedulerRunner\PeerScopeSchedulerRunner.exe
```

## ビルド

PeerScopeのプロジェクトルートで以下を実行します。

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\windows-scheduler-runner\build.ps1
```

PowerShell 7を使う場合は `powershell` を `pwsh` に置き換えて実行できます。

出力先を変える場合は `-OutputDirectory` を指定します。

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\windows-scheduler-runner\build.ps1 -OutputDirectory C:\tools\PeerScopeSchedulerRunner
```

## タスク登録

ビルドとタスクスケジューラ登録をまとめて実行する場合は以下を実行します。

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\windows-scheduler-runner\register-task.ps1
```

既存タスクが実行中の場合、登録スクリプトはタスクを停止してからRunnerをビルドし直します。

既定値は以下です。

| 項目 | 値 |
| --- | --- |
| タスク名 | `PeerScope Laravel Scheduler` |
| Runner出力先 | `C:\xampp\htdocs\PeerScopeSchedulerRunner` |
| 作業フォルダ | PeerScopeプロジェクトルート |
| 実行間隔 | 1分ごと |

## パスの変更

Runnerは既定で以下を使用します。

| 用途 | 既定値 |
| --- | --- |
| PHP | `C:\xampp\php\php.exe` |
| PeerScope | `C:\xampp\htdocs\PeerScope` |
| ログ | `C:\xampp\htdocs\PeerScope\storage\logs\scheduler-runner.log` |

別パスで使う場合は、タスク側で以下の環境変数を設定してください。

```text
PEERSCOPE_PHP_PATH
PEERSCOPE_PROJECT_PATH
PEERSCOPE_SCHEDULER_LOG_PATH
```

Runnerはエラー発生時だけ `scheduler-runner.log` に内容を書き込みます。
