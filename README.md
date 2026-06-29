# PeerScope

PeerScopeは、同業他社のWebサイトに掲載される新着情報を収集し、社内で確認・既読管理するためのLaravelアプリケーションです。

XAMPPのApache配下で `http://localhost/PeerScope` として動作することを想定しています。データベースはMySQLを使用します。

## 主な機能

- IDとパスワードによるログイン
- 管理者・一般ユーザーのロール管理
- ユーザー一覧のCSVダウンロード
- ユーザーCSVの手動取込
- 指定フォルダ配置CSVによるユーザー取込ジョブ
- 会社マスタ管理
- 収集先マスタ管理
- URL自動判定による新着情報収集
- RSS/HTMLページからの新着情報収集
- JavaScript配列形式の新着一覧収集
- JSON形式の新着一覧収集
- PDFリンクの自動保存と認証付きダウンロード
- 新着一覧の検索・会社絞り込み・日付絞り込み・既読絞り込み
- 記事詳細表示時の自動既読化
- Ollamaを使った記事詳細のAI要約生成
- AI要約用のリンク先HTML本文抽出、保存済みPDF本文抽出
- 収集ジョブ実行時のAI要約自動生成
- 収集ログ、収集エラーの確認
- 収集ログのCSVダウンロード
- 手動収集とスケジュール収集
- 収集中の進捗表示

## 技術構成

- PHP 8.2以上
- Laravel 12
- MySQL
- Bootstrap 5
- Bootstrap Icons
- Symfony CSS Selector
- Smalot PDF Parser
- Ollama
- XAMPP Apache

## ディレクトリ概要

```text
app/Services/Collection/   収集、解析、PDF保存、URL正規化
app/Http/Controllers/      画面操作用コントローラ
app/Console/Commands/      収集・テスト用artisanコマンド
database/migrations/       DBスキーマ
resources/views/           Bladeテンプレート
public/css/peerscope.css   PeerScope用CSS
routes/web.php             Webルート
routes/console.php         スケジュール設定
```

## セットアップ

1. 依存関係をインストールします。

```bash
composer install
npm install
```

2. `.env.example` をコピーして `.env` を作成します。

```bash
copy .env.example .env
php artisan key:generate
```

3. MySQLにデータベースを作成します。

```sql
CREATE DATABASE peerscope CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

4. `.env` のDB設定を確認します。

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=peerscope
DB_USERNAME=root
DB_PASSWORD=
ASSET_URL=/PeerScope/public
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=gemma4:e2b
OLLAMA_TIMEOUT=180
OLLAMA_CONNECT_TIMEOUT=5
OLLAMA_FETCH_TIMEOUT=20
OLLAMA_NUM_PREDICT=420
OLLAMA_MAX_INPUT_CHARS=12000
OLLAMA_EXTRACTED_TEXT_CHARS=12000
OLLAMA_KEEP_ALIVE=5m
OLLAMA_THINK=false
```

`ASSET_URL` はホスト名を含めず `/PeerScope/public` のようなパスだけにします。`http://localhost/...` を指定すると、他PCからIPアドレスでアクセスした時にCSSやJavaScriptが取得できません。

AI要約を使う場合はOllamaを起動し、`.env` の `OLLAMA_MODEL` に利用するモデル名を設定します。既定は `gemma4:e2b` です。初回のモデル読み込みや長めの記事では時間がかかるため、`OLLAMA_TIMEOUT` は180秒を既定にしています。要約の具体性を確保するため、リンク先HTML本文や抽出可能な保存済みPDF本文もAIへ渡します。thinking対応モデルで空応答になることを避けるため、要約用途では `OLLAMA_THINK=false` を既定にしています。

5. マイグレーションと初期データ投入を実行します。

```bash
php artisan migrate --seed
```

6. フロントエンドをビルドします。

```bash
npm run build
```

XAMPPのApache配下に配置する場合は、ブラウザで以下を開きます。

```text
http://localhost/PeerScope
```

## 認証仕様

ログインにはメールアドレスではなく、ユーザー登録時に任意作成した `login_id` とパスワードを使用します。

ロールは以下の2種類です。

- `admin`: ユーザー、会社、収集先、収集ログを管理できます。
- `user`: 新着情報の閲覧、既読化、PDFダウンロードができます。

ログインIDが `admin` のユーザーはシステム管理者として扱い、ユーザー管理画面やCSV取込での編集・削除対象外です。管理者ロールを持つ通常ユーザーは登録・編集できます。

## ユーザー一覧CSV

管理者はユーザー管理画面から登録済みユーザー一覧をCSV形式でダウンロードできます。

出力列はログインID、名前、メールアドレス、権限、作成日時、更新日時です。パスワードや認証トークンは出力しません。

## ユーザーCSV取込

管理者はユーザー管理画面からCSVをアップロードし、ユーザーを一括登録・削除できます。

CSVの列は以下です。

```csv
action,login_id,name,email,password,role
登録,user01,User One,user01@example.com,password123,user
登録,manager01,Manager One,manager01@example.com,password123,admin
削除,user02,,,,
```

