<?php

namespace Tests\Feature;

use App\Models\CollectedItem;
use App\Models\CollectionRun;
use App\Models\Company;
use App\Models\User;
use App\Models\WatchSource;
use App\Services\Collection\NewsCollectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'role' => 'user',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'name' => 'Staff User',
            'login_id' => 'staff-01',
            'email' => 'staff@example.com',
            'role' => 'user',
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
    ['2026/05/07','蒲郡のお知らせ','./2026050110082185.html','_self','10']
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
            'title' => '蒲郡のお知らせ',
            'url' => 'https://example.com/etc/2026050110082185.html',
            'published_at' => '2026-05-07 00:00:00',
        ]);
        $this->assertDatabaseMissing('collected_items', [
            'title' => '対象外のお知らせ',
        ]);
    }

    public function test_pdf_links_are_saved_and_downloadable_from_item_detail(): void
    {
        Storage::fake('local');

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
        Storage::disk('local')->assertExists($item->pdf_storage_path);

        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('items.pdf', $item))
            ->assertOk()
            ->assertDownload('notice.pdf');
    }
}
