<?php

namespace Tests\Feature;

use App\Models\CollectedItem;
use App\Models\CollectionRun;
use App\Models\Company;
use App\Models\User;
use App\Models\UserImportSetting;
use App\Models\WatchSource;
use App\Services\Collection\NewsCollectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PeerScopeFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_items(): void
    {
        $this->get('/items')->assertRedirect('/login');
    }

    public function test_user_can_login_with_id_and_password(): void
    {
        $user = User::factory()->create([
            'login_id' => 'member01',
            'password' => 'secret-password',
            'role' => 'user',
        ]);

        $this->post('/login', [
            'login_id' => 'member01',
            'password' => 'secret-password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_can_register_user_with_custom_login_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Staff User',
            'login_id' => 'staff-01',
            'email' => 'staff@example.com',
            'role' => 'admin',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'name' => 'Staff User',
            'login_id' => 'staff-01',
            'email' => 'staff@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_user_management_hides_edit_button_only_for_admin_login_id(): void
    {
        $systemAdmin = User::factory()->create([
            'name' => 'System Admin',
            'login_id' => 'admin',
            'role' => 'admin',
        ]);
        $adminUser = User::factory()->create([
            'name' => 'Editable Admin',
            'login_id' => 'editable-admin',
            'role' => 'admin',
        ]);

        $response = $this->actingAs($systemAdmin)->get(route('users.index'));

        $response
            ->assertOk()
            ->assertSee('admin')
            ->assertSee('editable-admin')
            ->assertDontSee(route('users.edit', $systemAdmin), false)
            ->assertSee(route('users.edit', $adminUser), false);

        $this->actingAs($systemAdmin)->post(route('users.store'), [
            'name' => 'Role Forced User',
            'login_id' => 'role-forced',
            'email' => 'role-forced@example.com',
            'role' => 'admin',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'login_id' => 'role-forced',
            'role' => 'admin',
        ]);
    }

    public function test_user_csv_import_creates_and_deletes_general_users(): void
    {
        $admin = User::factory()->create([
            'name' => 'System Admin',
            'login_id' => 'admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        $oldUser = User::factory()->create([
            'login_id' => 'old-user',
            'email' => 'old-user@example.com',
            'role' => 'user',
        ]);
        $csv = implode("\n", [
            'action,login_id,name,email,password',
            '登録,csv-user,CSV User,csv-user@example.com,password123',
            '削除,old-user,,,',
        ]);

        $this->actingAs($admin)
            ->post(route('users.import'), [
                'csv_file' => UploadedFile::fake()->createWithContent('users.csv', $csv),
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'login_id' => 'csv-user',
            'email' => 'csv-user@example.com',
            'role' => 'user',
        ]);
        $this->assertDatabaseMissing('users', [
            'id' => $oldUser->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => 'admin',
        ]);
    }

    public function test_user_csv_import_rejects_admin_rows_without_partial_changes(): void
    {
        $admin = User::factory()->create([
            'login_id' => 'admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        $csv = implode("\n", [
            'action,login_id,name,email,password,role',
            '削除,admin,,,',
            '登録,should-not-create,CSV User,should-not-create@example.com,password123,user',
        ]);

        $this->actingAs($admin)
            ->post(route('users.import'), [
                'csv_file' => UploadedFile::fake()->createWithContent('users.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('csv_file');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => 'admin',
        ]);
        $this->assertDatabaseMissing('users', [
            'login_id' => 'should-not-create',
        ]);
    }

    public function test_user_csv_import_can_create_admin_role_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $csv = implode("\n", [
            'action,login_id,name,email,password,role',
            '登録,csv-admin,CSV Admin,csv-admin@example.com,password123,admin',
        ]);

        $this->actingAs($admin)
            ->post(route('users.import'), [
                'csv_file' => UploadedFile::fake()->createWithContent('users.csv', $csv),
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'login_id' => 'csv-admin',
            'email' => 'csv-admin@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_user_import_folder_job_setting_can_be_saved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('users.import-setting.update'), [
            'is_enabled' => '1',
            'scheduled_time' => '13:30',
            'import_directory' => 'user-import/inbox-test',
            'processed_directory' => 'user-import/processed-test',
            'failed_directory' => 'user-import/failed-test',
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('user_import_settings', [
            'is_enabled' => true,
            'scheduled_time' => '13:30',
            'import_directory' => 'user-import/inbox-test',
            'processed_directory' => 'user-import/processed-test',
            'failed_directory' => 'user-import/failed-test',
        ]);
    }

    public function test_user_import_directory_picker_lists_storage_folders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('users.import-directories'))
            ->assertOk()
            ->assertJsonPath('current.label', 'storage/app')
            ->assertJsonFragment([
                'name' => 'user-import',
                'path' => 'user-import',
            ]);

        $this->actingAs($admin)
            ->getJson(route('users.import-directories', ['path' => 'user-import']))
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'inbox',
                'path' => 'user-import/inbox',
            ]);
    }

    public function test_user_import_folder_command_imports_csv_files(): void
    {
        $basePath = storage_path('framework/testing/user-import-job');
        File::deleteDirectory($basePath);
        File::ensureDirectoryExists($basePath.'/inbox');

        UserImportSetting::current()->update([
            'is_enabled' => true,
            'scheduled_time' => now()->format('H:i'),
            'import_directory' => $basePath.'/inbox',
            'processed_directory' => $basePath.'/processed',
            'failed_directory' => $basePath.'/failed',
        ]);

        File::put($basePath.'/inbox/users.csv', implode("\n", [
            'action,login_id,name,email,password',
            '登録,folder-user,Folder User,folder-user@example.com,password123',
        ]));

        $exitCode = Artisan::call('peerscope:import-users-folder');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('users', [
            'login_id' => 'folder-user',
            'email' => 'folder-user@example.com',
            'role' => 'user',
        ]);
        $this->assertFalse(File::exists($basePath.'/inbox/users.csv'));
        $this->assertCount(1, File::files($basePath.'/processed'));
        $this->assertSame([], File::isDirectory($basePath.'/failed') ? File::files($basePath.'/failed') : []);

        File::deleteDirectory($basePath);
    }

    public function test_user_import_folder_command_waits_until_scheduled_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00'));

        $basePath = storage_path('framework/testing/user-import-wait');

        try {
            File::deleteDirectory($basePath);
            File::ensureDirectoryExists($basePath.'/inbox');

            UserImportSetting::current()->update([
                'is_enabled' => true,
                'scheduled_time' => '10:30',
                'import_directory' => $basePath.'/inbox',
                'processed_directory' => $basePath.'/processed',
                'failed_directory' => $basePath.'/failed',
            ]);

            File::put($basePath.'/inbox/users.csv', implode("\n", [
                'action,login_id,name,email,password',
                '登録,waiting-user,Waiting User,waiting-user@example.com,password123',
            ]));

            $exitCode = Artisan::call('peerscope:import-users-folder');

            $this->assertSame(0, $exitCode);
            $this->assertDatabaseMissing('users', [
                'login_id' => 'waiting-user',
            ]);
            $this->assertTrue(File::exists($basePath.'/inbox/users.csv'));
        } finally {
            File::deleteDirectory($basePath);
            Carbon::setTestNow();
        }
    }

    public function test_admin_user_is_not_editable_from_user_management(): void
    {
        $systemAdmin = User::factory()->create([
            'login_id' => 'admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        $adminUser = User::factory()->create([
            'login_id' => 'editable-admin',
            'email' => 'editable-admin@example.com',
            'role' => 'admin',
        ]);

        $this->actingAs($systemAdmin)
            ->get(route('users.edit', $systemAdmin))
            ->assertForbidden();

        $this->actingAs($systemAdmin)
            ->put(route('users.update', $systemAdmin), [
                'name' => 'Changed Admin',
                'login_id' => 'admin2',
                'email' => 'admin2@example.com',
                'role' => 'admin',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertForbidden();

        $this->actingAs($systemAdmin)
            ->put(route('users.update', $adminUser), [
                'name' => 'Edited Admin User',
                'login_id' => 'editable-admin',
                'email' => 'editable-admin@example.com',
                'role' => 'admin',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $systemAdmin->id,
            'login_id' => 'admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $adminUser->id,
            'name' => 'Edited Admin User',
            'role' => 'admin',
        ]);
    }

    public function test_admin_can_create_company_and_user_cannot_open_admin_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('companies.store'), [
            'name' => 'Example Peer',
            'official_url' => 'https://example.com',
            'memo' => 'watch target',
            'is_active' => '1',
        ])->assertRedirect(route('companies.index'));

        $this->assertDatabaseHas('companies', [
            'name' => 'Example Peer',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->get(route('companies.index'))->assertForbidden();
    }

    public function test_showing_item_marks_it_as_read(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/news.xml',
            'source_type' => 'rss',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);
        $item = CollectedItem::create([
            'company_id' => $company->id,
            'watch_source_id' => $source->id,
            'title' => '新商品のお知らせ',
            'url' => 'https://example.com/news/1',
            'url_hash' => hash('sha256', 'https://example.com/news/1'),
            'content_hash' => hash('sha256', '新商品のお知らせ'),
            'detected_at' => now(),
            'summary' => '抜粋',
        ]);

        $this->actingAs($user)->get(route('items.show', $item))->assertOk();

        $this->assertDatabaseHas('item_reads', [
            'user_id' => $user->id,
            'collected_item_id' => $item->id,
        ]);
    }

    public function test_items_index_orders_by_newest_published_date_first(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/news',
            'source_type' => 'html',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        CollectedItem::create([
            'company_id' => $company->id,
            'watch_source_id' => $source->id,
            'title' => '古い掲載日',
            'url' => 'https://example.com/old',
            'url_hash' => hash('sha256', 'https://example.com/old'),
            'content_hash' => hash('sha256', 'old'),
            'published_at' => '2026-05-01 00:00:00',
            'detected_at' => '2026-06-02 00:00:00',
        ]);
        CollectedItem::create([
            'company_id' => $company->id,
            'watch_source_id' => $source->id,
            'title' => '新しい掲載日',
            'url' => 'https://example.com/new',
            'url_hash' => hash('sha256', 'https://example.com/new'),
            'content_hash' => hash('sha256', 'new'),
            'published_at' => '2026-06-01 00:00:00',
            'detected_at' => '2026-05-01 00:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('items.index'))
            ->assertSeeInOrder(['新しい掲載日', '古い掲載日']);
    }

    public function test_rss_collection_deduplicates_by_url_hash(): void
    {
        Http::fake([
            'https://example.com/rss.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>IRニュース</title>
      <link>https://example.com/ir/1?utm_source=test#top</link>
      <pubDate>Mon, 01 Jun 2026 09:00:00 +0900</pubDate>
      <description>決算に関するお知らせ</description>
      <category>IR</category>
    </item>
  </channel>
</rss>
XML, 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'IR',
            'source_url' => 'https://example.com/rss.xml',
            'source_type' => 'rss',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        $collector = app(NewsCollectorService::class);

        $firstRun = $collector->collectSource($source);
        $secondRun = $collector->collectSource($source->fresh());

        $this->assertSame(1, $firstRun->created_count);
        $this->assertSame(0, $secondRun->created_count);
        $this->assertDatabaseCount('collected_items', 1);
        $this->assertDatabaseHas('collected_items', [
            'title' => 'IRニュース',
            'url' => 'https://example.com/ir/1',
        ]);
    }

    public function test_admin_can_manually_execute_collection_from_watch_source_page(): void
    {
        Http::fake([
            'https://example.com/rss.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>手実行のお知らせ</title>
      <link>https://example.com/manual/1</link>
      <pubDate>Mon, 01 Jun 2026 09:00:00 +0900</pubDate>
      <description>手動収集で登録される記事</description>
    </item>
  </channel>
</rss>
XML, 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
            'source_type' => 'rss',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('watch-sources.collect', $source));
        $run = CollectionRun::query()->firstOrFail();

        $response->assertRedirect(route('collection-runs.show', $run));
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->created_count);
        $this->assertDatabaseHas('collected_items', [
            'title' => '手実行のお知らせ',
            'url' => 'https://example.com/manual/1',
        ]);
    }

    public function test_html_collection_uses_configured_css_selectors(): void
    {
        Http::fake([
            'https://example.com/news' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body>
    <article class="news-item">
      <time class="date">2026-06-01</time>
      <a class="title" href="/news/alpha?utm_campaign=x#body">採用情報を公開しました</a>
      <p class="summary">新卒採用ページを更新しました。</p>
    </article>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => '採用',
            'source_url' => 'https://example.com/news',
            'source_type' => 'html',
            'list_selector' => '.news-item',
            'title_selector' => '.title',
            'url_selector' => '.title',
            'date_selector' => '.date',
            'body_selector' => '.summary',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);

        $this->assertDatabaseHas('collected_items', [
            'title' => '採用情報を公開しました',
            'url' => 'https://example.com/news/alpha',
            'category' => '採用',
            'summary' => '新卒採用ページを更新しました。',
        ]);
    }

    public function test_html_collection_can_read_previous_definition_date(): void
    {
        Storage::fake('local');

        Http::fake([
            'https://example.com/news/' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body>
    <div id="news" class="news_list">
      <dl>
        <dt>2026年05月20日</dt>
        <dd><a href="documents/20260520.pdf">dt dd型のお知らせ</a></dd>
      </dl>
    </div>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
            'https://example.com/news/documents/20260520.pdf' => Http::response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/news/',
            'source_type' => 'html',
            'list_selector' => '#news dl dd',
            'title_selector' => 'a',
            'url_selector' => 'a',
            'date_selector' => 'previous:dt',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);

        $this->assertDatabaseHas('collected_items', [
            'title' => 'dt dd型のお知らせ',
            'url' => 'https://example.com/news/documents/20260520.pdf',
            'published_at' => '2026-05-20 00:00:00',
        ]);
    }

    public function test_html_collection_can_read_js_news_list_array(): void
    {
        Http::fake([
            'https://example.com/etc/list.html' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body>
    <div id="DivContentsList">
      <input type="hidden" name="target" value="1_10">
    </div>
    <script src="../js/news_list.js"></script>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
            'https://example.com/js/news_list.js' => Http::response(<<<'JS'
NewsListArray[1] = new Array;
NewsListArray[1][10] = [
    ['2026/05/07','配列形式のお知らせ','./2026050110082185.html','_self','10']
];
NewsListArray[1][20] = [
    ['2026/04/01','対象外のお知らせ','./outside.html','_self','20']
];
JS, 200, ['Content-Type' => 'application/javascript']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'お知らせ一覧',
            'source_url' => 'https://example.com/etc/list.html',
            'source_type' => 'html',
            'list_selector' => 'js-news-list',
            'title_selector' => 'title',
            'url_selector' => 'url',
            'date_selector' => 'date',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);

        $this->assertDatabaseHas('collected_items', [
            'title' => '配列形式のお知らせ',
            'url' => 'https://example.com/etc/2026050110082185.html',
            'published_at' => '2026-05-07 00:00:00',
        ]);
        $this->assertDatabaseMissing('collected_items', [
            'title' => '対象外のお知らせ',
        ]);
    }

    public function test_html_collection_can_read_json_news_list(): void
    {
        Storage::fake('local');

        Http::fake([
            'https://example.com/sample/news/' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body data-webroot="/sample/">
    <ul class="_cmn-newslist" data-listpage="1"></ul>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
            'https://example.com/sample/assets/data/news/list.json' => Http::response(<<<'JSON'
{
  "list": [
    {
      "id": 10,
      "pubdate": "2026年06月01日",
      "title": "通常詳細のお知らせ",
      "type": "1",
      "listpages": [1],
      "cats": {"2": {"title": "ニュース", "color": "#98befb"}},
      "tags": []
    },
    {
      "id": 11,
      "pubdate": "2026年05月29日",
      "title": "リンク型のお知らせ",
      "type": "2",
      "link_url": "/sample/topics/link/",
      "listpages": [1],
      "cats": {"2": {"title": "ニュース", "color": "#98befb"}},
      "tags": []
    },
    {
      "id": 12,
      "pubdate": "2026年05月28日",
      "title": "PDF型のお知らせ",
      "type": "3",
      "file": "/sample/_upload/news/file/notice.pdf",
      "listpages": [1],
      "cats": {"2": {"title": "ニュース", "color": "#98befb"}},
      "tags": []
    },
    {
      "id": 13,
      "pubdate": "2026年05月27日",
      "title": "対象外ページのお知らせ",
      "type": "1",
      "listpages": [2],
      "cats": {"2": {"title": "ニュース", "color": "#98befb"}},
      "tags": []
    }
  ]
}
JSON, 200, ['Content-Type' => 'application/json']),
            'https://example.com/sample/_upload/news/file/notice.pdf' => Http::response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'お知らせ',
            'source_url' => 'https://example.com/sample/news/',
            'source_type' => 'html',
            'list_selector' => 'json-news-list',
            'title_selector' => 'title',
            'url_selector' => 'url',
            'date_selector' => 'date',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);

        $this->assertDatabaseHas('collected_items', [
            'title' => '通常詳細のお知らせ',
            'url' => 'https://example.com/sample/news/detail/10/',
            'published_at' => '2026-06-01 00:00:00',
        ]);
        $this->assertDatabaseHas('collected_items', [
            'title' => 'リンク型のお知らせ',
            'url' => 'https://example.com/sample/topics/link/',
        ]);
        $this->assertDatabaseHas('collected_items', [
            'title' => 'PDF型のお知らせ',
            'url' => 'https://example.com/sample/_upload/news/file/notice.pdf',
        ]);
        $this->assertDatabaseMissing('collected_items', [
            'title' => '対象外ページのお知らせ',
        ]);
    }

    public function test_pdf_links_are_saved_and_downloadable_from_item_detail(): void
    {
        $storage = Storage::fake('local');

        Http::fake([
            'https://example.com/news' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body>
    <article class="news-item">
      <time class="date">2026年6月1日</time>
      <a class="title" href="/docs/notice.pdf">PDFのお知らせ</a>
    </article>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
            'https://example.com/docs/notice.pdf' => Http::response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'PDF',
            'source_url' => 'https://example.com/news',
            'source_type' => 'html',
            'list_selector' => '.news-item',
            'title_selector' => '.title',
            'url_selector' => '.title',
            'date_selector' => '.date',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);

        $item = CollectedItem::query()->where('title', 'PDFのお知らせ')->firstOrFail();

        $this->assertNotNull($item->pdf_storage_path);
        $this->assertTrue(Storage::disk('local')->exists((string) $item->pdf_storage_path));

        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('items.pdf', $item))
            ->assertOk()
            ->assertDownload('notice.pdf');
    }
}