| 列 | 内容 |
| --- | --- |
| `action` | `登録` / `追加` / `create`、または `削除` / `delete` |
| `login_id` | ログインID |
| `name` | 名前。削除時は空で可 |
| `email` | メールアドレス。削除時は空で可 |
| `password` | 登録時の初期パスワード。8文字以上 |
| `role` | `user` または `admin`。省略時は `user` |

1行でもエラーがある場合、そのCSVの変更は反映しません。

### フォルダ取込ジョブ

ユーザー管理画面で、CSV取込ジョブの有効化、実行時刻、取込フォルダ、処理済みフォルダ、失敗フォルダを設定できます。フォルダは `storage/app` 配下を画面の選択ボタンから選べます。

既定値は以下です。

| 項目 | 既定値 |
| --- | --- |
| 実行時刻 | `09:00` |
| 取込フォルダ | `user-import/inbox` |
| 処理済みフォルダ | `user-import/processed` |
| 失敗フォルダ | `user-import/failed` |

成功したCSVは処理済みフォルダへ移動します。失敗したCSVは失敗フォルダへ移動し、同名の `.error.txt` に理由を保存します。

## 収集仕様

収集先は `watch_sources` テーブルで管理します。

主な設定項目は以下です。

| 項目 | 内容 |
| --- | --- |
| `source_url` | 収集対象URL |
| `source_type` | `auto`、`rss`、`html` |
| `list_selector` | HTML収集時の一覧要素セレクタ |
| `title_selector` | タイトル要素セレクタ |
| `url_selector` | URL要素セレクタ |
| `date_selector` | 日付要素セレクタ |
| `body_selector` | 本文・概要要素セレクタ |
| `schedule_type` | `interval`、`daily`、`weekly`、`monthly` |
| `crawl_interval_minutes` | 一定間隔実行時の分数 |
| `schedule_time` | 毎日・毎週・毎月実行時の時刻 |
| `schedule_weekdays` | 毎週実行時の曜日 |
| `schedule_month_days` | 毎月実行時の日付 |
| `auto_ai_summary` | 収集ジョブ実行時に新規・更新記事へAI要約を自動生成するか |

### 自動判定収集

`source_type` を `auto` にすると、RSS/Atomフィード、HTML内のフィードリンク、既知形式の新着一覧、日付付きリンク一覧の順に判定して収集します。

自動判定で取得対象を特定できない場合は、`source_type` を `html` にしてCSSセレクタを指定します。

### RSS収集

RSS 2.0の `item` とAtomの `entry` に対応しています。

タイトル、リンク、公開日、概要、カテゴリを共通形式に変換して保存します。

### HTML収集

CSSセレクタで一覧、タイトル、URL、日付、本文を指定します。

`date_selector` には特殊指定として `previous:dt` を使用できます。これは `dd` の直前にある `dt` を日付として読むための設定です。

### JavaScript一覧収集

HTML本文ではなく `news_list.js` の `NewsListArray` に新着一覧が入っているサイトに対応しています。

この形式を使う場合は、収集先設定で以下を指定します。

```text
list_selector = js-news-list
```

ページ内の hidden `target` に一致するカテゴリだけを収集し、同じURLが複数カテゴリに出る場合は1件にまとめます。

### JSON一覧収集

HTML本文ではなくJSONデータから新着一覧を組み立てるサイトに対応しています。

この形式を使う場合は、収集先設定で以下を指定します。

```text
list_selector = json-news-list
```

通常記事、直接リンク、PDFリンクを共通形式に変換して保存します。

### URL正規化と重複判定

記事URLは保存前に正規化します。

- 相対URLを絶対URLへ変換
- `.` や `..` を含むパスを整理
- `utm_*`、`fbclid`、`gclid` などの計測パラメータを除外
- URLハッシュで同一URLの記事を判定
- 内容ハッシュで同一内容の記事の重複を抑制

### PDF保存

URLパスが `.pdf` で終わるリンクは、記事保存後にPDFとして取得します。

PDFは `storage/app/private/pdfs/{company_id}/` 配下に保存され、画面のダウンロード機能を通じて認証済みユーザーだけが取得できます。

PDF取得に失敗しても記事収集全体は止めません。

### 収集時AI要約

収集先ごとに「ジョブ実行時にAI要約する」を有効化できます。

有効な収集先では、収集ジョブで新規作成または更新された記事に対してOllamaのAI要約を生成し、`collected_items.ai_summary` に保存します。既に変更のない記事は毎回再要約しません。

AI要約生成に失敗しても記事収集全体は止めず、収集エラーとして記録します。AI要約はリンク先HTML本文や抽出可能な保存済みPDF本文も材料にするため、記事数が多い収集先ではジョブ時間が長くなる場合があります。

## 手動収集

管理者は収集先管理画面から収集先ごとに手動実行できます。

手動実行時は先に収集ログを作成し、Web画面を収集ログ詳細へすぐ遷移させます。実処理は `peerscope:run-collection` をバックグラウンド起動し、ログ詳細画面で進捗を確認できます。

バックグラウンド起動に失敗した場合は、同じWebリクエスト内で通常実行します。

