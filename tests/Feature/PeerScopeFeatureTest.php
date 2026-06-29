<?php

namespace Tests\Feature;

use App\Models\CollectedItem;
use App\Models\CollectionRun;
use App\Models\Company;
use App\Models\User;
use App\Models\UserImportSetting;
use App\Models\WatchSource;
use App\Services\Ai\ArticleTextExtractor;
use App\Services\Collection\NewsCollectorService;
use App\Support\CollectionProcessTerminator;
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

    public function test_admin_can_download_users_as_csv(): void
    {
        $systemAdmin = User::factory()->create([
            'name' => 'System Admin',
            'login_id' => 'admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        User::factory()->create([
            'name' => 'General User',
            'login_id' => 'general-user',
            'email' => 'general@example.com',
            'password' => 'secret-password',
            'role' => 'user',
        ]);

        $response = $this->actingAs($systemAdmin)->get(route('users.download'));

        $response->assertOk();
        $response->assertDownload();

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('ログインID', $csv);
        $this->assertStringContainsString('admin', $csv);
        $this->assertStringContainsString('System Admin', $csv);
        $this->assertStringContainsString('general-user', $csv);
        $this->assertStringContainsString('General User', $csv);
        $this->assertStringContainsString('管理者', $csv);
        $this->assertStringContainsString('一般ユーザー', $csv);
        $this->assertStringNotContainsString('secret-password', $csv);
        $this->assertStringNotContainsString('パスワード', $csv);
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

    public function test_user_can_generate_ai_summary_for_item(): void
    {
        config([
            'services.ollama.base_url' => 'http://ollama.test',
            'services.ollama.model' => 'gemma4:e2b',
            'services.ollama.extracted_text_chars' => 2000,
        ]);
        Http::fake([
            'https://example.com/news/ai-summary' => Http::response(<<<'HTML'
                <html>
                    <body>
                        <header>共通ナビ</header>
                        <main>
                            <h1>サービス変更のお知らせ</h1>
                            <p>2026年7月1日から利用者向けサービスの受付時間を平日9時から17時までに変更します。</p>
                            <p>対象者はサービスを利用する契約者で、変更前に手続きの締切日と利用可能時間を確認する必要があります。</p>
                            <p>問い合わせ先はサポート窓口です。</p>
                        </main>
                    </body>
                </html>
            HTML, 200, ['Content-Type' => 'text/html']),
            'http://ollama.test/api/generate' => Http::response([
                'response' => "はい、承知いたしました。この文章を要約します。\n\n概要: 利用者向けサービスの受付時間変更を知らせる記事です。\n主な内容: AI検索専用語を含む受付時間が平日9時から17時までに変わります。\n対象・日付: 対象は契約者で、開始日は2026年7月1日です。\n確認事項: 手続きの締切日と利用可能時間を事前に確認する必要があります。",
            ], 200),
        ]);

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
            'title' => 'サービス変更のお知らせ',
            'url' => 'https://example.com/news/ai-summary',
            'url_hash' => hash('sha256', 'https://example.com/news/ai-summary'),
            'content_hash' => hash('sha256', 'サービス変更のお知らせ'),
            'detected_at' => now(),
            'summary' => 'サービス変更について',
            'body_text' => '2026年7月1日から利用者向けサービスの受付時間を変更します。対象者は事前に確認してください。',
        ]);

        $returnTo = route('items.index', ['company_id' => $company->id]);

        $response = $this->actingAs($user)->post(route('items.ai-summary', $item), [
            'return_to' => $returnTo,
        ]);

        $response->assertRedirect(route('items.show', ['item' => $item, 'return_to' => $returnTo]));
        $response->assertSessionHas('status', 'AI要約を生成しました。');

        $item->refresh();

        $this->assertSame("概要: 利用者向けサービスの受付時間変更を知らせる記事です。\n主な内容: AI検索専用語を含む受付時間が平日9時から17時までに変わります。\n対象・日付: 対象は契約者で、開始日は2026年7月1日です。\n確認事項: 手続きの締切日と利用可能時間を事前に確認する必要があります。", $item->ai_summary);
        $this->assertSame('gemma4:e2b', $item->ai_summary_model);
        $this->assertNotNull($item->ai_summary_generated_at);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://ollama.test/api/generate'
            && $request['model'] === 'gemma4:e2b'
            && $request['stream'] === false
            && $request['think'] === false
            && $request['options']['num_predict'] === 420
            && str_contains($request['prompt'], 'サービス変更のお知らせ')
            && str_contains($request['prompt'], '平日9時から17時')
            && str_contains($request['prompt'], '主な内容'));

        $this->actingAs($user)
            ->get(route('items.show', ['item' => $item, 'return_to' => $returnTo]))
            ->assertOk()
            ->assertSee('href="'.$returnTo.'"', false)
            ->assertSee('name="return_to" value="'.$returnTo.'"', false)
            ->assertSee('data-ai-summary-form', false)
            ->assertSee('AI要約を生成しています。完了までこの画面のままお待ちください。')
            ->assertSee('受付時間が平日9時から17時までに変わります。')
            ->assertSee('AI要約を再生成');

        $this->actingAs($user)
            ->get(route('items.index', ['q' => 'AI検索専用語']))
            ->assertOk()
            ->assertSee('サービス変更のお知らせ');
    }

    public function test_ai_summary_generation_failure_keeps_original_summary(): void
    {
        config([
            'services.ollama.base_url' => 'http://ollama.test',
            'services.ollama.model' => 'gemma4:e2b',
        ]);
        Http::fake([
            'https://example.com/news/ai-summary-failed' => Http::response('', 404),
            'http://ollama.test/api/generate' => Http::response(['error' => 'model error'], 500),
        ]);

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
            'title' => '要約失敗のお知らせ',
            'url' => 'https://example.com/news/ai-summary-failed',
            'url_hash' => hash('sha256', 'https://example.com/news/ai-summary-failed'),
            'content_hash' => hash('sha256', '要約失敗のお知らせ'),
            'detected_at' => now(),
            'summary' => '元の要約',
        ]);

        $response = $this->actingAs($user)->from(route('items.show', $item))->post(route('items.ai-summary', $item));

        $response->assertRedirect(route('items.show', $item));
        $response->assertSessionHasErrors('ai_summary');

        $this->assertNull($item->fresh()->ai_summary);
        $this->actingAs($user)
            ->get(route('items.show', $item))
            ->assertOk()
            ->assertSee('元の要約');
    }

    public function test_ai_summary_rejects_too_short_model_response(): void
    {
        config([
            'services.ollama.base_url' => 'http://ollama.test',
            'services.ollama.model' => 'gemma4:e2b',
        ]);
        Http::fake([
            'https://example.com/news/ai-summary-too-short' => Http::response('', 404),
            'http://ollama.test/api/generate' => Http::response(['response' => 'The'], 200),
        ]);

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
            'title' => '短すぎるAI応答のお知らせ',
            'url' => 'https://example.com/news/ai-summary-too-short',
            'url_hash' => hash('sha256', 'https://example.com/news/ai-summary-too-short'),
            'content_hash' => hash('sha256', '短すぎるAI応答のお知らせ'),
            'detected_at' => now(),
            'summary' => '2026年7月1日から利用者向けサービスの受付時間を変更します。対象者は契約者で、手続きの締切日と利用可能時間を確認する必要があります。',
        ]);

        $response = $this->actingAs($user)
            ->from(route('items.show', $item))
            ->post(route('items.ai-summary', $item));

        $response->assertRedirect(route('items.show', $item));
        $response->assertSessionHasErrors('ai_summary');

        $this->assertNull($item->fresh()->ai_summary);
    }

    public function test_ai_summary_text_extractor_reads_pdf_to_unicode_cmap(): void
    {
        Storage::disk('local')->put('pdfs/test/cmap.pdf', <<<'PDF'
%PDF-1.4
1 0 obj
<< /Resources << /Font << /F0 2 0 R >> >> /Contents 3 0 R >>
endobj
2 0 obj
<< /Type /Font /Subtype /Type0 /BaseFont /TestFont /Encoding /Identity-H /ToUnicode 4 0 R >>
endobj
3 0 obj
<< /Length 120 >>
stream
BT
/F0 12 Tf
<0001000200030004000100020003000400010002000300040001000200030004000100020003000400010002000300040001000200030004000100020003> Tj
ET
endstream
endobj
4 0 obj
/CIDInit /ProcSet findresource begin 12 dict begin begincmap
1 begincodespacerange
<0001> <0004>
endcodespacerange
4 beginbfchar
<0001> <65E5>
<0002> <672C>
<0003> <8A9E>
<0004> <0020>
endbfchar
endcmap CMapName currentdict /CMap defineresource pop end end
endobj
%%EOF
PDF);

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
            'title' => 'PDF文字マップのお知らせ',
            'url' => 'https://example.com/docs/cmap.pdf',
            'url_hash' => hash('sha256', 'https://example.com/docs/cmap.pdf'),
            'content_hash' => hash('sha256', 'PDF文字マップのお知らせ'),
            'detected_at' => now(),
            'pdf_storage_path' => 'pdfs/test/cmap.pdf',
            'pdf_original_filename' => 'cmap.pdf',
            'pdf_mime_type' => 'application/pdf',
            'pdf_file_size' => Storage::disk('local')->size('pdfs/test/cmap.pdf'),
            'pdf_downloaded_at' => now(),
        ]);

        $extracted = app(ArticleTextExtractor::class)->extract($item);

        $this->assertStringContainsString('日本語 日本語 日本語', (string) $extracted['pdf']);
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

    public function test_admin_can_register_auto_source_with_weekly_schedule(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);

        $this->actingAs($admin)->post(route('watch-sources.store'), [
            'company_id' => $company->id,
            'source_name' => '自動収集',
            'source_url' => 'https://example.com/news/',
            'source_type' => 'auto',
            'schedule_type' => 'weekly',
            'schedule_time' => '13:15',
            'schedule_weekdays' => ['1', '5'],
            'auto_ai_summary' => '1',
            'is_active' => '1',
        ])->assertRedirect(route('watch-sources.index'));

        $source = WatchSource::query()->where('source_name', '自動収集')->firstOrFail();

        $this->assertSame('auto', $source->source_type);
        $this->assertSame('weekly', $source->schedule_type);
        $this->assertSame('13:15', $source->schedule_time);
        $this->assertSame([1, 5], $source->schedule_weekdays);
        $this->assertTrue($source->auto_ai_summary);
        $this->assertNull($source->list_selector);

        $this->actingAs($admin)
            ->get(route('watch-sources.index'))
            ->assertOk()
            ->assertSee('AI要約')
            ->assertSee('自動');
    }

    public function test_collection_job_can_generate_ai_summary_automatically(): void
    {
        config([
            'services.ollama.base_url' => 'http://ollama.test',
            'services.ollama.model' => 'gemma4:e2b',
        ]);
        Http::fake([
            'https://example.com/rss.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>自動AI要約のお知らせ</title>
      <link>https://example.com/ai/1</link>
      <pubDate>Mon, 01 Jun 2026 09:00:00 +0900</pubDate>
      <description>2026年7月1日から利用者向けサービスの受付時間を変更します。対象者は契約者です。</description>
    </item>
  </channel>
</rss>
XML, 200, ['Content-Type' => 'application/rss+xml']),
            'https://example.com/ai/1' => Http::response(<<<'HTML'
<html>
  <body>
    <main>
      <p>2026年7月1日から利用者向けサービスの受付時間を平日9時から17時までに変更します。</p>
      <p>対象者は契約者で、手続きの締切日と利用可能時間を事前に確認する必要があります。</p>
    </main>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
            'http://ollama.test/api/generate' => Http::response([
                'response' => "概要: 利用者向けサービスの受付時間変更を知らせる記事です。\n主な内容: 受付時間が平日9時から17時までに変わります。\n対象・日付: 対象は契約者で、開始日は2026年7月1日です。\n確認事項: 手続きの締切日と利用可能時間を確認する必要があります。",
            ], 200),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
            'source_type' => 'rss',
            'crawl_interval_minutes' => 60,
            'auto_ai_summary' => true,
            'is_active' => true,
        ]);

        $run = app(NewsCollectorService::class)->collectSource($source);
        $item = CollectedItem::query()->where('title', '自動AI要約のお知らせ')->firstOrFail();

        $this->assertSame('success', $run->status);
        $this->assertStringContainsString('AI要約 1件', (string) $run->message);
        $this->assertSame("概要: 利用者向けサービスの受付時間変更を知らせる記事です。\n主な内容: 受付時間が平日9時から17時までに変わります。\n対象・日付: 対象は契約者で、開始日は2026年7月1日です。\n確認事項: 手続きの締切日と利用可能時間を確認する必要があります。", $item->ai_summary);
        $this->assertSame('gemma4:e2b', $item->ai_summary_model);
        $this->assertNotNull($item->ai_summary_generated_at);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://ollama.test/api/generate'
            && str_contains($request['prompt'], '平日9時から17時'));
    }

    public function test_collection_job_does_not_generate_ai_summary_when_disabled(): void
    {
        config([
            'services.ollama.base_url' => 'http://ollama.test',
            'services.ollama.model' => 'gemma4:e2b',
        ]);
        Http::fake([
            'https://example.com/rss.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>AI要約しないお知らせ</title>
      <link>https://example.com/ai-disabled/1</link>
      <pubDate>Mon, 01 Jun 2026 09:00:00 +0900</pubDate>
      <description>2026年7月1日から利用者向けサービスの受付時間を変更します。対象者は契約者です。</description>
    </item>
  </channel>
</rss>
XML, 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
            'source_type' => 'rss',
            'crawl_interval_minutes' => 60,
            'auto_ai_summary' => false,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);
        $item = CollectedItem::query()->where('title', 'AI要約しないお知らせ')->firstOrFail();

        $this->assertNull($item->ai_summary);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'http://ollama.test/api/generate');
    }

    public function test_calendar_schedule_runs_once_after_scheduled_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 08:59:00'));

        try {
            Http::fake([
                'https://example.com/rss.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>指定時刻のお知らせ</title>
      <link>https://example.com/scheduled/1</link>
      <pubDate>Mon, 08 Jun 2026 09:00:00 +0900</pubDate>
    </item>
  </channel>
</rss>
XML, 200, ['Content-Type' => 'application/rss+xml']),
            ]);

            $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
            WatchSource::create([
                'company_id' => $company->id,
                'source_name' => '時刻指定',
                'source_url' => 'https://example.com/rss.xml',
                'source_type' => 'rss',
                'crawl_interval_minutes' => 60,
                'schedule_type' => 'daily',
                'schedule_time' => '09:00',
                'is_active' => true,
            ]);

            $beforeRun = app(NewsCollectorService::class)->collectDue();

            $this->assertNull($beforeRun);
            $this->assertDatabaseCount('collection_runs', 0);
            $this->assertDatabaseMissing('collected_items', [
                'title' => '指定時刻のお知らせ',
            ]);

            Carbon::setTestNow(Carbon::parse('2026-06-08 09:00:00'));

            $dueRun = app(NewsCollectorService::class)->collectDue();

            $this->assertSame(1, $dueRun->target_count);
            $this->assertDatabaseHas('collection_run_sources', [
                'collection_run_id' => $dueRun->id,
                'source_name' => '時刻指定',
                'source_url' => 'https://example.com/rss.xml',
            ]);
            $this->assertDatabaseHas('collected_items', [
                'title' => '指定時刻のお知らせ',
            ]);

            Carbon::setTestNow(Carbon::parse('2026-06-08 09:01:00'));

            $secondRun = app(NewsCollectorService::class)->collectDue();

            $this->assertNull($secondRun);
            $this->assertDatabaseCount('collection_runs', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_auto_collection_uses_discovered_feed(): void
    {
        Http::fake([
            'https://example.com/news/' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <head>
    <link rel="alternate" type="application/rss+xml" href="/feed.xml">
  </head>
  <body></body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
            'https://example.com/feed.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>フィード自動判定のお知らせ</title>
      <link>https://example.com/feed/1</link>
      <pubDate>Mon, 01 Jun 2026 09:00:00 +0900</pubDate>
    </item>
  </channel>
</rss>
XML, 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => '自動判定',
            'source_url' => 'https://example.com/news/',
            'source_type' => 'auto',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);

        $this->assertDatabaseHas('collected_items', [
            'title' => 'フィード自動判定のお知らせ',
            'url' => 'https://example.com/feed/1',
        ]);
    }

    public function test_auto_collection_reads_generic_news_list(): void
    {
        Http::fake([
            'https://example.com/news/' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body>
    <ul class="news-list">
      <li>
        <time>2026.06.01</time>
        <a href="/news/auto-1.pdf">自動判定のお知らせ</a>
      </li>
    </ul>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/news/',
            'source_type' => 'auto',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);

        $this->assertDatabaseHas('collected_items', [
            'title' => '自動判定のお知らせ',
            'url' => 'https://example.com/news/auto-1.pdf',
            'published_at' => '2026-06-01 00:00:00',
        ]);
    }

    public function test_auto_collection_reads_same_origin_iframe_news_list(): void
    {
        Http::fake([
            'https://example.com/news/' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body>
    <iframe src="/embedded/history.html"></iframe>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
            'https://example.com/embedded/history.html' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body>
    <div class="news_block">
      <dl>
        <dt><strong>2026年06月10日</strong></dt>
        <dd><a href="/docs/notice.pdf">埋め込み一覧のお知らせ</a></dd>
      </dl>
    </div>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/news/',
            'source_type' => 'auto',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);

        $this->assertDatabaseHas('collected_items', [
            'title' => '埋め込み一覧のお知らせ',
            'url' => 'https://example.com/docs/notice.pdf',
            'published_at' => '2026-06-10 00:00:00',
        ]);
    }

    public function test_auto_collection_reads_hidden_target_js_news_list(): void
    {
        Http::fake([
            'https://example.com/etc/list.html' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body>
    <div id="DivContentsList">
      <input type="hidden" name="target" value="1_10">
    </div>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
            'https://example.com/js/news_list.js' => Http::response(<<<'JS'
NewsListArray[1] = new Array;
NewsListArray[1][10] = [
    ['2026/05/07','対象のお知らせ','./2026050110082185.html','_self','10']
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
            'source_type' => 'auto',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        app(NewsCollectorService::class)->collectSource($source);

        $this->assertDatabaseHas('collected_items', [
            'title' => '対象のお知らせ',
            'url' => 'https://example.com/etc/2026050110082185.html',
            'published_at' => '2026-05-07 00:00:00',
        ]);
        $this->assertDatabaseMissing('collected_items', [
            'title' => '対象外のお知らせ',
        ]);
    }

    public function test_auto_collection_uses_row_date_and_keeps_same_url_rows(): void
    {
        Http::fake([
            'https://example.com/news/' => Http::response(<<<'HTML'
<!doctype html>
<html>
  <body>
    <div id="news" class="news_list">
      <dl>
        <dt>2025年10月27日</dt>
        <dd>
          <a href="/shared/">募集を2025年11月4日より開始します。</a>
          <a href="/files/flyer.pdf">チラシPDF</a>
        </dd>
        <dt>2024年07月13日</dt>
        <dd><a href="/shared/">同じURLの別行のお知らせ</a></dd>
      </dl>
    </div>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/news/',
            'source_type' => 'auto',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);

        $result = app(NewsCollectorService::class)->testSource($source, 10);

        $this->assertSame(2, $result['count']);
        $this->assertSame('募集を2025年11月4日より開始します。', $result['items'][0]['title']);
        $this->assertSame('2025-10-27', $result['items'][0]['published_at']->format('Y-m-d'));
        $this->assertSame('同じURLの別行のお知らせ', $result['items'][1]['title']);
        $this->assertSame('2024-07-13', $result['items'][1]['published_at']->format('Y-m-d'));
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
        $this->assertDatabaseHas('collection_run_sources', [
            'collection_run_id' => $run->id,
            'watch_source_id' => $source->id,
            'company_name' => 'Peer Co',
            'source_name' => 'ニュース',
        ]);
        $this->assertDatabaseHas('collected_items', [
            'title' => '手実行のお知らせ',
            'url' => 'https://example.com/manual/1',
        ]);

        $this->actingAs($admin)
            ->get(route('collection-runs.index'))
            ->assertOk()
            ->assertSee('Peer Co / ニュース');
    }

    public function test_manual_collection_redirects_to_existing_running_run(): void
    {
        Http::fake();

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
        $runningRun = CollectionRun::create([
            'started_at' => now(),
            'status' => 'running',
            'target_count' => 1,
            'message' => '実行中です。',
        ]);
        $runningRun->targetSources()->create([
            'watch_source_id' => $source->id,
            'company_name' => 'Peer Co',
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
        ]);

        $response = $this->actingAs($admin)->post(route('watch-sources.collect', $source));

        $response->assertRedirect(route('collection-runs.show', $runningRun));
        $response->assertSessionHas('status', 'この収集先はすでに実行中です。実行中の収集ログを表示します。');
        $this->assertDatabaseCount('collection_runs', 1);
        Http::assertNothingSent();
    }

    public function test_due_collection_skips_source_already_running(): void
    {
        Http::fake();

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
            'source_type' => 'rss',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);
        $runningRun = CollectionRun::create([
            'started_at' => now(),
            'status' => 'running',
            'target_count' => 1,
            'message' => '実行中です。',
        ]);
        $runningRun->targetSources()->create([
            'watch_source_id' => $source->id,
            'company_name' => 'Peer Co',
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
        ]);

        $run = app(NewsCollectorService::class)->collectDue();

        $this->assertNull($run);
        $this->assertDatabaseCount('collection_runs', 1);
        Http::assertNothingSent();
    }

    public function test_background_collection_command_skips_source_already_running(): void
    {
        Http::fake();

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
            'source_type' => 'rss',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);
        $runningRun = CollectionRun::create([
            'started_at' => now(),
            'status' => 'running',
            'target_count' => 1,
            'message' => '実行中です。',
        ]);
        $runningRun->targetSources()->create([
            'watch_source_id' => $source->id,
            'company_name' => 'Peer Co',
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
        ]);
        $pendingRun = CollectionRun::create([
            'started_at' => now(),
            'status' => 'running',
            'target_count' => 1,
            'message' => '起動待ちです。',
        ]);

        $exitCode = Artisan::call('peerscope:run-collection', [
            'run' => $pendingRun->id,
            '--source' => [$source->id],
        ]);

        $pendingRun->refresh();

        $this->assertSame(0, $exitCode);
        $this->assertSame('warning', $pendingRun->status);
        $this->assertStringContainsString('#'.$runningRun->id, $pendingRun->message);
        Http::assertNothingSent();
    }

    public function test_admin_can_cancel_running_collection_run(): void
    {
        $this->mock(CollectionProcessTerminator::class, function ($mock): void {
            $mock->shouldReceive('terminate')->once()->andReturn(true);
        });

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
        $run = CollectionRun::create([
            'started_at' => now(),
            'status' => 'running',
            'target_count' => 1,
            'message' => '実行中です。',
        ]);
        $run->targetSources()->create([
            'watch_source_id' => $source->id,
            'company_name' => 'Peer Co',
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
        ]);

        $response = $this->actingAs($admin)->post(route('collection-runs.cancel', $run));

        $response->assertRedirect(route('collection-runs.show', $run));
        $response->assertSessionHas('status', '収集ログを中断しました。');

        $run->refresh();

        $this->assertSame('cancelled', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('中断しました。実行中の収集プロセスを停止しました。', $run->message);
        $this->assertNotNull($source->fresh()->last_crawled_at);
    }

    public function test_cancelled_collection_run_stops_before_fetching_source(): void
    {
        Http::fake();

        $company = Company::create(['name' => 'Peer Co', 'is_active' => true]);
        $source = WatchSource::create([
            'company_id' => $company->id,
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
            'source_type' => 'rss',
            'crawl_interval_minutes' => 60,
            'is_active' => true,
        ]);
        $run = CollectionRun::create([
            'started_at' => now(),
            'status' => 'cancelled',
            'target_count' => 1,
            'message' => '中断要求を受け付けました。',
        ]);

        $finishedRun = app(NewsCollectorService::class)->collectRun($run, collect([$source->load('company')]));

        $this->assertSame('cancelled', $finishedRun->status);
        $this->assertNotNull($finishedRun->finished_at);
        $this->assertStringContainsString('中断しました。', $finishedRun->message);
        $this->assertNotNull($source->fresh()->last_crawled_at);
        Http::assertNothingSent();
    }

    public function test_admin_can_download_collection_runs_as_csv(): void
    {
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

        $run = CollectionRun::create([
            'started_at' => Carbon::parse('2026-06-01 09:00:00'),
            'finished_at' => Carbon::parse('2026-06-01 09:01:00'),
            'status' => 'success',
            'target_count' => 1,
            'created_count' => 2,
            'updated_count' => 3,
            'error_count' => 0,
            'message' => 'CSV出力対象',
        ]);
        $run->targetSources()->create([
            'watch_source_id' => $source->id,
            'company_name' => 'Peer Co',
            'source_name' => 'ニュース',
            'source_url' => 'https://example.com/rss.xml',
        ]);

        CollectionRun::create([
            'started_at' => Carbon::parse('2026-06-01 09:05:00'),
            'finished_at' => Carbon::parse('2026-06-01 09:05:00'),
            'status' => 'success',
            'target_count' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'error_count' => 0,
            'message' => '対象なしログ',
        ]);

        $response = $this->actingAs($admin)->get(route('collection-runs.download'));

        $response->assertOk();
        $response->assertDownload();

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('ログID', $csv);
        $this->assertStringContainsString('Peer Co / ニュース', $csv);
        $this->assertStringContainsString('成功', $csv);
        $this->assertStringContainsString('CSV出力対象', $csv);
        $this->assertStringNotContainsString('対象なしログ', $csv);
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