## artisanコマンド

有効な収集先のうち、収集間隔を過ぎたものを収集します。

```bash
php artisan peerscope:collect
```

指定した収集先だけを収集します。

```bash
php artisan peerscope:collect-source {id}
```

収集先の取得・解析テストを実行します。DBへは保存しません。

```bash
php artisan peerscope:test-source {id}
```

作成済みの収集ログに紐づけて収集します。主にWeb手動実行から使います。

```bash
php artisan peerscope:run-collection {run_id} --source={watch_source_id}
```

設定フォルダに配置されたユーザーCSVを取り込みます。通常はスケジューラから実行されます。

```bash
php artisan peerscope:import-users-folder
```

設定の有効/無効や実行時刻に関係なく即時実行する場合は `--force` を付けます。

```bash
php artisan peerscope:import-users-folder --force
```

## スケジュール

`routes/console.php` で以下のスケジュールを定義しています。

```php
peerscope:collect
peerscope:import-users-folder
```

新着収集は毎分確認し、収集先ごとの実行周期に一致した場合のみ実行します。ユーザーCSVフォルダ取込も毎分確認し、設定した時刻を過ぎていて当日未実行の場合のみ実行します。

本番運用ではLaravel SchedulerをOSのcronやタスクスケジューラから毎分起動してください。

```bash
php artisan schedule:run
```

### Windowsタスクスケジューラ

XAMPPのWindows環境では、タスクスケジューラから `php-win.exe artisan schedule:run` を実行します。`php.exe` ではなく `php-win.exe` を使うことで、毎分コマンド画面が表示されることを避けます。

Windowsでは、通常実行時にSymfony Consoleの端末サイズ判定と開発用パッケージのPHPUnit向け初期化を抑止し、`stty`、`mode CON`、`git describe --tags` による一瞬の画面表示を防ぎます。テスト実行時はPHPUnit向け初期化を有効にします。

セットアップ手順は以下にあります。

```text
tools/windows-scheduler/
```

タスク登録は以下で実行できます。

```powershell
pwsh -NoProfile -ExecutionPolicy Bypass -File .\tools\windows-scheduler\register-task.ps1
```

独自にビルドしたexe形式のRunnerはWindowsのApplication Controlにブロックされる場合があるため、通常は使いません。Runnerのソースは `tools/windows-scheduler-runner/` に残しています。

## 主要テーブル

| テーブル | 内容 |
| --- | --- |
| `users` | ユーザー、ログインID、ロール |
| `companies` | 収集対象会社 |
| `watch_sources` | 収集先設定 |
| `collected_items` | 収集済み記事 |
| `item_reads` | ユーザー別既読状態 |
| `collection_runs` | 収集実行ログ |
| `collection_errors` | 収集エラー |
| `user_import_settings` | ユーザーCSVフォルダ取込設定 |

## 画面仕様

### ダッシュボード

- 本日の新着件数
- 未読件数
- 直近収集日時
- 直近24時間の収集エラー件数
- 会社別新着件数
- 最近の新着

### 新着一覧

- 掲載日が新しい順を基本に表示
- 掲載日が同じ場合は検知日時、IDの降順で表示
- キーワード、会社、掲載日、検知日、既読状態で絞り込み
- 一覧ではカテゴリと収集元名は表示しない

### 新着詳細

- 記事本文・概要を表示
- AI要約を生成し、生成済みの場合はAI要約を優先表示
- AI要約の生成中はボタンとメッセージで処理中状態を表示
- AI要約では保存済み本文、リンク先HTML本文、抽出可能な保存済みPDF本文を材料にする
- 要約に必要な本文を抽出できない場合は、タイトルだけの薄い要約を作らずエラー表示する
- AI要約生成に失敗した場合は収集時の概要を表示
- 元ページへのリンクを表示
- PDF保存済みの場合はダウンロードボタンを表示
- 詳細表示時に既読登録

### 収集ログ

- 収集対象がないスケジューラ確認では実行ログを作成しない
- 実行ログでは対象収集先の会社名、収集元名、URLを確認できる
- 実行対象がある収集ログをCSV形式でダウンロードできる
- 実行中、成功、一部失敗、失敗を日本語ラベルと色付きバッジで表示
- 実行中は一覧・詳細画面を自動更新
- エラー詳細では発生日時、会社、収集元、種別、内容を表示

## 収集先設定の例

各社のニュースページ、重要なお知らせページ、PDFリンクを含む告知ページなどを収集先として登録できます。

登録時は `auto` を基本とし、自動判定できない場合だけHTML構造に合わせてCSSセレクタや `js-news-list` 形式を設定します。

## テスト

```bash
php artisan test
```

コード整形はLaravel Pintを使用します。

```bash
vendor\bin\pint --dirty
```

## 注意事項

- `.env` はGit管理対象外です。
- `vendor/` と `node_modules/` はGit管理対象外です。
- PDF本体は `storage/app/private/` に保存され、Git管理対象外です。
- DBはMySQLを前提としています。SQLite運用は対象外です。
- XAMPP環境ではApacheのドキュメントルート配下に配置する構成を想定しています。
