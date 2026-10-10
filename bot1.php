<?php
// ════════════════════════════════════════════════════════════════════════════
// TASJIL BOT — Facebook Messenger + Telegram Admin Bot
// ════════════════════════════════════════════════════════════════════════════

if (!isset($input)) {
    $input = json_decode(file_get_contents("php://input"), true);
}
if (!isset($event)) {
    $event = $input['entry'][0]['messaging'][0] ?? [];
}
$sender_id = $event['sender']['id'] ?? null;
$message   = $event['message']['text'] ?? '';

// ════════ Facebook Config ════════
define('FB_TOKEN',        'EAAFYLlWaXQkBSOhl9K4ZBkD5StOSm7DnlasNSbG14xViuqgIULzhhU2HkZA1jpj1LXH02jAYUc2eDqCZCcO65YDlFv0QFesE4EILjt98Ry8R4MLO6BkWcSQjZCSUH57ZAB6DId6lzd0PotL152kII3G4MNCjNT8Cq7ZAhWSkuGGA4gfJzoMeZA4tZC24tNPsJynTo4CP8ZAJhZBwZDZD');
define('VERIFY_TOKEN',    'Yacin');

// ════════ Telegram Config ════════
define('TG_TOKEN',   '8723811941:AAGi5C0AwV-G45PAoou2rYOZJovI5AmhdJM');
define('TG_ADMIN_ID', '8499896271');
define('TG_API',     'https://api.telegram.org/bot' . TG_TOKEN);

// ════════ Paths ════════
define('PROXY_LIST_FILE',  '/tmp/proxies.json');
define('PROXY_API_URL',    'https://dev-bendjarayacine.pantheonsite.io/wp-admin/maint/proxy.json');
define('SESSIONS_DIR',     '/tmp/fb_sessions');
define('USERS_DIR',        '/tmp/fb_users');
define('PHONE_MAP_FILE',   '/tmp/fb_phone_map.json');
define('PENDING_DIR',      '/tmp/fb_pending');
define('DB_FILE',          '/tmp/fb_dedup.sqlite');
define('NEW_USERS_FILE',   '/tmp/fb_new_users.json');
define('RATE_LIMIT_DIR',   '/tmp/fb_rate_limit');
define('TG_STATE_DIR',     '/tmp/tg_states');
define('MATCH_GIFT_FILE',  '/tmp/match_gift_config.json');
define('BROADCAST_LOG',    '/tmp/broadcast_log.json');

define('RATE_LIMIT_SECONDS', 600);

// ════════ Client Credentials ════════
define('CLIENT_ID_OLD',     '87pIExRhxBb3_wGsA5eSEfyATloa');
define('CLIENT_SECRET_OLD', 'uf82p68Bgisp8Yg1Uz8Pf6_v1XYa');
define('CLIENT_ID_NEW',     '6E6CwTkp8H1CyQxraPmcEJPQ7xka');
define('CLIENT_SECRET_NEW', 'MVpXHW_ImuMsxKIwrJpoVVMHjRsa');

// ════════ Ooredoo Config ════════
define('OOREDOO_BASE',         'https://apis.ooredoo.dz');
define('OOREDOO_BFF',          'https://apis.ooredoo.dz/api/ooredoo-bff');
define('OOREDOO_AUTH',         'https://apis.ooredoo.dz/api/auth/realms/myooredoo/protocol/openid-connect/token');
define('OOREDOO_CLIENT_ID',    'myooredoo-app');
define('OOREDOO_X_VERSION',    '1.5.15');
define('OOREDOO_X_SIG',        'f320f896f3da2a5a0284f9af316efb4ab0432b26406413568db116fa9dc60feb');
define('OOREDOO_PLATFORM',     'android');
define('OOREDOO_PLATFORM_ORIGIN', 'mobile-android');
// ════════ Dynamic Device Identity Generators (مثل Python: _iid, _pld, _corr) ════════
// يُنشئ instance-id فريد = uuid1 + timestamp_ms (نفس منطق Python _iid)
function ooredooNewInstanceId(): string {
    // uuid v1-style: time-based using uniqid
    $uuid = sprintf('%08x-%04x-11%s-%04x-%012s',
        crc32(uniqid('', true)) & 0xFFFFFFFF,
        mt_rand(0, 0xFFFF),
        substr(bin2hex(random_bytes(2)), 0, 4),
        (mt_rand(0, 0x3FFF) | 0x8000),
        bin2hex(random_bytes(6))
    );
    return $uuid . ooredooNowMs();
}
// يُنشئ platform-data-signature = base64(json{platform,is-physical-device,device-id})
function ooredooNewPlatSig(string $instanceId): string {
    $payload = json_encode([
        'platform'           => 'android',
        'is-physical-device' => true,
        'device-id'          => $instanceId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return base64_encode($payload);
}
// يُعيد timestamp بالميلي ثانية كـ string
function ooredooNowMs(): string {
    return (string)(int)(microtime(true) * 1000);
}
// يُنشئ correlation-id = hex(8 bytes) + timestamp_ms
function ooredooNewCorrId(): string {
    return bin2hex(random_bytes(8)) . ooredooNowMs();
}
// يُنشئ device fingerprint عشوائي 64 hex chars
function ooredooNewFingerprint(): string {
    return bin2hex(random_bytes(32));
}


// ════════ استخراج device-id من JWT payload (السيرفر يقيّد التوكن به) ════════
function ooredooExtractJwtDeviceId(string $accessToken): ?string {
    $parts = explode('.', $accessToken);
    if (count($parts) < 2) return null;
    $payload = base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4 ? strlen($parts[1]) + 4 - strlen($parts[1]) % 4 : 0, '='));
    $data = @json_decode($payload, true);
    return $data['device-id'] ?? null;
}
// بناء plat-sig من device-id المستخرج من JWT
function ooredooMakePlatSigFromJwt(string $accessToken): string {
    $did = ooredooExtractJwtDeviceId($accessToken);
    if (!$did) return ooredooNewPlatSig(ooredooNewInstanceId());
    return base64_encode(json_encode([
        'platform'           => 'android',
        'is-physical-device' => true,
        'device-id'          => $did,
    ], JSON_UNESCAPED_SLASHES));
}
// ════════ Session-scoped device identity (يُنشأ مرة واحدة لكل جلسة مصادقة) ════════
// الـ instance-id و plat-sig يجب أن يكونا ثابتَين طوال الجلسة الواحدة
// (مثل Python: OoredooSession.__init__ ينشئ iid و psig مرة واحدة)
function ooredooGetOrCreateDeviceId(string $psid): array {
    $f = OOREDOO_SESSIONS_DIR . "/{$psid}_device.json";
    if (file_exists($f)) {
        $d = json_decode(file_get_contents($f), true);
        if (is_array($d) && !empty($d['instance_id'])) return $d;
    }
    $iid    = ooredooNewInstanceId();
    $psig   = ooredooNewPlatSig($iid);
    $device = ['instance_id' => $iid, 'plat_sig' => $psig, 'created_at' => time()];
    file_put_contents($f, json_encode($device));
    return $device;
}
function ooredooClearDeviceId(string $psid): void {
    $f = OOREDOO_SESSIONS_DIR . "/{$psid}_device.json";
    if (file_exists($f)) @unlink($f);
}
// Integrity tokens للـ checkpoint
define('OOREDOO_INTEGRITY_1',  'JWBTpxXcH7KY9EXYeIRp8NjQMx2dt3vF8ejjoARazyoFzOw6Cu8+wkeV+zSVHSTeI6a12SG/FU+Q/9uBrcN71RlYO4CulD2x375NKwJRAInar8DVUilHBKUfjEsoHd6EwfLCfdYzLNXJu9POfEf6tqKn3PUe0pY6qZxWLhyVAFHw8Qwb52ZqdI2soCH4S3mrz+OZL+YovdGSUKlXKIkxVroAMTAg3jljLF7gvXaEsB/O3uW+tbkBMwSX2uTOwf+rh3sgOF/zPhKCFgRvZmU1o87Fa96Y/+wOcK+UpfXTCBYVrvQ1wG6ryC7vHMSqgUhYJK4tbSoti0zv4av9pwxh9Iv2MgsMENjyKBK/TuFZxEG3xQCKj1LTMgn2xBEeKcbsKrEW3xDRwsccGI3D7GHnbUlhdI4ujDIRQVKHaZSwhTp+32Cr4EB/Qt8p/xRbdgCde9MUlM4AYIGlfyVoHSkOvTu5c0DIIy1EhowtAkbKIJl49wsYKWvpT/nXFWzeXP3R2qzaclCnIb9qihhrarmwz8l8XH2T+f7AK2uSNiuWLG5Fy2mM/YLmuJPZT9Ciag/mpy7JGImKNQAcFbSAInBdKQjUKT+COzZfrXj5rwil9/HDMtuzIovq8hrQGY+roTfVPheTLWmcjkVCOL8VXAHjPb22vOuqrkMioWT9CQ5O8RgW7RKHrObeKQMp8F7tSjOqkxYDFkPVN1G4nkEBrTlPKK9YtPl+j7IuH9fGcDTwhCTTqP8peuBryW0L11tApvAEi7VkJyBxpVxW');
define('OOREDOO_INTEGRITY_2',  'JWBTpxXcH7KR/kzaHeUUydDWODuU4i3H4fL9gCgKzyYfyN4qLJJE8keV+zSVHSTeI6aEyC2tbSza/NGmkflQ/UgaNZSN9SKxz7tcdQckHY25n6mOaDZ9PPMQmF4sKZqDwebce/c1SNGNn6qvTULW/L348aJvy7Vii6dyPTu3OSPV9AwKwGwQKNHN61HyK3jx/fa8Os5ux9u3Q654YLZrAJ99QGEm/C9nEBPmlHC98RjovLGBnuJOGx6k7fnyrt/Ogy5fNg+tBgi5HEsoXDAwutX7TOWfx4YKHpKW//TvPRQgp69e2Xqa+CvGJJ+a/WlPMPhRCSsfo0OG04mVvlkc8eXiLzV/GPuJCEmcRf9Z6V+xoDyMtU7OZ3XT5BMhI/D/N68LsRn/9dIBH6X7n0WAW3tzeKZK1zENWFKrd7aLlzEdwFzO/1JTQsEDom5TRie0Kd5yic4BGvS1C1BsA2wJhzyiZ2j7RUE9m8tOAkPEIs929FYIfmH8XcCQKHT0TqT0+N+wCiyfNawPlDhZWcObscFZeEmKgvqiUFCoCj6xI1hvyHK0nu7fnZ/sGc6oNT/SrFjubYWjOyoYFL7gMnlYAhyjZhjuAwN1m1/wjwrOx8DbFr2NW8qb3hv+CIzK1zL3LDryKjqNkTxcOp4aJnmSTa73guCEmWc7pnLkLw5O8RgW7RKHrObeeQ0l9lu4HWXwlhdWRxvVMQDimhtTrGASL6sIuaxyhOkrH4HFImP70SaCoK9+euMxnzkPjg9HqPIFj+I0KSMloVxW');
define('OOREDOO_SESSIONS_DIR', '/tmp/ooredoo_sessions');
define('OOREDOO_LOG',          '/tmp/ooredoo.log');
define('OOREDOO_PROXY_ENABLED_FILE', '/tmp/ooredoo_proxy_enabled.json');
define('OOREDOO_PROXIES_FILE',       '/tmp/ooredoo_proxies.json');

// ════════ Ooredoo — قيم ثابتة لطلبات التوكن + Gamification ════════
// ملاحظة: FP_AUTHED, FP_TOKEN1, FP_TOKEN2, FP_GPLAY_CHK → تُنشأ ديناميكياً الآن
// لا حاجة لثوابت ثابتة — كل طلب يُنشئ fingerprint فريد عبر ooredooNewFingerprint()
// OOREDOO_CORR_AUTHED → تُنشأ ديناميكياً عبر ooredooNewCorrId()

// بروكسيات أوريدو الافتراضية (تُحدَّث من التلقرام)
define('OOREDOO_DEFAULT_PROXIES', [
    '38.154.185.97:6370:nhtbnigs:3tdfei3ngchg',
    '84.247.60.125:6095:nhtbnigs:3tdfei3ngchg',
    '31.59.20.176:6754:nhtbnigs:3tdfei3ngchg',
]);

@mkdir(OOREDOO_SESSIONS_DIR, 0777, true);

@mkdir(SESSIONS_DIR,   0777, true);
@mkdir(USERS_DIR,      0777, true);
@mkdir(PENDING_DIR,    0777, true);
@mkdir(RATE_LIMIT_DIR, 0777, true);
@mkdir(TG_STATE_DIR,   0777, true);

// ════════════════════════════════════════════════════════════════════════════
// Match Gift Config (يتحكم بها من تلقرام)
// ════════════════════════════════════════════════════════════════════════════
function getMatchGiftConfig(): array
{
    $defaults = [
        'enabled'   => false,
        'qr_code'   => 'https://www.djezzy.dz/scanwin-wd26?1',
        'gift_label'=> '12Go',
        'start_hour'=> 1,
        'end_hour'  => 5,
    ];
    if (!file_exists(MATCH_GIFT_FILE)) return $defaults;
    $d = json_decode(file_get_contents(MATCH_GIFT_FILE), true);
    return is_array($d) ? array_merge($defaults, $d) : $defaults;
}
function saveMatchGiftConfig(array $cfg): void
{
    file_put_contents(MATCH_GIFT_FILE, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ════════════════════════════════════════════════════════════════════════════
// قائمة العروض
// ════════════════════════════════════════════════════════════════════════════
define('OFFERS', [
    'BTL500MBDAY'                => ['name' => '📦 5GB - 90دج - 24h',           'display' => "الإنترنت: 5GB | السعر: 90 دج | المدة: 24 ساعة"],
    'DOVINTSPEEDDAY100MoPRE'     => ['name' => '📦 300Mo - 30دج - 24h',         'display' => "الإنترنت: 300Mo | السعر: 30 دج | المدة: 24 ساعة"],
    'DOVINTSPEEDDAY250MoPRE'     => ['name' => '📦 600Mo - 50دج - 24h',         'display' => "الإنترنت: 600Mo | السعر: 50 دج | المدة: 24 ساعة"],
    'DOVINTSPEEDDAY1GoPRE'       => ['name' => '📦 2Go - 100دج - 24h',          'display' => "الإنترنت: 2Go | السعر: 100 دج | المدة: 24 ساعة"],
    'OFFREJEUNE50'               => ['name' => '📦 1Go - 50دج - 24h',           'display' => "الإنترنت: 1Go | السعر: 50 دج | المدة: 24 ساعة"],
    'BTLINTSPEEDDAY2Go'          => ['name' => '🏷️ 4GB - 70دج - 24h',          'display' => "الإنترنت: 4GB | السعر: 70 دج | المدة: 24 ساعة"],
    'BTL4GBDAY'                  => ['name' => '📦 5GB - 190دج - 24h',          'display' => "الإنترنت: 5GB | السعر: 190 دج | المدة: 24 ساعة"],
    'BTL1GBDAY'                  => ['name' => '📦 4GB - 140دج - 24h',          'display' => "الإنترنت: 4GB | السعر: 140 دج | المدة: 24 ساعة"],
    'DOVINTSPEEDWEEK2GoPRE'      => ['name' => '📦 4Go - 150دج - 7أيام',        'display' => "الإنترنت: 4Go | السعر: 150 دج | المدة: 7 أيام"],
    'DOVINTSPEEDWEEK3GoPRE'      => ['name' => '📦 10Go - 300دج - 7أيام',       'display' => "الإنترنت: 10Go | السعر: 300 دج | المدة: 7 أيام"],
    'BTLDATA2WEEKS'              => ['name' => '📦 4GB - 400دج - 15يوم',        'display' => "الإنترنت: 4GB | السعر: 400 دج | المدة: 15 يوم"],
    '1GBFB3DAYInternet'          => ['name' => '📦 1GB(FB) - 70دج - 3أيام',     'display' => "الإنترنت: 1GB (Facebook) | السعر: 70 دج | المدة: 3 أيام"],
    'DOVINTSPEEDMONTH6GoPRE'     => ['name' => '📦 12Go - 500دج - 30يوم',       'display' => "الإنترنت: 12Go | السعر: 500 دج | المدة: 30 يوم"],
    'DOVINTSPEEDMONTH15GoPRE'    => ['name' => '📦 30Go - 1000دج - 30يوم',      'display' => "الإنترنت: 30Go | السعر: 1000 دج | المدة: 30 يوم"],
    'DOVINTSPEEDMONTH30GoPRE'    => ['name' => '📦 60Go - 1500دج - 30يوم',      'display' => "الإنترنت: 60Go | السعر: 1500 دج | المدة: 30 يوم"],
    '2GBMONTH'                   => ['name' => '📦 3GB - 250دج - 30يوم',        'display' => "الإنترنت: 3GB | السعر: 250 دج | المدة: 30 يوم"],
    'BTL500MBHOUR'               => ['name' => '⚡ 1GB - 40دج - 1ساعة',         'display' => "الإنترنت: 1GB | السعر: 40 دج | المدة: 1 ساعة"],
    'ImtiyazSurpriseData2hfbPRE' => ['name' => '📘 FB غير محدود - 50دج - 4h',  'display' => "الإنترنت: Facebook غير محدود | السعر: 50 دج | المدة: 4 ساعات"],
]);

define('OFFER_SHORTCUTS', [
    '5'  => 'BTL500MBDAY',
    '6'  => 'DOVINTSPEEDDAY100MoPRE',
    '7'  => 'DOVINTSPEEDDAY250MoPRE',
    '8'  => 'DOVINTSPEEDDAY1GoPRE',
    '9'  => 'OFFREJEUNE50',
    '10' => 'BTLINTSPEEDDAY2Go',
    '11' => 'BTL4GBDAY',
    '12' => 'BTL1GBDAY',
    '13' => 'DOVINTSPEEDWEEK2GoPRE',
    '14' => 'DOVINTSPEEDWEEK3GoPRE',
    '15' => 'BTLDATA2WEEKS',
    '16' => '1GBFB3DAYInternet',
    '17' => 'DOVINTSPEEDMONTH6GoPRE',
    '18' => 'DOVINTSPEEDMONTH15GoPRE',
    '19' => 'DOVINTSPEEDMONTH30GoPRE',
    '20' => '2GBMONTH',
    '21' => 'BTL500MBHOUR',
    '22' => 'ImtiyazSurpriseData2hfbPRE',
]);

// ════════════════════════════════════════════════════════════════════════════
// SQLite — Dedup + User Lock
// ════════════════════════════════════════════════════════════════════════════
function getDB(): PDO
{
    static $db = null;
    if ($db !== null) return $db;
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA synchronous=OFF");          // أسرع بكثير تحت الضغط
    $db->exec("PRAGMA cache_size=-8000");          // 8MB cache في الذاكرة
    $db->exec("PRAGMA temp_store=MEMORY");         // temp tables في RAM
    $db->exec("PRAGMA mmap_size=268435456");       // 256MB mmap
    $db->exec("PRAGMA busy_timeout=3000");         // 3s timeout بدل الانهيار
    $db->exec("CREATE TABLE IF NOT EXISTS processed_events (event_id TEXT PRIMARY KEY, created_at INTEGER NOT NULL)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_events_created ON processed_events(created_at)");
    $db->exec("CREATE TABLE IF NOT EXISTS user_locks (psid TEXT PRIMARY KEY, locked_at INTEGER NOT NULL)");
    // Cleanup عشوائي — 1 من كل 50 request بدل كل request
    if (mt_rand(1, 50) === 1) {
        $db->exec("DELETE FROM processed_events WHERE created_at < " . (time() - 3600));
        $db->exec("DELETE FROM user_locks WHERE locked_at < " . (time() - 600));
    }
    return $db;
}
function tryMarkEvent(string $id): bool
{
    // Fast path: APCu in-memory dedup (sub-millisecond)
    if (function_exists('apcu_add')) {
        if (!apcu_add('ev_' . $id, 1, 7200)) return false; // already exists
    }
    try {
        $s = getDB()->prepare("INSERT OR IGNORE INTO processed_events (event_id, created_at) VALUES (?,?)");
        $s->execute([$id, time()]);
        return $s->rowCount() > 0;
    } catch (Throwable $e) { return true; }
}
function unmarkEvent(string $id): void
{
    try { getDB()->prepare("DELETE FROM processed_events WHERE event_id=?")->execute([$id]); } catch (Throwable $e) {}
}
function tryLockUser(string $psid): bool
{
    try {
        $s = getDB()->prepare("INSERT OR IGNORE INTO user_locks (psid, locked_at) VALUES (?,?)");
        $s->execute([$psid, time()]);
        return $s->rowCount() > 0;
    } catch (Throwable $e) { return true; }
}
function unlockUser(string $psid): void
{
    try { getDB()->prepare("DELETE FROM user_locks WHERE psid=?")->execute([$psid]); } catch (Throwable $e) {}
}
function dbg(string $m): void
{
    file_put_contents('/tmp/fb_debug.log', date('Y-m-d H:i:s') . " $m\n", FILE_APPEND);
}

// ════════════════════════════════════════════════════════════════════════════
// Rate Limit
// ════════════════════════════════════════════════════════════════════════════
function rateLimitFile(string $psid): string { return RATE_LIMIT_DIR . "/{$psid}.json"; }
function getFinalResultTimestamps(string $psid): array
{
    $f = rateLimitFile($psid);
    if (!file_exists($f)) return [];
    $d = json_decode(@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function recordFinalResult(string $psid): void
{
    $list   = getFinalResultTimestamps($psid);
    $list[] = time();
    if (count($list) > 2) $list = array_slice($list, -2);
    @file_put_contents(rateLimitFile($psid), json_encode($list));
}
function checkRateLimit(string $psid): ?int
{
    $list = getFinalResultTimestamps($psid);
    if (count($list) < 2) return null;
    $elapsed = time() - $list[0];
    if ($elapsed < RATE_LIMIT_SECONDS) return RATE_LIMIT_SECONDS - $elapsed;
    return null;
}
function formatRemainingRateLimit(int $secondsLeft): string
{
    $minutes = (int)ceil($secondsLeft / 60);
    if ($minutes <= 1) return "أقل من دقيقة";
    return "{$minutes} دقيقة";
}
function rateLimitMessage(int $secondsLeft): string
{
    return "⏳ أنت ترسل طلبات كثيرة خلال فترة قصيرة.\n\n🔁 يرجى إعادة المحاولة بعد:\n🕐 الوقت المتبقي: " . formatRemainingRateLimit($secondsLeft) . "\n\nيتم تطبيق هذا القيد لضمان استمرارية عمل خدمة البوت بشكل جيد لجميع المستخدمين.";
}

// ════════════════════════════════════════════════════════════════════════════
// New Users Tracking
// ════════════════════════════════════════════════════════════════════════════
function isNewUser(string $psid): bool
{
    $map = file_exists(NEW_USERS_FILE) ? (json_decode(file_get_contents(NEW_USERS_FILE), true) ?? []) : [];
    return !isset($map[$psid]);
}
function markUserAsSeen(string $psid): void
{
    // APCu fast path: تجنب قراءة/كتابة الملف في كل request
    $cacheKey = 'user_seen_' . $psid;
    $isNew    = !file_exists(USERS_DIR . "/{$psid}.json");

    if (function_exists('apcu_fetch') && apcu_fetch($cacheKey)) {
        // تحديث last_active كل 5 دقائق بدل كل request
        if (!$isNew) return;
    }
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, 1, 300); // 5 دقائق cache
    }

    $lockFile = NEW_USERS_FILE . '.lock';
    $lock = fopen($lockFile, 'c');
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
        $map = file_exists(NEW_USERS_FILE) ? (json_decode(@file_get_contents(NEW_USERS_FILE), true) ?? []) : [];
        if (!isset($map[$psid])) {
            $map[$psid] = ['first_seen' => time(), 'last_active' => time()];
        } else {
            $map[$psid]['last_active'] = time();
        }
        $tmp = NEW_USERS_FILE . '.tmp';
        file_put_contents($tmp, json_encode($map));
        rename($tmp, NEW_USERS_FILE);
        flock($lock, LOCK_UN);
    }
    if ($lock) fclose($lock);
}
function getAllKnownUsers(): array
{
    if (!file_exists(NEW_USERS_FILE)) return [];
    return array_keys(json_decode(file_get_contents(NEW_USERS_FILE), true) ?? []);
}
function getActiveUsers(int $days = 7): array
{
    if (!file_exists(NEW_USERS_FILE)) return [];
    $map   = json_decode(file_get_contents(NEW_USERS_FILE), true) ?? [];
    $since = time() - ($days * 86400);
    $active = [];
    foreach ($map as $psid => $data) {
        $lastActive = is_array($data) ? ($data['last_active'] ?? 0) : $data;
        if ($lastActive >= $since) $active[] = $psid;
    }
    return $active;
}
function getUserStats(): array
{
    if (!file_exists(NEW_USERS_FILE)) return ['total' => 0, 'active_7d' => 0, 'active_30d' => 0];
    $map   = json_decode(file_get_contents(NEW_USERS_FILE), true) ?? [];
    $total = count($map);
    $now   = time();
    $a7 = 0; $a30 = 0;
    foreach ($map as $psid => $data) {
        $lastActive = is_array($data) ? ($data['last_active'] ?? 0) : $data;
        if ($now - $lastActive <= 7  * 86400) $a7++;
        if ($now - $lastActive <= 30 * 86400) $a30++;
    }
    return ['total' => $total, 'active_7d' => $a7, 'active_30d' => $a30];
}

// ════════════════════════════════════════════════════════════════════════════
// Broadcast Log — لمنع الإرسال المكرر
// ════════════════════════════════════════════════════════════════════════════
function getBroadcastLog(): array
{
    if (!file_exists(BROADCAST_LOG)) return [];
    return json_decode(file_get_contents(BROADCAST_LOG), true) ?? [];
}
function saveBroadcastLog(array $log): void
{
    file_put_contents(BROADCAST_LOG, json_encode($log, JSON_UNESCAPED_UNICODE));
}
function markBroadcastSent(string $broadcastId, string $psid): void
{
    $log = getBroadcastLog();
    if (!isset($log[$broadcastId])) $log[$broadcastId] = [];
    $log[$broadcastId][$psid] = time();
    saveBroadcastLog($log);
}
function wasBroadcastSent(string $broadcastId, string $psid): bool
{
    $log = getBroadcastLog();
    return isset($log[$broadcastId][$psid]);
}

// ════════════════════════════════════════════════════════════════════════════
// Pending Operations
// ════════════════════════════════════════════════════════════════════════════
function setPending(string $psid, string $op): void
{
    file_put_contents(PENDING_DIR . "/{$psid}.json", json_encode(['op' => $op, 'ts' => time()]));
}
function clearPending(string $psid): void
{
    $f = PENDING_DIR . "/{$psid}.json";
    if (file_exists($f)) @unlink($f);
}
function getPending(string $psid): ?string
{
    $f = PENDING_DIR . "/{$psid}.json";
    if (!file_exists($f)) return null;
    $d = json_decode(@file_get_contents($f), true);
    if (!$d) return null;
    if (time() - ($d['ts'] ?? 0) > 600) { @unlink($f); return null; }
    return $d['op'] ?? null;
}

// ════════════════════════════════════════════════════════════════════════════
// Proxy System — المحسّن (يستخدم جميع البروكسيات
// ════════════════════════════════════════════════════════════════════════════
function loadProxies(): array
{
    if (file_exists(PROXY_LIST_FILE)) {
        $d = json_decode(file_get_contents(PROXY_LIST_FILE), true);
        if (is_array($d) && count($d) > 0) return $d;
    }
    return [
    "http://gate.kookeey.info:1000:7464034-bc00eb59f5:fc54fe8a0a-DZ-32737453-1m",
    "http://gate.kookeey.info:1000:7464034-bc00eb59f5:fc54fe8a0a-DZ-56432587-1m",
    "http://gate.kookeey.info:1000:7464034-bc00eb59f5:fc54fe8a0a-DZ-27039306-1m",
    "http://gate.kookeey.info:1000:7464034-bc00eb59f5:fc54fe8a0a-DZ-81339712-1m",
    "http://gate.kookeey.info:1000:7464034-bc00eb59f5:fc54fe8a0a-DZ-15043776-1m",
    "http://mobile.kookeey.info:1086:7464034-bc00eb59f5:fc54fe8a0a-DZ-83875156-1m",
    "http://mobile.kookeey.info:1086:7464034-bc00eb59f5:fc54fe8a0a-DZ-55676526-1m",
    "http://mobile.kookeey.info:1086:7464034-bc00eb59f5:fc54fe8a0a-DZ-28123568-1m",
    "http://mobile.kookeey.info:1086:7464034-bc00eb59f5:fc54fe8a0a-DZ-23954035-1m",
    "http://mobile.kookeey.info:1086:7464034-bc00eb59f5:fc54fe8a0a-DZ-68203884-1m",
    "http://gate.kookeey.info:1000:6569414-55154c240e:199dff35c9-DZ-43435299-1m",
    "http://gate.kookeey.info:1000:6569414-55154c240e:199dff35c9-DZ-70136297-1m",
    "http://gate.kookeey.info:1000:6569414-55154c240e:199dff35c9-DZ-09690211-1m",
    "http://gate.kookeey.info:1000:6569414-55154c240e:199dff35c9-DZ-54922057-1m",
    "http://gate.kookeey.info:1000:6569414-55154c240e:199dff35c9-DZ-41481427-1m",
    "http://mobile.kookeey.info:1086:6569414-55154c240e:199dff35c9-DZ-79987015-1m",
    "http://mobile.kookeey.info:1086:6569414-55154c240e:199dff35c9-DZ-46036262-1m",
    "http://mobile.kookeey.info:1086:6569414-55154c240e:199dff35c9-DZ-44649406-1m",
    "http://mobile.kookeey.info:1086:6569414-55154c240e:199dff35c9-DZ-11784209-1m",
    "http://mobile.kookeey.info:1086:6569414-55154c240e:199dff35c9-DZ-22681983-1m",
    "http://gate.kookeey.info:1000:1049279-6d55cdb272:b6ce229818-DZ-70127176-1m",
    "http://gate.kookeey.info:1000:1049279-6d55cdb272:b6ce229818-DZ-28888193-1m",
    "http://gate.kookeey.info:1000:1049279-6d55cdb272:b6ce229818-DZ-39691366-1m",
    "http://gate.kookeey.info:1000:1049279-6d55cdb272:b6ce229818-DZ-67583368-1m",
    "http://gate.kookeey.info:1000:1049279-6d55cdb272:b6ce229818-DZ-82483278-1m",
    "http://mobile.kookeey.info:1086:1049279-6d55cdb272:b6ce229818-DZ-65551033-1m",
    "http://mobile.kookeey.info:1086:1049279-6d55cdb272:b6ce229818-DZ-25039549-1m",
    "http://mobile.kookeey.info:1086:1049279-6d55cdb272:b6ce229818-DZ-26317792-1m",
    "http://mobile.kookeey.info:1086:1049279-6d55cdb272:b6ce229818-DZ-81851342-1m",
    "http://mobile.kookeey.info:1086:1049279-6d55cdb272:b6ce229818-DZ-25594447-1m",
    "http://gate.kookeey.info:1000:1518711-520d25c499:094049a358-DZ-88224532-1m",
    "http://gate.kookeey.info:1000:1518711-520d25c499:094049a358-DZ-60208739-1m",
    "http://gate.kookeey.info:1000:1518711-520d25c499:094049a358-DZ-71249293-1m",
    "http://gate.kookeey.info:1000:1518711-520d25c499:094049a358-DZ-41349815-1m",
    "http://gate.kookeey.info:1000:1518711-520d25c499:094049a358-DZ-71859234-1m",
    "http://mobile.kookeey.info:1086:1518711-520d25c499:094049a358-DZ-86736697-1m",
    "http://mobile.kookeey.info:1086:1518711-520d25c499:094049a358-DZ-30893493-1m",
    "http://mobile.kookeey.info:1086:1518711-520d25c499:094049a358-DZ-28800972-1m",
    "http://mobile.kookeey.info:1086:1518711-520d25c499:094049a358-DZ-66006049-1m",
    "http://mobile.kookeey.info:1086:1518711-520d25c499:094049a358-DZ-04398964-1m",
    "http://gate.kookeey.info:1000:8179119-2072bee7e4:35067ea761-DZ-81920635-1m",
    "http://gate.kookeey.info:1000:8179119-2072bee7e4:35067ea761-DZ-61920809-1m",
    "http://gate.kookeey.info:1000:8179119-2072bee7e4:35067ea761-DZ-44451293-1m",
    "http://gate.kookeey.info:1000:8179119-2072bee7e4:35067ea761-DZ-48117989-1m",
    "http://gate.kookeey.info:1000:8179119-2072bee7e4:35067ea761-DZ-60195406-1m",
    "http://mobile.kookeey.info:1086:8179119-2072bee7e4:35067ea761-DZ-91715029-1m",
    "http://mobile.kookeey.info:1086:8179119-2072bee7e4:35067ea761-DZ-33683624-1m",
    "http://mobile.kookeey.info:1086:8179119-2072bee7e4:35067ea761-DZ-89868487-1m",
    "http://mobile.kookeey.info:1086:8179119-2072bee7e4:35067ea761-DZ-53888793-1m",
    "http://mobile.kookeey.info:1086:8179119-2072bee7e4:35067ea761-DZ-95280483-1m"
];
}
function saveProxies(array $proxies): void
{
    file_put_contents(PROXY_LIST_FILE, json_encode($proxies));
}
function refreshProxies(): array
{
    $ch = curl_init(PROXY_API_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => false]);
    $body = curl_exec($ch);
    curl_close($ch);
    $list = json_decode($body, true);
    if (is_array($list) && count($list) > 0) {
        saveProxies($list);
        return $list;
    }
    return loadProxies();
}
function parseProxy(string $proxy): array
{
    $raw = preg_replace('#^https?://#', '', $proxy);
    $p   = explode(':', $raw, 4);
    return ['host' => ($p[0] ?? '') . ':' . ($p[1] ?? ''), 'userpass' => ($p[2] ?? '') . ':' . ($p[3] ?? '')];
}

/**
 * getAllProxies — تجميع جميع البروكسيات المتاحة (محلية + API)
 */
function getAllProxies(): array
{
    $local     = loadProxies();
    $fromApi   = refreshProxies();
    $combined  = array_unique(array_merge($local, $fromApi));
    return array_values($combined);
}

/**
 * curlWithAllProxies — يجرب جميع البروكسيات واحدة تلو الأخرى
 * يُعيد ['http_code', 'body', 'json'] أو null إذا فشلت الكل
 */
function curlWithAllProxies(
    string $url,
    string $method,
    string $payload,
    array  $headers,
    string $logTag,
    int    $timeout = 5,
    string $logFile = '/tmp/proxy_curl.log'
): ?array {
    $proxies = getAllProxies();
    $totalProxies = count($proxies);

    if ($totalProxies === 0) {
        dbg("[{$logTag}] No proxies available!");
        return null;
    }

    // ══ PARALLEL CURL: نجرب أول 8 بروكسيات بالتوازي — أسرع من sequential ══
    $batchSize  = min(8, $totalProxies);
    $mh         = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $batchSize);
    $handles    = [];

    for ($i = 0; $i < $batchSize; $i++) {
        $pp  = parseProxy($proxies[$i]);
        $ch  = curl_init($url);
        $opts = [
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_ENCODING        => 'gzip',
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_PROXY           => $pp['host'],
            CURLOPT_PROXYUSERPWD    => $pp['userpass'],
            CURLOPT_PROXYTYPE       => CURLPROXY_HTTP,
            CURLOPT_FOLLOWLOCATION  => true,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $payload;
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    // تشغيل جميع الـ handles بالتوازي
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 0.1);
    } while ($running > 0);

    // أول نتيجة ناجحة تُعاد فوراً
    $winner = null;
    foreach ($handles as $i => $ch) {
        $body     = curl_multi_getcontent($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $bodyStr = (string)$body;
        if ($errno || !$body || $httpCode === 0
            || stripos($bodyStr, '<html') !== false
            || stripos($bodyStr, '<!DOCTYPE') !== false) {
            continue;
        }
        if ($winner === null) {
            $json   = @json_decode($bodyStr, true);
            $winner = ['http_code' => $httpCode, 'body' => $bodyStr, 'json' => $json];
        }
    }
    curl_multi_close($mh);

    if ($winner !== null) return $winner;

    // Batch أول فشل → نجرب الباقي sequentially بشكل سريع
    for ($i = $batchSize; $i < $totalProxies; $i++) {
        $pp = parseProxy($proxies[$i]);
        $ch = curl_init($url);
        $opts = [
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_ENCODING        => 'gzip',
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_PROXY           => $pp['host'],
            CURLOPT_PROXYUSERPWD    => $pp['userpass'],
            CURLOPT_PROXYTYPE       => CURLPROXY_HTTP,
            CURLOPT_FOLLOWLOCATION  => true,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $payload;
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($ch, $opts);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        curl_close($ch);

        $bodyStr = (string)$body;
        if ($errno || !$body || $httpCode === 0
            || stripos($bodyStr, '<html') !== false
            || stripos($bodyStr, '<!DOCTYPE') !== false) {
            continue;
        }
        $json = @json_decode($bodyStr, true);
        return ['http_code' => $httpCode, 'body' => $bodyStr, 'json' => $json];
    }

    dbg("[{$logTag}] ALL {$totalProxies} proxies failed!");
    tgNotifyAdmin("🚨 جميع البروكسيات ({$totalProxies}) فشلت في [{$logTag}]!\n\n/setproxies");
    return null;
}

// ════════════════════════════════════════════════════════════════════════════
// Telegram Notification
// ════════════════════════════════════════════════════════════════════════════
function tgNotifyAdmin(string $text): void
{
    tgSendMessage(TG_ADMIN_ID, $text);
}
function tgSendMessage(string $chatId, string $text, array $keyboard = []): void
{
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if (!empty($keyboard)) {
        $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    }
    $ch = curl_init(TG_API . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    file_put_contents('/tmp/tg_send.log', date('Y-m-d H:i:s') . " chat={$chatId} resp={$resp}\n", FILE_APPEND);
}
function tgAnswerCallback(string $callbackId, string $text = ''): void
{
    $ch = curl_init(TG_API . '/answerCallbackQuery');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['callback_query_id' => $callbackId, 'text' => $text]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
function tgEditMessage(string $chatId, int $messageId, string $text, array $keyboard = []): void
{
    $data = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
    if (!empty($keyboard)) {
        $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    }
    $ch = curl_init(TG_API . '/editMessageText');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ════════════════════════════════════════════════════════════════════════════
// Telegram State (للأوامر متعددة الخطوات)
// ════════════════════════════════════════════════════════════════════════════
function getTgState(string $chatId): array
{
    $f = TG_STATE_DIR . "/{$chatId}.json";
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?? [];
}
function setTgState(string $chatId, array $state): void
{
    file_put_contents(TG_STATE_DIR . "/{$chatId}.json", json_encode($state));
}
function clearTgState(string $chatId): void
{
    $f = TG_STATE_DIR . "/{$chatId}.json";
    if (file_exists($f)) @unlink($f);
}

// ════════════════════════════════════════════════════════════════════════════
// Telegram Webhook Handler
// ════════════════════════════════════════════════════════════════════════════
function handleTelegramUpdate(array $update): void
{
    // Callback Query (ضغط على زر)
    if (isset($update['callback_query'])) {
        $cb     = $update['callback_query'];
        $cbId   = $cb['id'];
        $chatId = (string)($cb['message']['chat']['id'] ?? '');
        $msgId  = (int)($cb['message']['message_id'] ?? 0);
        $data   = $cb['data'] ?? '';

        if ($chatId !== TG_ADMIN_ID) { tgAnswerCallback($cbId, '⛔ غير مصرح'); return; }
        tgAnswerCallback($cbId);
        handleTgCallback($chatId, $msgId, $data);
        return;
    }

    // رسالة عادية
    if (!isset($update['message'])) return;
    $msg    = $update['message'];
    $chatId = (string)($msg['chat']['id'] ?? '');
    $text   = trim($msg['text'] ?? '');

    if ($chatId !== TG_ADMIN_ID) {
        tgSendMessage($chatId, '⛔ أنت لست مصرحاً باستخدام هذا البوت.');
        return;
    }

    $state = getTgState($chatId);

    // حالة انتظار نص البث
    if (($state['action'] ?? '') === 'awaiting_broadcast') {
        handleTgBroadcastText($chatId, $text, $state);
        return;
    }
    // حالة انتظار بروكسيات جديدة (عامة)
    if (($state['action'] ?? '') === 'awaiting_proxies') {
        handleTgProxiesInput($chatId, $text);
        return;
    }
    // حالة انتظار بروكسيات أوريدو المخصصة
    if (($state['action'] ?? '') === 'awaiting_ooredoo_proxies') {
        if (trim($text) === '/cancel') {
            clearTgState($chatId);
            tgSendMessage($chatId, "❌ تم الإلغاء.");
            sendTgMainMenu($chatId);
            return;
        }
        $lines = array_filter(array_map('trim', explode("
", $text)));
        $valid = [];
        foreach ($lines as $line) {
            if (strpos($line, "@") !== false) {
                [$userpass, $hostport] = explode("@", $line, 2);
                $hp = explode(":", $hostport, 2);
                $host = $hp[0] ?? ""; $port = $hp[1] ?? "";
                if ($host && $port) { $valid[] = $host.":".$port.":".$userpass; continue; }
            }
            $parts = explode(":", $line, 4);
            if (count($parts) === 4 && !empty($parts[0])) {
                $valid[] = $line;
            }
        }
        if (empty($valid)) {
            tgSendMessage($chatId, "❌ لم يتم التعرف على أي بروكسي صالح.
الصيغة: <code>ip:port:user:pass</code>");
            return;
        }
        setOoredooProxies($valid);
        clearTgState($chatId);
        $list = implode("
", array_map(fn($p) => "✅ <code>{$p}</code>", $valid));
        tgSendMessage($chatId, "🟡 تم تحديث بروكسيات أوريدو:
{$list}");
        sendTgMainMenu($chatId);
        return;
    }
    // حالة انتظار QR Code للهدية
    if (($state['action'] ?? '') === 'awaiting_qr') {
        handleTgQrInput($chatId, $text);
        return;
    }

    // أوامر
    switch ($text) {
        case '/start':
        case '/help':
            sendTgMainMenu($chatId);
            break;
        case '/stats':
            handleTgStats($chatId);
            break;
        case '/broadcast':
            handleTgBroadcastStart($chatId);
            break;
        case '/setproxies':
            handleTgSetProxies($chatId);
            break;
        case '/proxies':
            handleTgShowProxies($chatId);
            break;
        case '/matchgift':
            handleTgMatchGift($chatId);
            break;
        case '/cancel':
            clearTgState($chatId);
            tgSendMessage($chatId, '✅ تم إلغاء العملية الحالية.');
            sendTgMainMenu($chatId);
            break;
        default:
            sendTgMainMenu($chatId);
    }
}

function sendTgMainMenu(string $chatId): void
{
    $cfg     = getMatchGiftConfig();
    $status  = $cfg['enabled'] ? '✅ مفعّلة' : '❌ معطلة';
    $qr      = $cfg['qr_code'];
    $label   = $cfg['gift_label'];

    $text = "🤖 <b>لوحة تحكم Tasjil BOT</b>\n\n"
          . "📊 اختر أمراً من القائمة أدناه:\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n"
          . "🎁 هدية المباراة: <b>{$status}</b>\n"
          . "📦 الهدية: <b>{$label}</b>\n"
          . "🔗 QR: <code>{$qr}</code>\n"
          . "━━━━━━━━━━━━━━━━━━━━";

    $keyboard = [
        [
            ['text' => '📊 إحصائيات المستخدمين', 'callback_data' => 'tg_stats'],
            ['text' => '📢 إرسال إعلان',          'callback_data' => 'tg_broadcast'],
        ],
        [
            ['text' => '🔗 إدارة البروكسيات',      'callback_data' => 'tg_proxies'],
            ['text' => '➕ إضافة بروكسيات',         'callback_data' => 'tg_setproxies'],
        ],
        [
            ['text' => $cfg['enabled'] ? '🔴 إيقاف هدية المباراة' : '🟢 تفعيل هدية المباراة', 'callback_data' => 'tg_toggle_match'],
            ['text' => '✏️ تغيير QR Code',          'callback_data' => 'tg_set_qr'],
        ],
        [
            ['text' => '🔄 تحديث البروكسيات (API)', 'callback_data' => 'tg_refresh_proxies'],
        ],
        [
            ['text' => ooredooProxyEnabled() ? '🟡 إيقاف بروكسي أوريدو' : '🟡 تفعيل بروكسي أوريدو', 'callback_data' => 'tg_toggle_ooredoo_proxy'],
            ['text' => '✏️ تعديل بروكسيات أوريدو', 'callback_data' => 'tg_edit_ooredoo_proxies'],
        ],
    ];

    tgSendMessage($chatId, $text, $keyboard);
}

function handleTgCallback(string $chatId, int $msgId, string $data): void
{
    switch ($data) {
        case 'tg_stats':
            handleTgStats($chatId);
            break;
        case 'tg_broadcast':
            handleTgBroadcastStart($chatId);
            break;
        case 'tg_proxies':
            handleTgShowProxies($chatId);
            break;
        case 'tg_setproxies':
            handleTgSetProxies($chatId);
            break;
        case 'tg_toggle_match':
            handleTgToggleMatch($chatId);
            break;
        case 'tg_set_qr':
            handleTgSetQr($chatId);
            break;
        case 'tg_refresh_proxies':
            handleTgRefreshProxies($chatId);
            break;
        case 'tg_toggle_ooredoo_proxy':
            $cur = ooredooProxyEnabled();
            setOoredooProxyEnabled(!$cur);
            $label = !$cur ? '✅ مفعّل' : '❌ معطّل';
            tgSendMessage($chatId, "🟡 بروكسي أوريدو الآن: <b>{$label}</b>");
            sendTgMainMenu($chatId);
            break;
        case 'tg_edit_ooredoo_proxies':
            $current = implode("\n", getOoredooProxies());
            tgSendMessage($chatId,
                "✏️ <b>بروكسيات أوريدو الحالية:</b>\n<code>{$current}</code>\n\n"
              . "أرسل القائمة الجديدة، كل بروكسي في سطر:\n"
              . "<code>ip:port:user:pass</code>\n\n"
              . "أرسل /cancel للإلغاء."
            );
            setTgState($chatId, ['action' => 'awaiting_ooredoo_proxies']);
            break;
        case 'tg_broadcast_all':
            setTgState($chatId, ['action' => 'awaiting_broadcast', 'target' => 'all']);
            tgSendMessage($chatId, "📝 أرسل نص الإعلان الذي تريد إرساله لـ <b>جميع المستخدمين</b>:\n\n/cancel للإلغاء");
            break;
        case 'tg_broadcast_active':
            setTgState($chatId, ['action' => 'awaiting_broadcast', 'target' => 'active_7d']);
            tgSendMessage($chatId, "📝 أرسل نص الإعلان الذي تريد إرساله للمستخدمين <b>النشطين (7 أيام)</b>:\n\n/cancel للإلغاء");
            break;
        default:
            // broadcast confirm
            if (str_starts_with($data, 'tg_confirm_broadcast_')) {
                $broadcastId = substr($data, strlen('tg_confirm_broadcast_'));
                executeBroadcast($chatId, $broadcastId);
            }
            break;
    }
}

// ─── Stats ───────────────────────────────────────────────────────────────────
function handleTgStats(string $chatId): void
{
    $stats   = getUserStats();
    $proxies = loadProxies();
    $cfg     = getMatchGiftConfig();

    $text = "📊 <b>إحصائيات Tasjil BOT</b>\n\n"
          . "👥 إجمالي المستخدمين: <b>{$stats['total']}</b>\n"
          . "🟢 نشط (7 أيام): <b>{$stats['active_7d']}</b>\n"
          . "🟡 نشط (30 يوم): <b>{$stats['active_30d']}</b>\n\n"
          . "🔗 عدد البروكسيات: <b>" . count($proxies) . "</b>\n"
          . "🎁 هدية المباراة: <b>" . ($cfg['enabled'] ? '✅ مفعّلة' : '❌ معطلة') . "</b>\n\n"
          . "📅 التاريخ: " . date('Y-m-d H:i:s');

    $keyboard = [[['text' => '🔙 رجوع', 'callback_data' => 'tg_stats']]];
    tgSendMessage($chatId, $text, $keyboard);
}

// ─── Broadcast ───────────────────────────────────────────────────────────────
function handleTgBroadcastStart(string $chatId): void
{
    $stats = getUserStats();
    $text  = "📢 <b>إرسال إعلان</b>\n\n"
           . "👥 إجمالي المستخدمين: {$stats['total']}\n"
           . "🟢 النشطين (7 أيام): {$stats['active_7d']}\n\n"
           . "اختر الجمهور المستهدف:";

    $keyboard = [
        [
            ['text' => "📢 الكل ({$stats['total']})",           'callback_data' => 'tg_broadcast_all'],
            ['text' => "✅ النشطين ({$stats['active_7d']})",     'callback_data' => 'tg_broadcast_active'],
        ],
        [['text' => '❌ إلغاء', 'callback_data' => 'tg_stats']],
    ];
    tgSendMessage($chatId, $text, $keyboard);
}

function handleTgBroadcastText(string $chatId, string $text, array $state): void
{
    if (trim($text) === '' || $text === '/cancel') {
        clearTgState($chatId);
        tgSendMessage($chatId, '❌ تم إلغاء الإعلان.');
        return;
    }

    $target      = $state['target'] ?? 'all';
    $broadcastId = 'bc_' . time() . '_' . substr(md5($text), 0, 6);

    // حفظ بيانات البث للتأكيد
    setTgState($chatId, [
        'action'       => 'pending_broadcast',
        'target'       => $target,
        'broadcast_id' => $broadcastId,
        'message'      => $text,
    ]);

    $users     = ($target === 'all') ? getAllKnownUsers() : getActiveUsers(7);
    $userCount = count($users);

    $preview = "📢 <b>معاينة الإعلان</b>\n\n"
             . "🎯 المستهدفون: <b>{$userCount} مستخدم</b>\n"
             . "🆔 معرف البث: <code>{$broadcastId}</code>\n\n"
             . "━━━━━━━━━━━━━━\n"
             . htmlspecialchars($text)
             . "\n━━━━━━━━━━━━━━\n\n"
             . "⚠️ هل تريد إرسال هذا الإعلان؟";

    $keyboard = [
        [
            ['text' => "✅ إرسال للـ {$userCount}",         'callback_data' => "tg_confirm_broadcast_{$broadcastId}"],
            ['text' => '❌ إلغاء',                          'callback_data' => 'tg_broadcast'],
        ],
    ];
    tgSendMessage($chatId, $preview, $keyboard);
}

function executeBroadcast(string $chatId, string $broadcastId): void
{
    $state = getTgState($chatId);
    if (($state['broadcast_id'] ?? '') !== $broadcastId) {
        tgSendMessage($chatId, '❌ معرف البث غير متطابق.');
        return;
    }

    $target  = $state['target']  ?? 'all';
    $msgText = $state['message'] ?? '';
    $users   = ($target === 'all') ? getAllKnownUsers() : getActiveUsers(7);

    clearTgState($chatId);

    tgSendMessage($chatId, "🚀 جاري الإرسال لـ <b>" . count($users) . "</b> مستخدم...");

    $sent   = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($users as $uid) {
        if (wasBroadcastSent($broadcastId, $uid)) { $skipped++; continue; }
        $result = sendFbMessage($uid, "📢 إعلان:\n\n" . $msgText);
        if ($result) {
            markBroadcastSent($broadcastId, $uid);
            $sent++;
        } else {
            $failed++;
        }
        usleep(80000); // 80ms delay لتجنب الحظر
    }

    $summary = "✅ <b>اكتمل الإعلان</b>\n\n"
             . "📤 مُرسَل: <b>{$sent}</b>\n"
             . "❌ فشل: <b>{$failed}</b>\n"
             . "⏭️ تم تخطيه (سبق الإرسال): <b>{$skipped}</b>\n"
             . "🆔 معرف البث: <code>{$broadcastId}</code>";

    tgSendMessage($chatId, $summary);
    sendTgMainMenu($chatId);
}

// ─── Proxies ─────────────────────────────────────────────────────────────────
function handleTgShowProxies(string $chatId): void
{
    $proxies = loadProxies();
    $count   = count($proxies);

    if ($count === 0) {
        tgSendMessage($chatId, "⚠️ لا توجد بروكسيات محفوظة!\n\nأرسل /setproxies لإضافة بروكسيات جديدة.");
        return;
    }

    $lines = ["🔗 <b>البروكسيات المحفوظة ({$count})</b>\n"];
    foreach ($proxies as $i => $p) {
        $pp     = parseProxy($p);
        $host   = explode(':', $pp['host'])[0] ?? $pp['host'];
        $lines[] = ($i + 1) . ". <code>{$host}</code>";
    }
    $lines[] = "\n/setproxies لإضافة بروكسيات جديدة";

    $keyboard = [
        [
            ['text' => '🔄 تحديث من API',    'callback_data' => 'tg_refresh_proxies'],
            ['text' => '➕ إضافة بروكسيات',  'callback_data' => 'tg_setproxies'],
        ],
        [['text' => '🔙 رجوع', 'callback_data' => 'tg_stats']],
    ];
    tgSendMessage($chatId, implode("\n", $lines), $keyboard);
}

function handleTgSetProxies(string $chatId): void
{
    setTgState($chatId, ['action' => 'awaiting_proxies']);
    tgSendMessage($chatId,
        "📝 <b>إضافة بروكسيات جديدة</b>\n\n"
        . "أرسل قائمة البروكسيات، كل بروكسي في سطر بالصيغة:\n"
        . "<code>https://host:port:user:pass</code>\n\n"
        . "أو JSON array مثل:\n"
        . "<code>[\"https://host:port:user:pass\"]</code>\n\n"
        . "⚠️ ستُستبدل القائمة الحالية بالقائمة الجديدة.\n"
        . "/cancel للإلغاء"
    );
}

function handleTgProxiesInput(string $chatId, string $text): void
{
    if ($text === '/cancel') { clearTgState($chatId); tgSendMessage($chatId, '❌ تم الإلغاء.'); return; }

    // محاولة JSON
    $list = @json_decode($text, true);
    if (!is_array($list)) {
        // سطر بسطر
        $list = array_filter(array_map('trim', explode("\n", $text)));
        $list = array_values($list);
    }

    if (empty($list)) {
        tgSendMessage($chatId, '❌ لم أتمكن من قراءة البروكسيات، تحقق من الصيغة وأعد المحاولة.');
        return;
    }

    // التحقق من الصيغة
    $valid = [];
    foreach ($list as $item) {
        $item = trim($item);
        if (preg_match('#^https?://.+:.+:.+:.+$#', $item)) {
            $valid[] = $item;
        }
    }

    if (empty($valid)) {
        tgSendMessage($chatId, '❌ لا توجد بروكسيات بصيغة صحيحة. الصيغة: https://host:port:user:pass');
        return;
    }

    saveProxies($valid);
    clearTgState($chatId);
    tgSendMessage($chatId, "✅ تم حفظ <b>" . count($valid) . "</b> بروكسي بنجاح!");
    sendTgMainMenu($chatId);
}

function handleTgRefreshProxies(string $chatId): void
{
    tgSendMessage($chatId, '🔄 جاري تحديث البروكسيات من API...');
    $proxies = refreshProxies();
    tgSendMessage($chatId, "✅ تم تحديث البروكسيات: <b>" . count($proxies) . "</b> بروكسي.");
    sendTgMainMenu($chatId);
}

// ─── Match Gift ───────────────────────────────────────────────────────────────
function handleTgToggleMatch(string $chatId): void
{
    $cfg = getMatchGiftConfig();
    $cfg['enabled'] = !$cfg['enabled'];
    saveMatchGiftConfig($cfg);
    $status = $cfg['enabled'] ? '✅ مفعّلة' : '❌ معطلة';
    tgSendMessage($chatId, "🎁 هدية المباراة الآن: <b>{$status}</b>");
    sendTgMainMenu($chatId);
}

function handleTgMatchGift(string $chatId): void
{
    $cfg    = getMatchGiftConfig();
    $status = $cfg['enabled'] ? '✅ مفعّلة' : '❌ معطلة';

    $text = "🎁 <b>إعدادات هدية المباراة</b>\n\n"
          . "الحالة: <b>{$status}</b>\n"
          . "الهدية: <b>{$cfg['gift_label']}</b>\n"
          . "QR Code: <code>{$cfg['qr_code']}</code>\n";

    $keyboard = [
        [
            ['text' => $cfg['enabled'] ? '🔴 إيقاف' : '🟢 تفعيل', 'callback_data' => 'tg_toggle_match'],
            ['text' => '✏️ تغيير QR',                              'callback_data' => 'tg_set_qr'],
        ],
        [['text' => '🔙 رجوع', 'callback_data' => 'tg_stats']],
    ];
    tgSendMessage($chatId, $text, $keyboard);
}

function handleTgSetQr(string $chatId): void
{
    $cfg = getMatchGiftConfig();
    setTgState($chatId, ['action' => 'awaiting_qr']);
    tgSendMessage($chatId,
        "✏️ <b>تغيير QR Code لهدية المباراة</b>\n\n"
        . "QR الحالي:\n<code>{$cfg['qr_code']}</code>\n\n"
        . "أرسل الـ QR Code الجديد:\n"
        . "/cancel للإلغاء"
    );
}

function handleTgQrInput(string $chatId, string $text): void
{
    if ($text === '/cancel') { clearTgState($chatId); tgSendMessage($chatId, '❌ تم الإلغاء.'); return; }

    $cfg = getMatchGiftConfig();
    $cfg['qr_code'] = trim($text);
    saveMatchGiftConfig($cfg);
    clearTgState($chatId);
    tgSendMessage($chatId, "✅ تم تحديث QR Code:\n<code>{$cfg['qr_code']}</code>");
    sendTgMainMenu($chatId);
}

// ════════════════════════════════════════════════════════════════════════════
// Webhook Routing — Facebook & Telegram
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Facebook Verify
    if (isset($_GET['hub_mode'], $_GET['hub_verify_token'], $_GET['hub_challenge'])
        && $_GET['hub_mode'] === 'subscribe'
        && $_GET['hub_verify_token'] === VERIFY_TOKEN) {
        http_response_code(200);
        echo $_GET['hub_challenge'];
    } else {
        http_response_code(403);
        echo 'Forbidden';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data     = json_decode($rawInput, true);
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'EVENT_RECEIVED';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    // تحرير الاتصال فوراً — الـ worker يعود للاستقبال
    if (ob_get_level() > 0) { ob_flush(); ob_end_clean(); }

    if (!$data) exit;

    // ── Telegram Update ──────────────────────────────────────────────────────
    if (isset($data['update_id'])) {
        handleTelegramUpdate($data);
        exit;
    }

    // ── Facebook Update ──────────────────────────────────────────────────────
    if (($data['object'] ?? '') !== 'page') exit;

    foreach ($data['entry'] as $entry) {
        foreach ($entry['messaging'] ?? [] as $event) {
            $psid = $event['sender']['id'] ?? null;
            if (!$psid) continue;
            $eid = buildEventId($psid, $event);
            if (!tryMarkEvent($eid)) { dbg("[DUP] $psid $eid"); continue; }
            if (!tryLockUser($psid)) { dbg("[LOCK] $psid busy"); unmarkEvent($eid); continue; }
            try { processEvent($psid, $event); }
            catch (Throwable $e) { dbg("[ERR] $psid " . $e->getMessage()); }
            finally { unlockUser($psid); }
        }
    }
    exit;
}

http_response_code(200);
echo 'OK';
exit;

// ════════════════════════════════════════════════════════════════════════════
function buildEventId(string $psid, array $event): string
{
    if (isset($event['message'])) {
        $mid = $event['message']['mid'] ?? '';
        if ($mid !== '') return "msg_{$mid}";
        $ts = (int)($event['timestamp'] ?? time());
        return "msg_{$psid}_" . md5(trim($event['message']['text'] ?? '')) . "_" . (int)($ts / 10);
    }
    if (isset($event['postback'])) {
        $ts = (int)($event['timestamp'] ?? time());
        return "pb_{$psid}_" . md5($event['postback']['payload'] ?? '') . "_" . (int)($ts / 10);
    }
    return "ev_{$psid}_" . md5(json_encode($event));
}

// ════════════════════════════════════════════════════════════════════════════
// Process Facebook Event
// ════════════════════════════════════════════════════════════════════════════
function processEvent(string $psid, array $event): void
{
    $isNew = isNewUser($psid);
    markUserAsSeen($psid);

    if (isset($event['postback'])) { handlePostback($psid, $event['postback']['payload'] ?? ''); return; }
    if (!isset($event['message'])) return;

    $msg = $event['message'];
    if (isset($msg['sticker_id']) && $msg['sticker_id'] == 369239263222822) { sendMessage($psid, '👍'); return; }
    if (isset($msg['attachments']) && empty($msg['text'])) { sendMessage($psid, randomSticker()); return; }
    if (isset($msg['quick_reply']['payload'])) { handlePostback($psid, $msg['quick_reply']['payload']); return; }

    $text   = trim($msg['text'] ?? '');
    $digits = preg_replace('/\D/', '', $text);
    if ($text === '') { if ($isNew) sendWelcomeNew($psid); else sendWelcome($psid); return; }

    // Admin Broadcast
    if (preg_match('/@#(.+?)@#/su', $text, $adMatch)) { handleAdminBroadcast($psid, trim($adMatch[1])); return; }

    $session = getSession($psid);
    $state   = $session['state'] ?? 'idle';

    if ($state === 'awaiting_ooredoo_otp')  { handleAwaitingOoredooOtp($psid, $text, $session); return; }
    if ($state === 'awaiting_otp')          { handleAwaitingOtp($psid, $text, $session); return; }
    if ($state === 'awaiting_offer_otp')    { handleOfferOtp($psid, $text, $session); return; }
    if ($state === 'awaiting_invite_phone') { handleInvitePhoneInput($psid, $text, $session); return; }
    if ($state === 'awaiting_invitee_otp')  { handleInviteeOtp($psid, $text, $session); return; }

    $pending = getPending($psid);
    if ($pending !== null) { sendMessage($psid, "⏳ انتظر، نحن نقوم بـ {$pending}\nبعدها يمكنك الطلب."); return; }

    if (preg_match('/^07\d{8}$/', $digits)) { handleNewPhone($psid, $digits); return; }
    if (preg_match('/^05\d{8}$/', $digits)) { handleNewOoredooPhone($psid, $digits); return; }
    if (preg_match('/^06\d{8}$/', $digits)) { sendMessage($psid, "❌ لا يوجد تسجيل Mobilis."); return; }

    if ($state === 'menu' || $state === 'offers') {
        $isOoredoo = ($session['operator'] ?? '') === 'ooredoo';

        if ($isOoredoo) {
            // ── Ooredoo numeric shortcuts ──────────────────────────────────
            $numInt = ctype_digit($text) ? (int)$text : -1;
            if ($text === '1') {
                $user = getUser($psid);
                if ($user && !empty($user['access_token'])) {
                    if (!empty($session['msisdn'])) $user['msisdn'] = $session['msisdn'];
                    activateOoredooDailyGift($psid, $user);
                } else { sendMessage($psid, '⚠️ يجب تسجيل الدخول، أرسل رقمك مجدداً.'); }
            } elseif ($text === '2') {
                handlePostback($psid, 'OOREDOO_OFFERS_MENU');
            } elseif ($text === '3') {
                handlePostback($psid, 'OOREDOO_SNAPCHAT');
            } elseif ($text === '4') {
                handlePostback($psid, 'OOREDOO_MY_INFO');
            } elseif ($numInt >= 5 && $numInt <= 19) {
                $user = getUser($psid);
                if (!$user || empty($user['access_token'])) { sendMessage($psid, '⚠️ يجب تسجيل الدخول، أرسل رقمك مجدداً.'); }
                else {
                    if (!empty($session['msisdn'])) $user['msisdn'] = $session['msisdn'];
                    activateOoredooOffer($psid, $user, $numInt);
                }
            } else {
                // لا شيء يطابق — أعد القائمة
                $user = getUser($psid);
                if ($user && !empty($user['access_token'])) {
                    if (!empty($session['msisdn'])) $user['msisdn'] = $session['msisdn'];
                    ooredooSendPostLoginInfo($psid, $user);
                }
            }
            return;
        }

        // ── Djezzy shortcuts ───────────────────────────────────────────────
        if (false) { // dummy — keeps structure clean
        } elseif ($text === '1')  handlePostback($psid, 'MENU_2G');
        elseif ($text === '2')  handlePostback($psid, 'MENU_70DZ');
        elseif ($text === '3')  handlePostback($psid, 'MENU_INVITE');
        elseif ($text === '4')  handlePostback($psid, 'MENU_MORE_OFFERS');
        elseif ($text === '30') {
            $cfg  = getMatchGiftConfig();
            $sess = getSession($psid);
            $user = getUser($psid);
            if (!$cfg['enabled']) {
                sendMessage($psid, "⏰ هدية المباراة غير متاحة حالياً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
                return;
            }
            if (!$user || empty($user['access_token'])) {
                sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك.");
                return;
            }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            activateAlgeriaMatchGift($psid, $user);
        }
        elseif (isset(OFFER_SHORTCUTS[$text])) {
            handlePostback($psid, 'ACTIVATE_OFFER_' . OFFER_SHORTCUTS[$text]);
        } else {
            sendMessage($psid,
                "❌ اختيار خاطئ\n\n📌 قم باستخدام الأزرار الموجودة بالأسفل\nإذا لم تظهر لك الأزرار أرسل الرقم المناسب 👇\n\n━━━━━━━━━━━━━━\n\n" .
                "1️⃣ لتفعيل 2G الأسبوعية\n📩 أرسل: 1\n\n2️⃣ لتفعيل عرض 4GB بـ 70دج 🏷️\n📩 أرسل: 2\n\n3️⃣ لإرسال دعوة 🎁\n📩 أرسل: 3\n\n4️⃣ للمزيد من العروض 📦\n📩 أرسل: 4\n\n━━━━━━━━━━━━━━"
            );
        }
        return;
    }
    if ($isNew) sendWelcomeNew($psid); else sendWelcome($psid);
}

// ════════════════════════════════════════════════════════════════════════════
// Admin Broadcast (من فيسبوك)
// ════════════════════════════════════════════════════════════════════════════
function handleAdminBroadcast(string $psid, string $adText): void
{
    $users = getAllKnownUsers();
    $broadcastId = 'fb_bc_' . time();
    $count = 0;
    foreach ($users as $uid) {
        if (wasBroadcastSent($broadcastId, $uid)) continue;
        if (sendFbMessage($uid, "📢 إعلان:\n\n" . $adText)) {
            markBroadcastSent($broadcastId, $uid);
            $count++;
        }
        usleep(100000);
    }
    sendMessage($psid, "✅ تم إرسال الإعلان إلى {$count} مستخدم.");
    dbg("[BROADCAST] from={$psid} users={$count}");
}

// ════════════════════════════════════════════════════════════════════════════
// OTP — تسجيل الدخول
// ════════════════════════════════════════════════════════════════════════════
function handleAwaitingOtp(string $psid, string $text, array $session): void
{
    $msisdn       = $session['msisdn'] ?? '';
    $phoneDisplay = '0' . substr($msisdn, 3);
    if (trim($text) === '0') {
        clearSession($psid);
        sendMessage($psid, "✅ تم إلغاء عملية التسجيل.\n\n📱 أرسل رقمك في أي وقت للبدء من جديد.");
        return;
    }
    $digits = preg_replace('/\D/', '', $text);
    if (preg_match('/^07\d{8}$/', $digits)) {
        $newMsisdn = '213' . substr($digits, 1);
        clearSession($psid);
        sendMessage($psid, "📲 جاري إعادة إرسال رمز التحقق إلى الرقم {$digits}...");
        sendOTPAndWait($psid, $newMsisdn, $digits);
        return;
    }
    if (preg_match('/^05\d{8}$/', $digits)) {
        clearSession($psid);
        handleNewOoredooPhone($psid, $digits);
        return;
    }
    if (!preg_match('/\b(\d{6})\b/', $text, $m)) {
        sendMessage($psid,
            "⚠️ الرجاء إدخال رمز التحقق المكوّن من 6 أرقام.\n\n📱 أو أرسل رقم هاتفك مجدداً لاستقبال رمز جديد\n🔢 الرمز أُرسل إلى: {$phoneDisplay}\n\n❌ لإلغاء العملية أرسل: 0"
        );
        return;
    }
    if (empty($msisdn)) { clearSession($psid); sendMessage($psid, "❌ حدث خطأ في الجلسة، أرسل رقمك مجدداً."); return; }
    $result = verifyOTP($msisdn, $m[1]);
    if ($result === 'wrong_otp') {
        sendMessage($psid, "❌ الرمز المُدخل خاطئ!\n\n🔄 أعد إرسال الرمز الصحيح\n📱 أو أرسل رقم هاتفك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0");
    } elseif ($result === false) {
        sendMessage($psid, "❌ حدث خطأ، حاول مجدداً.\n\n📱 يمكنك إرسال رقمك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0");
    } else {
        saveUser($psid, ['user_id' => $psid, 'msisdn' => $msisdn, 'access_token' => $result['access_token'], 'refresh_token' => $result['refresh_token']]);
        savePhoneOwner($msisdn, $psid);
        setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn]);
        sendMessage($psid, "✅ تم تسجيل الدخول بنجاح!");
        sendMenu($psid);
        sendAlgeriaMatchGiftPromo($psid);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// OTP — تفعيل العرض (جديد)
// ════════════════════════════════════════════════════════════════════════════
function handleOfferOtp(string $psid, string $text, array $session): void
{
    $msisdn      = $session['msisdn'] ?? '';
    $packageCode = $session['pending_package'] ?? '';
    $phoneDisplay = '0' . substr($msisdn, 3);

    if (trim($text) === '0') {
        clearSession($psid);
        setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn]);
        sendMessage($psid, "✅ تم إلغاء عملية تفعيل العرض.");
        sendMenu($psid);
        return;
    }
    $digits = preg_replace('/\D/', '', $text);
    if (preg_match('/^07\d{8}$/', $digits)) {
        $newMsisdn = '213' . substr($digits, 1);
        clearSession($psid);
        sendMessage($psid, "📲 جاري إعادة إرسال رمز التحقق إلى الرقم {$digits}...");
        sendNewOTPAndWaitForOffer($psid, $newMsisdn, $digits, $packageCode);
        return;
    }
    if (preg_match('/^05\d{8}$/', $digits)) {
        clearSession($psid);
        handleNewOoredooPhone($psid, $digits);
        return;
    }
    if (!preg_match('/\b(\d{6})\b/', $text, $m)) {
        sendMessage($psid,
            "⚠️ الرجاء إدخال رمز التحقق المكوّن من 6 أرقام.\n\n📱 أو أرسل رقم هاتفك مجدداً لاستقبال رمز جديد\n🔢 الرمز أُرسل إلى: {$phoneDisplay}\n\n❌ لإلغاء العملية أرسل: 0"
        );
        return;
    }
    if (empty($msisdn) || empty($packageCode)) {
        clearSession($psid);
        sendMessage($psid, "❌ حدث خطأ في الجلسة، أرسل رقمك مجدداً.");
        return;
    }
    $result = verifyOTPNew($msisdn, $m[1]);
    if ($result === 'wrong_otp') {
        sendMessage($psid, "❌ الرمز المُدخل خاطئ!\n\n🔄 أعد إرسال الرمز الصحيح\n📱 أو أرسل رقم هاتفك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0");
        return;
    }
    if ($result === false) {
        sendMessage($psid, "❌ حدث خطأ في التحقق، حاول مجدداً.\n\n📱 يمكنك إرسال رقمك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0");
        return;
    }
    $userForOffer = array_merge(getUser($psid) ?? [], [
        'msisdn'        => $msisdn,
        'access_token'  => $result['access_token'],
        'refresh_token' => $result['refresh_token'],
    ]);
    setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn]);
    sendMessage($psid, "✅ تم التحقق بنجاح! جاري تفعيل العرض...");
    activateOfferNew($psid, $userForOffer, $packageCode);
}

// ════════════════════════════════════════════════════════════════════════════
// Phone Handler
// ════════════════════════════════════════════════════════════════════════════
function handleNewPhone(string $psid, string $phone): void
{
    $msisdn = '213' . substr($phone, 1);
    $owner  = getPhoneOwner($msisdn);
    if ($owner === $psid) {
        $user = getUser($psid);
        if ($user && !empty($user['access_token'])) {
            $user['msisdn'] = $msisdn;
            saveUser($psid, $user);
            $refreshed = refreshAccessToken($user['refresh_token'], $msisdn, $psid);
            if ($refreshed === 'expired') return; // تم إرسال رمز تحقق جديد بالفعل من داخل refreshAccessToken
            if (is_array($refreshed)) {
                saveUser($psid, array_merge($user, ['msisdn' => $msisdn, 'access_token' => $refreshed['access_token'], 'refresh_token' => $refreshed['refresh_token']]));
                setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn]);
                sendMessage($psid, "✅ تم التعرف على رقمك بنجاح!");
                sendMenu($psid);
                sendAlgeriaMatchGiftPromo($psid);
                return;
            }
        }
    } elseif ($owner !== null) {
        sendMessage($psid, "🚫 أنت لست صاحب الرقم، يجب إثبات الهوية.\n\n📲 سيتم إرسال رمز تحقق إلى هذا الرقم...");
    }
    sendOTPAndWait($psid, $msisdn, $phone);
}
function sendOTPAndWait(string $psid, string $msisdn, string $phone): void
{
    if (sendDjezzyOTP($msisdn)) {
        setSession($psid, ['state' => 'awaiting_otp', 'msisdn' => $msisdn]);
        sendMessage($psid,
            "✅ تم إرسال رمز التحقق إلى الرقم {$phone}.\n\n🔢 الرجاء إدخال الرمز المكوّن من 6 أرقام:\n\n📱 أو أرسل رقمك مجدداً لاستقبال رمز جديد\n\n❌ لإلغاء العملية أرسل: 0"
        );
    } else {
        sendMessage($psid, "سيرفر جازي غير متاح حاليا نعمل على اصلاحه 🧑‍🔧 يمكنك التسجيل عبر التطبيق الخاص بنا رابط تحميله https://tasjil-app.lovestoblog.com
او من
https://t.me/tasjilbott/130");
    }
}
function sendNewOTPAndWaitForOffer(string $psid, string $msisdn, string $phone, string $packageCode): void
{
    if (sendDjezzyOTPNew($msisdn)) {
        setSession($psid, ['state' => 'awaiting_offer_otp', 'msisdn' => $msisdn, 'pending_package' => $packageCode]);
        $offerInfo  = OFFERS[$packageCode] ?? null;
        $offerLabel = $offerInfo ? $offerInfo['name'] : $packageCode;
        sendMessage($psid,
            "✅ تم إرسال رمز التحقق إلى الرقم {$phone}.\n\n" .
            "📌 نقوم الآن بتجربة طريقة تفعيل أخرى للعرض.\n\n" .
            "🔢 الرجاء إدخال الرمز المكوّن من 6 أرقام لتفعيل العرض:\n" .
            "📦 العرض: {$offerLabel}\n\n" .
            "📱 أو أرسل رقمك مجدداً لاستقبال رمز جديد\n\n" .
            "❌ لإلغاء العملية أرسل: 0"
        );
    } else {
        sendMessage($psid, "سيرفر جازي غير متاح حاليا نعمل على اصلاحه 🧑‍🔧 يمكنك التسجيل عبر التطبيق الخاص بنا رابط تحميله https://tasjil-app.lovestoblog.com
او من
https://t.me/tasjilbott/130");
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Postback Handler
// ════════════════════════════════════════════════════════════════════════════
function handlePostback(string $psid, string $payload): void
{
    if (str_starts_with($payload, 'ACTIVATE_OFFER_')) {
        $packageCode = substr($payload, strlen('ACTIVATE_OFFER_'));
        $sess = getSession($psid); $user = getUser($psid);
        if (!$user || empty($user['access_token'])) { sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك."); return; }
        if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
        setSession($psid, array_merge($sess, ['state' => 'menu']));
        activateOffer($psid, $user, $packageCode);
        return;
    }
    switch ($payload) {
        case 'GET_STARTED':
            sendWelcomeNew($psid);
            break;
        case 'OOREDOO_DAILY_GIFT':
            $sess = getSession($psid);
            $user = getUser($psid);
            if (!$user || empty($user['access_token'])) {
                sendMessage($psid, '⚠️ يجب تسجيل الدخول أولاً، أرسل رقم أوريدو الخاص بك.');
                return;
            }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            activateOoredooDailyGift($psid, $user);
            break;
        case 'ACTIVATE_ALGERIA_MATCH':
            $cfg  = getMatchGiftConfig();
            $sess = getSession($psid);
            $user = getUser($psid);
            if (!$cfg['enabled']) {
                sendMessage($psid, "⏰ هدية المباراة غير متاحة حالياً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
                return;
            }
            if (!$user || empty($user['access_token'])) {
                sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك.");
                return;
            }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            activateAlgeriaMatchGift($psid, $user);
            break;
        case 'MENU_2G':
            $sess = getSession($psid); $user = getUser($psid);
            if (!$user || empty($user['access_token'])) { sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك."); return; }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            setSession($psid, array_merge($sess, ['state' => 'menu']));
            activate2G($psid, $user);
            break;
        case 'MENU_70DZ':
            $sess = getSession($psid); $user = getUser($psid);
            if (!$user || empty($user['access_token'])) { sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك."); return; }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            setSession($psid, array_merge($sess, ['state' => 'menu']));
            activate70DZ($psid, $user);
            break;
        case 'MENU_INVITE':
            $sess = getSession($psid); $user = getUser($psid);
            if (!$user || empty($user['access_token'])) { sendMessage($psid, "⚠️ يجب تسجيل الدخول أولاً، أرسل رقم هاتفك."); return; }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            handleInviteStart($psid, $user);
            break;
        case 'MENU_MORE_OFFERS':
            sendMoreOffers($psid);
            break;
        case 'BACK_MENU':
            sendMenu($psid);
            break;
        case 'OOREDOO_BACK_MENU':
            $sess = getSession($psid); $user = getUser($psid);
            if ($user && !empty($user['access_token'])) {
                if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
                ooredooSendPostLoginInfo($psid, $user);
            } else {
                sendMessage($psid, '⚠️ يجب تسجيل الدخول، أرسل رقمك مجدداً.');
            }
            break;
        case 'OOREDOO_OFFERS_MENU':
            $sess = getSession($psid); $user = getUser($psid);
            if (!$user || empty($user['access_token'])) { sendMessage($psid, '⚠️ يجب تسجيل الدخول، أرسل رقمك مجدداً.'); return; }
            sendOoredooOffersMenu($psid);
            break;
        case 'OOREDOO_SNAPCHAT':
            $sess = getSession($psid); $user = getUser($psid);
            if (!$user || empty($user['access_token'])) { sendMessage($psid, '⚠️ يجب تسجيل الدخول، أرسل رقمك مجدداً.'); return; }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            activateOoredooSnapchat($psid, $user);
            break;
        case 'OOREDOO_MY_INFO':
            $sess = getSession($psid); $user = getUser($psid);
            if (!$user || empty($user['access_token'])) { sendMessage($psid, '⚠️ يجب تسجيل الدخول، أرسل رقمك مجدداً.'); return; }
            if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
            sendOoredooMyInfo($psid, $user);
            break;
        default:
            // Ooredoo offer by number: OOREDOO_OFFER_5 .. OOREDOO_OFFER_19
            if (str_starts_with($payload, 'OOREDOO_OFFER_')) {
                $num  = (int)substr($payload, strlen('OOREDOO_OFFER_'));
                $sess = getSession($psid); $user = getUser($psid);
                if (!$user || empty($user['access_token'])) { sendMessage($psid, '⚠️ يجب تسجيل الدخول.'); return; }
                if (!empty($sess['msisdn'])) $user['msisdn'] = $sess['msisdn'];
                activateOoredooOffer($psid, $user, $num);
                return;
            }
            sendWelcome($psid);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Algeria Match Gift — يتحكم بها من تلقرام
// ════════════════════════════════════════════════════════════════════════════
function sendAlgeriaMatchGiftPromo(string $psid): void
{
    $cfg = getMatchGiftConfig();
    if (!$cfg['enabled']) return;

    $label = $cfg['gift_label'];
    fbApiCall(json_encode([
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => [
            'text' =>
                "🇩🇿🔥 هدية خاصة في اليوم الذي تلعب فيه الجزائر مباراة ⚽!\n\n" .
                "━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                "🎁 احصل على {$label} مجاناً 🎉\n\n" .
                "━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                "📩 أرسل الرقم 30 للتفعيل الفوري",
            'quick_replies' => [[
                'content_type' => 'text',
                'title'        => '🇩🇿 تفعيل هدية المباراة',
                'payload'      => 'ACTIVATE_ALGERIA_MATCH',
            ]],
        ],
    ], JSON_UNESCAPED_UNICODE));
}

function activateAlgeriaMatchGift(string $psid, array $user): void
{
    $cfg = getMatchGiftConfig();
    if (!$cfg['enabled']) {
        sendMessage($psid, "⏰ هدية المباراة غير متاحة حالياً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
        return;
    }

    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $msisdn        = $user['msisdn'];
    $accessToken   = $user['access_token'];
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);
    $qrCode        = $cfg['qr_code'];
    $label         = $cfg['gift_label'];

    sendMessage($psid, "🔄 جاري تفعيل هدية مباراة الجزائر 🇩🇿...");

    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/scan/activate-reward/{$msisdn}";
    $payload = json_encode(['qrCode' => $qrCode]);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Encoding: gzip',
        "Authorization: Bearer {$accessToken}",
        'User-Agent: MobileApp/3.0.0',
    ];

    $raw = curlWithAllProxies($url, 'POST', $payload, $headers, 'MATCH_GIFT', 12, '/tmp/match_gift.log');

    if ($raw === null) {
        sendMessage($psid, "❌ حدث خطأ أثناء تفعيل الهدية. يرجى المحاولة مجدداً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
        return;
    }

    $httpCode = $raw['http_code'];

    if ($httpCode === 200 || $httpCode === 201) {
        recordFinalResult($psid);
        sendMessage($psid,
            "🎉 تم تفعيل الهدية بنجاح!\n\n" .
            "استمتع بمباراة الجزائر 🇩🇿⚽\n\n" .
            "✅ الرقم: {$displayMasked}\n" .
            "🎁 الهدية: {$label}\n\n" .
            "⚡ قناة التلغرام: https://t.me/tasjilbott"
        );
        sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰");
        clearSession($psid);
        return;
    }

    if ($httpCode === 400) {
        recordFinalResult($psid);
        sendMessage($psid,
            "⚠️ اما اليوم ليس يوم مباراة الجزائر أو انك استفدت من الهدية مسبقاً أو أن رقمك غير مؤهل لهذه الهدية.\n\n" .
            "⚡ قناة التلغرام: https://t.me/tasjilbott"
        );
        clearSession($psid);
        return;
    }

    sendMessage($psid, "❌ حدث خطأ أثناء تفعيل الهدية (HTTP {$httpCode}). يرجى المحاولة مجدداً.\n\n⚡ قناة التلغرام: https://t.me/tasjilbott");
    clearSession($psid);
}

// ════════════════════════════════════════════════════════════════════════════
// activateOffer — قديم ثم جديد
// ════════════════════════════════════════════════════════════════════════════
function activateOffer(string $psid, array $user, string $packageCode): void
{
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $msisdn        = $user['msisdn'];
    $accessToken   = $user['access_token'];
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);
    $offerInfo     = OFFERS[$packageCode] ?? null;
    $offerLabel    = $offerInfo ? $offerInfo['name'] : $packageCode;

    setPending($psid, "تفعيل {$offerLabel} 🔖");
    sendMessage($psid, "جاري تفعيل العرض {$offerLabel} 🔄...");

    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/subscribers/activate-product/{$msisdn}";
    $payload = json_encode(['packageCode' => $packageCode]);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Encoding: gzip',
        'accept-language: fr',
        "authorization: Bearer {$accessToken}",
        'User-Agent: MobileApp/3.0.0',
    ];

    $raw = curlWithAllProxies($url, 'POST', $payload, $headers, "OFFER_OLD:{$packageCode}", 10, '/tmp/activate70.log');

    if ($raw !== null) {
        $httpCode     = $raw['http_code'];
        $responseData = $raw['json'];
        $bodyStr      = $raw['body'];
        dbg("[OFFER_OLD:{$packageCode}] http={$httpCode} body=" . substr($bodyStr, 0, 300));

        if (is_array($responseData)) {
            $innerStatus = (int)($responseData['status'] ?? 0);
            $innerMsg    = $responseData['message'] ?? '';

            if ($httpCode === 402 || $innerStatus === 402) {
                clearPending($psid); recordFinalResult($psid);
                $balance    = $responseData['data']['mainBalance'] ?? null;
                $balanceMsg = ($balance !== null) ? "رصيدك الحالي: {$balance} دج 💳\n" : "";
                sendMessage($psid, "حدث خطأ ⚠️ رصيدك غير كافي 💰 لتفعيل هذا العرض 🔖 😔\n{$balanceMsg}\n⚡ قناة التلقرام : https://t.me/tasjilbott");
                clearSession($psid); sendMessage($psid, ""); return;
            }
            if ($httpCode === 200 || $httpCode === 201 || $innerStatus === 200) {
                $msgStr = is_array($innerMsg) ? ($innerMsg['en'] ?? '') : (string)$innerMsg;
                if (stripos($msgStr, 'successfully') !== false || $httpCode === 201 || $innerStatus === 200) {
                    clearPending($psid); recordFinalResult($psid);
                    $detailMsg = $offerInfo ? "\n✅ تفاصيل العرض: " . $offerInfo['display'] : "";
                    sendMessage($psid, "⭐ تم تفعيل العرض بنجاح 🎁 للرقم {$displayMasked}\n✅ اسم العرض: {$offerLabel}{$detailMsg}\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
                    sendMessage($psid, "");
                    clearSession($psid);
                    sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰");
                    return;
                }
            }
        }
    }

    // فشل — جرب الجديدة
    clearPending($psid);
    sendMessage($psid, "⚠️ تعذر تفعيل العرض بالطريقة الأولى، جارٍ تجربة طريقة أخرى...");
    $phoneDisplay = '0' . substr($msisdn, 3);
    sendNewOTPAndWaitForOffer($psid, $msisdn, $phoneDisplay, $packageCode);
}

// ════════════════════════════════════════════════════════════════════════════
// activateOfferNew
// ════════════════════════════════════════════════════════════════════════════
function activateOfferNew(string $psid, array $user, string $packageCode): void
{
    $msisdn        = $user['msisdn'];
    $accessToken   = $user['access_token'];
    $refreshToken  = $user['refresh_token'];
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);
    $offerInfo     = OFFERS[$packageCode] ?? null;
    $offerLabel    = $offerInfo ? $offerInfo['name'] : $packageCode;

    $maxAttempts       = 10;
    $maxTokenRefresh   = 3;
    $tokenRefreshCount = 0;

    setPending($psid, "تفعيل {$offerLabel} 🔖");

    $url     = "https://apim.djezzy.dz/djezzy-api/api/v1/subscribers/{$msisdn}/subscription-product?include=";
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Encoding: gzip',
        'Connection: Keep-Alive',
        "Authorization: Bearer {$accessToken}",
        'User-Agent: Djezzy/2.7.0',
        'X-Csrf-Token: YACIN_DZ',
    ];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $payload = json_encode(['data' => ['id' => $packageCode, 'type' => 'products']]);

        // تحديث Authorization header عند تجديد التوكن
        $hdrs = $headers;
        foreach ($hdrs as &$h) {
            if (str_starts_with($h, 'Authorization:')) {
                $h = "Authorization: Bearer {$accessToken}";
            }
        }
        unset($h);

        $raw = curlWithAllProxies($url, 'POST', $payload, $hdrs, "OFFER_NEW:{$packageCode}:#{$attempt}", 10, '/tmp/activate_offer_new.log');
        if ($raw === null) { usleep(1000000); continue; }

        $httpCode     = $raw['http_code'];
        $responseData = $raw['json'];
        dbg("[OFFER_NEW:{$packageCode}] attempt={$attempt} http={$httpCode}");

        if (!is_array($responseData)) { if ($httpCode === 429) usleep(2000000); else usleep(1000000); continue; }

        $fault = $responseData['fault'] ?? null;
        if ($fault !== null) {
            $faultCode = (int)($fault['code'] ?? 0);
            if ($faultCode === 900901) {
                if ($tokenRefreshCount >= $maxTokenRefresh) break;
                $tokenRefreshCount++;
                $refreshed = refreshAccessTokenNew($refreshToken, $msisdn, $psid, $packageCode);
                if ($refreshed === 'expired') { clearPending($psid); return; }
                if ($refreshed === false) { clearPending($psid); return; }
                $accessToken  = $refreshed['access_token'];
                $refreshToken = $refreshed['refresh_token'];
                $attempt--;
                continue;
            }
            usleep(1000000); continue;
        }

        $innerStatus = (int)($responseData['status'] ?? 0);
        if ($httpCode === 402 || $innerStatus === 402) {
            clearPending($psid); recordFinalResult($psid);
            $balance    = $responseData['data']['mainBalance'] ?? null;
            $balanceMsg = ($balance !== null) ? "رصيدك الحالي: {$balance} دج 💳\n" : "";
            sendMessage($psid, "حدث خطأ ⚠️ رصيدك غير كافي 💰 لتفعيل هذا العرض 🔖 😔\n{$balanceMsg}\n⚡ قناة التلقرام : https://t.me/tasjilbott");
            clearSession($psid); sendMessage($psid, ""); return;
        }
        if ($httpCode === 200 || $httpCode === 201) {
            clearPending($psid); recordFinalResult($psid);
            $detailMsg = $offerInfo ? "\n✅ تفاصيل العرض: " . $offerInfo['display'] : "";
            sendMessage($psid, "⭐ تم تفعيل العرض بنجاح 🎁 للرقم {$displayMasked}\n✅ اسم العرض: {$offerLabel}{$detailMsg}\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
            sendMessage($psid, "");
            clearSession($psid);
            sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰");
            return;
        }
        if ($httpCode === 429) { usleep(2000000); continue; }
        usleep(1000000);
    }

    clearPending($psid); recordFinalResult($psid);
    sendMessage($psid, "عذرا ⚠️ تعذر تفعيل العرض، يبدو أن شريحتك لا تدعم هذا العرض أو حدث خطأ مؤقت\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
    clearSession($psid);
    sendMessage($psid, "");
}

// ════════════════════════════════════════════════════════════════════════════
// activate70DZ & activate2G
// ════════════════════════════════════════════════════════════════════════════
function activate70DZ(string $psid, array $user): void
{
    activateOffer($psid, $user, 'BTLINTSPEEDDAY2Go');
}

function activate2G(string $psid, array $user): void
{
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $msisdn        = $user['msisdn'];
    $accessToken   = $user['access_token'];
    $refreshToken  = $user['refresh_token'];
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);

    sendMessage($psid, "🔍 جاري فحص تاريخ آخر تفعيل...");
    $history = fetchSubscriptionHistory($msisdn, $accessToken);
    if ($history !== null) {
        $lastTs = getLastWalkWinDate($history);
        if ($lastTs !== null) {
            $elapsed   = time() - $lastTs;
            $sevenDays = 7 * 24 * 3600;
            if ($elapsed < $sevenDays) {
                $remaining = $sevenDays - $elapsed;
                recordFinalResult($psid);
                sendMessage($psid,
                    "عذرا 😬 لم تكمل أسبوع ⚠️\n\n⏳ الوقت المتبقي: " . formatTimeRemaining($remaining) .
                    "\n\nأعد المحاولة بعد انتهاء هذه المدة 📆\n\n⚡ قناة التلقرام : https://t.me/tasjilbott"
                );
                clearSession($psid); sendMessage($psid, ""); return;
            }
        }
    }

    $maxAttempts       = 30;
    $maxTokenRefresh   = 3;
    $tokenRefreshCount = 0;
    setPending($psid, 'تفعيل 2G 🎁');
    sendMessage($psid, "جاري تفعيل 2G 🎁 🔄...");

    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/walk/activate-reward/{$msisdn}";
    $payload = json_encode(['packageCode' => 'GIFTWALKWIN2GO']);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Encoding: gzip',
        "Authorization: Bearer {$accessToken}",
        'User-Agent: MobileApp/3.0.0',
    ];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $hdrs = $headers;
        foreach ($hdrs as &$h) {
            if (str_starts_with($h, 'Authorization:')) $h = "Authorization: Bearer {$accessToken}";
        }
        unset($h);

        $raw = curlWithAllProxies($url, 'POST', $payload, $hdrs, "2G:#{$attempt}", 10, '/tmp/activate2g.log');
        if ($raw === null) { usleep(1000000); continue; }

        $httpCode     = $raw['http_code'];
        $responseData = $raw['json'];
        dbg("[2G] attempt={$attempt} http={$httpCode}");

        if (!is_array($responseData)) { if ($httpCode === 429) usleep(2000000); else usleep(1000000); continue; }

        $fault = $responseData['fault'] ?? null;
        if ($fault !== null && (int)($fault['code'] ?? 0) === 900901) {
            if ($tokenRefreshCount >= $maxTokenRefresh) {
                clearPending($psid);
                sendMessage($psid, "فشل تحديث الجلسة بعد عدة محاولات، الرجاء إعادة ارسال رقمك للتسجيل من جديد");
                clearSession($psid); return;
            }
            $tokenRefreshCount++;
            $refreshed = refreshAccessToken($refreshToken, $msisdn, $psid);
            if ($refreshed === 'expired') { clearPending($psid); return; }
            if ($refreshed === false) { clearPending($psid); clearSession($psid); return; }
            $accessToken  = $refreshed['access_token'];
            $refreshToken = $refreshed['refresh_token'];
            saveUser($psid, array_merge($user, ['access_token' => $accessToken, 'refresh_token' => $refreshToken]));
            $attempt--; continue;
        }

        $innerStatus = (int)($responseData['status'] ?? 0);
        if ($httpCode === 402 || $innerStatus === 402 || $httpCode === 403 || $innerStatus === 403) {
            clearPending($psid); recordFinalResult($psid);
            sendMessage($psid,
                "عذرا ⚠️ يلزمك الاشتراك في باقة 100da 💰 (عشرة الاف) او اكثر ثم بعدها يمكنك الاستفادة من 2G 🎁 المجانية كل اسبوع طيلة شهر كامل 📆\n\n" .
                "🔴 ملاحظة 1️⃣: هذا التحديث من المتعامل جيزي ولا يمكن تجاوزه ⚠️\n🔴 ملاحظة 2️⃣: يلزمك عرض ابتداءا من 100da او اكثر 💰\n⚡ قناة التلقرام : https://t.me/tasjilbott"
            );
            clearSession($psid); sendMessage($psid, ""); return;
        }
        if ($httpCode === 201 || $httpCode === 200 || $innerStatus === 200) {
            $msgStr = $responseData['message'] ?? '';
            if (is_array($msgStr)) $msgStr = $msgStr['en'] ?? '';
            if (stripos($msgStr, 'successfully') !== false || $httpCode === 201 || $innerStatus === 200) {
                clearPending($psid); recordFinalResult($psid);
                sendMessage($psid, "⭐ تم تفعيل 2G بنجاح 🎁 للرقم {$displayMasked}\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
                sendMessage($psid, "");
                clearSession($psid);
                sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰");
                return;
            }
            usleep(1000000); continue;
        }
        if ($httpCode === 429) { usleep(2000000); continue; }
        usleep(1000000);
    }
    clearPending($psid);
    sendMessage($psid, "هناك اشكال في سيرفر جيزي ⚠️ لم نستطع التفعيل لرقمك \n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
    clearSession($psid); sendMessage($psid, "");
}

// ════════════════════════════════════════════════════════════════════════════
// MGM — بداية عملية الدعوة
// ════════════════════════════════════════════════════════════════════════════
function handleInviteStart(string $psid, array $user): void
{
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $msisdn      = $user['msisdn'];
    $accessToken = $user['access_token'];

    sendMessage($psid, "🔍 جاري فحص المكافآت المعلقة...");
    $bonusResult = tryActivateMgmBonus($psid, $msisdn, $accessToken, $user);

    if ($bonusResult === 'SUCCESS_1GO') {
        recordFinalResult($psid);
        sendMessage($psid, "🎁 تم تفعيل مكافأة معلقة وحصلت على 1 جيقا 🎉\n\n⏳ عد بعد 24 ساعة للحصول على مكافأة جديدة 📆\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
        clearSession($psid);
        sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰");
        return;
    }
    if ($bonusResult === 'SUCCESS_500MO') {
        recordFinalResult($psid);
        sendMessage($psid, "🎁 تم تفعيل مكافأة معلقة وحصلت على 500Mo 🎉\n\n⏳ عد بعد 24 ساعة للحصول على مكافأة جديدة 📆\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
        clearSession($psid);
        sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰");
        return;
    }
    if ($bonusResult === 'ALREADY_CLAIMED') {
        recordFinalResult($psid);
        sendMessage($psid, "⚠️ لقد استفدت من الدعوة اليوم ولديك مكافأة أخرى معلقة.\nتأكد من مرور 24 ساعة على آخر استلام للمكافأة وأعد المحاولة 📆\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
        clearSession($psid); sendMessage($psid, ""); return;
    }

    sendMessage($psid, "🔍 جاري الفحص اذا كانت لديك دعوات متاحة ...");
    $invitations = fetchMgmInvitations($msisdn, $accessToken);
    if ($invitations === null) {
        sendMessage($psid, "❌ حدث خطأ أثناء جلب بيانات الدعوات، حاول مجدداً.");
        clearSession($psid); sendMessage($psid, ""); return;
    }

    $maxInvitations = $invitations['campaign']['maxInvitation'] ?? 5;
    $invitationList = $invitations['invitations'] ?? [];
    $doneCount = 0; $pendingCount = 0; $pendingIds = [];
    foreach ($invitationList as $inv) {
        if (($inv['status'] ?? '') === 'DONE')    $doneCount++;
        if (($inv['status'] ?? '') === 'PENDING') { $pendingCount++; $pendingIds[] = $inv['id'] ?? null; }
    }
    $totalCount = count($invitationList);

    if ($doneCount >= $maxInvitations) {
        recordFinalResult($psid);
        sendMessage($psid, "🚫 لقد وصلت للحد الأقصى لعدد الدعوات {$maxInvitations} ✅\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
        clearSession($psid); sendMessage($psid, ""); return;
    }
    if ($totalCount >= $maxInvitations && $pendingCount > 0) {
        sendMessage($psid, "🔄 جاري حذف الدعوات المعلقة لتوفير مكان...");
        $deleted = deletePendingInvitations($msisdn, $accessToken, $pendingIds);
        if (!$deleted) {
            sendMessage($psid, "❌ تعذر حذف الدعوات المعلقة، حاول لاحقاً.");
            clearSession($psid); sendMessage($psid, ""); return;
        }
        sendMessage($psid, "✅ تم حذف الدعوات المعلقة بنجاح.");
    }

    setSession($psid, [
        'state'         => 'awaiting_invite_phone',
        'msisdn'        => $msisdn,
        'access_token'  => $accessToken,
        'refresh_token' => $user['refresh_token'],
    ]);
    sendMessage($psid, "📲 أرسل رقم هاتف الشخص الذي تريد دعوته (جيزي فقط)\nمثال: 0770000000\n\n❌ لإلغاء العملية أرسل: 1");
}

function handleInvitePhoneInput(string $psid, string $text, array $session): void
{
    if (trim($text) === '1') {
        clearSession($psid); sendMessage($psid, "✅ تم إلغاء عملية الدعوة."); sendMenu($psid); return;
    }
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $digits = preg_replace('/\D/', '', $text);
    if (!preg_match('/^07\d{8}$/', $digits)) {
        sendMessage($psid, "❌ الرقم غير صحيح، أرسل رقم جيزي بصيغة 07xxxxxxxx\n\n❌ لإلغاء العملية أرسل: 1");
        return;
    }

    $receiverMsisdn = '213' . substr($digits, 1);
    $senderMsisdn   = $session['msisdn'];
    if ($receiverMsisdn === $senderMsisdn) {
        sendMessage($psid, "❌ لا يمكنك دعوة رقمك الخاص!\n\nأرسل رقم شخص آخر.\n\n❌ لإلغاء العملية أرسل: 1");
        return;
    }

    $accessToken  = $session['access_token'];
    $refreshToken = $session['refresh_token'];
    sendMessage($psid, "📤 جاري إرسال الدعوة...");
    $result = sendMgmInvitation($senderMsisdn, $receiverMsisdn, $accessToken, $refreshToken, $psid);

    switch ($result['status']) {
        case 'SUCCESS':
            recordFinalResult($psid);
            sendMessage($psid,
                "✅ تم إرسال الدعوة بنجاح إلى الرقم 0" . substr($receiverMsisdn, 3) . " 🎉\n\n📲 تم إرسال رسالة نصية إلى الرقم المدعو.\nسيتم الآن تفعيل مكافأتك بعد تسجيل المدعو...\n\n🔢 الرجاء إدخال رمز التحقق الذي وصل لرقم المدعو:\n\n❌ لإلغاء العملية أرسل: 1"
            );
            if (sendDjezzyOTP($receiverMsisdn)) {
                setSession($psid, [
                    'state'          => 'awaiting_invitee_otp',
                    'msisdn'         => $senderMsisdn,
                    'access_token'   => $result['access_token'] ?? $accessToken,
                    'refresh_token'  => $result['refresh_token'] ?? $refreshToken,
                    'invitee_msisdn' => $receiverMsisdn,
                ]);
            } else {
                sendMessage($psid, "⚠️ تعذر إرسال رمز التحقق للمدعو. حاول لاحقاً.");
                clearSession($psid); sendMessage($psid, "");
            }
            break;
        case 'MAX_INVITATIONS':
            recordFinalResult($psid);
            sendMessage($psid, "🚫 لقد وصلت للحد الأقصى لعدد الدعوات 5 ✅\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
            clearSession($psid); sendMessage($psid, ""); break;
        case 'ALREADY_INVITED':
            sendMessage($psid, "⚠️ لقد تمت دعوة هذا الرقم من قبل، استخدم رقماً آخر.\n❌ لإلغاء العملية أرسل: 1"); break;
        case 'CUSTOMER_NOT_EXIST':
        case 'INVALID_NUMBER':
            sendMessage($psid, "❌ الرقم المدرج غير موجود أو غير نشط، تأكد من الرقم وأعد المحاولة.\n❌ لإلغاء العملية أرسل: 1"); break;
        case 'TOKEN_EXPIRED':
            sendMessage($psid, "🔄 انتهت صلاحية الجلسة، الرجاء إعادة إرسال رقمك للتسجيل.");
            clearSession($psid); sendMessage($psid, ""); break;
        default:
            sendMessage($psid, "❌ حدث خطأ غير متوقع، حاول لاحقاً.");
            clearSession($psid); sendMessage($psid, ""); break;
    }
}

function handleInviteeOtp(string $psid, string $text, array $session): void
{
    if (trim($text) === '1') {
        clearSession($psid); sendMessage($psid, "✅ تم إلغاء عملية الدعوة."); sendMenu($psid); return;
    }
    if (!preg_match('/\b(\d{6})\b/', $text, $m)) {
        sendMessage($psid, "⚠️ الرجاء إدخال رمز التحقق المكوّن من 6 أرقام.\n\n❌ لإلغاء العملية أرسل: 1"); return;
    }
    $rl = checkRateLimit($psid);
    if ($rl !== null) { sendMessage($psid, rateLimitMessage($rl)); return; }

    $inviteeMsisdn = $session['invitee_msisdn'];
    $senderMsisdn  = $session['msisdn'];
    $senderToken   = $session['access_token'];

    sendMessage($psid, "🔐 جاري التحقق من الرمز...");
    $inviteeResult = verifyOTP($inviteeMsisdn, $m[1]);
    if ($inviteeResult === 'wrong_otp') {
        sendMessage($psid, "❌ الرمز خاطئ، أعد إرسال الرمز الصحيح.\n\n❌ لإلغاء العملية أرسل: 1"); return;
    }
    if ($inviteeResult === false) {
        sendMessage($psid, "❌ حدث خطأ أثناء التحقق، حاول مجدداً.");
        clearSession($psid); sendMessage($psid, ""); return;
    }
    $inviteeToken = $inviteeResult['access_token'];
    sendMessage($psid, "🎁 تم التحقق بنجاح! جاري تفعيل المكافآت...");

    $senderBonus  = activateMgmReward($senderMsisdn, $senderToken, 'MGMBONUS1Go');
    $inviteeBonus = activateMgmReward($inviteeMsisdn, $inviteeToken, 'MGMBONUS500Mo');

    $senderMsg = match($senderBonus) {
        'SUCCESS'          => "✅ مكافأتك (1 جيقا) تم تفعيلها بنجاح 🎉",
        'ALREADY_CLAIMED'  => "⚠️ مكافأتك محجوزة، تأكد من مرور 24 ساعة وأعد المحاولة.",
        'REWARD_NOT_EXIST' => "❌ لا توجد مكافأة متاحة لرقمك حالياً.",
        default            => "⚠️ تعذر تفعيل مكافأتك مؤقتاً.",
    };
    $inviteeMsg = match($inviteeBonus) {
        'SUCCESS'          => "✅ مكافأة المدعو (500Mo) تم تفعيلها بنجاح 🎉",
        'ALREADY_CLAIMED'  => "⚠️ مكافأة الرقم المدعو محجوزة، تأكد من مرور 24 ساعة وأعد المحاولة.",
        'REWARD_NOT_EXIST' => "❌ لا توجد مكافأة متاحة للمدعو حالياً.",
        default            => "⚠️ تعذر تفعيل مكافأة المدعو مؤقتاً.",
    };
    recordFinalResult($psid);
    sendMessage($psid, "📊 نتيجة تفعيل المكافآت:\n\n👤 أنت (الداعي):\n{$senderMsg}\n\n👤 المدعو:\n{$inviteeMsg}\n\n⚡ قناة التلقرام : https://t.me/tasjilbott");
    clearSession($psid);
    sendMessage($psid, "\n\n🥰 اذا كنت تريد دعمنا حتى نطور الخدمة ونستمر 🥰\n\n🔴 ادخل للموقع 👇\n\nhttps://timebucks.com/?refID=227870531\n\n✅ وسجل بحساب جوجل فقط 🥰");
}

// ════════════════════════════════════════════════════════════════════════════
// MGM API Calls
// ════════════════════════════════════════════════════════════════════════════
function fetchMgmInvitations(string $msisdn, string $accessToken): ?array
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/mgm/invitations/{$msisdn}";
    $headers = ['Accept: application/json', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0', 'accept-language: ar'];
    $raw     = curlWithAllProxies($url, 'GET', '', $headers, 'MGM_FETCH', 12);
    if ($raw === null) return null;
    $json = $raw['json'];
    if (is_array($json) && ($json['status'] ?? 0) == 200) return $json['data'] ?? [];
    return null;
}

function deletePendingInvitations(string $msisdn, string $accessToken, array $pendingIds): bool
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/mgm/delete-invitation/{$msisdn}";
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: gzip', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0', 'accept-language: ar'];
    $success = false;
    foreach ($pendingIds as $id) {
        if ($id === null) continue;
        $raw = curlWithAllProxies($url, 'POST', json_encode(['invitationId' => $id]), $headers, 'MGM_DEL', 10);
        if ($raw && ($raw['http_code'] === 200 || $raw['http_code'] === 201)) $success = true;
    }
    return $success;
}

function sendMgmInvitation(string $senderMsisdn, string $receiverMsisdn, string $accessToken, string $refreshToken, string $psid): array
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/mgm/send-invitation/{$senderMsisdn}";
    $payload = json_encode(['msisdnReciever' => $receiverMsisdn]);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: gzip', 'accept-language: ar', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0'];

    $maxTokenRefresh = 3; $tokenRefreshCount = 0;

    for ($attempts = 0; $attempts < 10; $attempts++) {
        $hdrs = $headers;
        foreach ($hdrs as &$h) {
            if (str_starts_with($h, 'Authorization:')) $h = "Authorization: Bearer {$accessToken}";
        }
        unset($h);

        $raw = curlWithAllProxies($url, 'POST', $payload, $hdrs, 'MGM_INVITE', 10);
        if ($raw === null) { usleep(1000000); continue; }

        $httpCode = $raw['http_code'];
        $json     = $raw['json'];
        dbg("[MGM_INVITE] http={$httpCode}");

        if (!is_array($json)) { usleep(1000000); continue; }

        $fault = $json['fault'] ?? null;
        if ($fault && (int)($fault['code'] ?? 0) === 900901) {
            if ($tokenRefreshCount >= $maxTokenRefresh) return ['status' => 'TOKEN_EXPIRED'];
            $tokenRefreshCount++;
            $refreshed = refreshAccessToken($refreshToken, $senderMsisdn, $psid);
            if ($refreshed === 'expired') return ['status' => 'TOKEN_EXPIRED'];
            if ($refreshed === false) return ['status' => 'TOKEN_EXPIRED'];
            $accessToken = $refreshed['access_token']; $refreshToken = $refreshed['refresh_token'];
            continue;
        }
        $msgField = $json['message'] ?? '';
        $arMsg    = is_array($msgField) ? ($msgField['ar'] ?? '') : (string)$msgField;
        if ($httpCode === 201 && str_contains($arMsg, 'تمت العملية بنجاح')) return ['status' => 'SUCCESS', 'access_token' => $accessToken, 'refresh_token' => $refreshToken];
        if ($httpCode === 400 && str_contains($arMsg, 'وصلت إلى الحد الأقصى')) return ['status' => 'MAX_INVITATIONS'];
        if (str_contains($arMsg, 'تمت دعوة هذا المستلم') || str_contains($arMsg, 'هذه العملية غير متوفرة')) return ['status' => 'ALREADY_INVITED'];
        if (str_contains($arMsg, 'العميل غير موجود')) return ['status' => 'CUSTOMER_NOT_EXIST'];
        if (str_contains($arMsg, 'غير نشط أو غير صالح')) return ['status' => 'INVALID_NUMBER'];
        usleep(1000000);
    }
    return ['status' => 'ERROR'];
}

function activateMgmReward(string $msisdn, string $accessToken, string $packageCode): string
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/services/mgm/activate-reward/{$msisdn}";
    $payload = json_encode(['packageCode' => $packageCode]);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: gzip', 'accept-language: ar', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0'];

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $raw = curlWithAllProxies($url, 'POST', $payload, $headers, "MGM_REWARD:{$packageCode}:#{$attempt}", 15);
        if ($raw === null) { usleep(500000); continue; }

        $httpCode = $raw['http_code'];
        $json     = $raw['json'];
        dbg("[MGM_REWARD:{$packageCode}] attempt={$attempt} http={$httpCode}");

        if ($httpCode === 200 || $httpCode === 201) {
            if (is_array($json)) {
                $msg    = $json['message'] ?? '';
                $msgStr = is_array($msg) ? ($msg['en'] ?? ($msg['ar'] ?? '')) : (string)$msg;
                $status = (int)($json['status'] ?? 0);
                if (stripos($msgStr, 'successfully') !== false || stripos($msgStr, 'تم') !== false || $httpCode === 201 || $status === 200 || $status === 201 || empty($msgStr)) return 'SUCCESS';
            } else { return 'SUCCESS'; }
        }
        if (!is_array($json)) { usleep(500000); continue; }
        if ($httpCode === 404) {
            $msg  = $json['message'] ?? '';
            $msgEn = is_array($msg) ? ($msg['en'] ?? '') : (string)$msg;
            if (stripos($msgEn, 'Eligibility not found') !== false || stripos($msgEn, 'eligibility') !== false) return 'ALREADY_CLAIMED';
            return 'REWARD_NOT_EXIST';
        }
        if ($httpCode === 400) {
            $msg  = $json['message'] ?? '';
            $msgAr = is_array($msg) ? ($msg['ar'] ?? '') : (string)$msg;
            $msgEn = is_array($msg) ? ($msg['en'] ?? '') : (string)$msg;
            if (str_contains($msgAr, 'تعذر معالجة طلبك') || str_contains($msgAr, 'لم تمر') || stripos($msgEn, 'cannot be processed') !== false) return 'ALREADY_CLAIMED';
        }
        if ($httpCode === 401) return 'ERROR';
        usleep(500000);
    }
    return 'ERROR';
}

function tryActivateMgmBonus(string $psid, string $msisdn, string $accessToken, array $user): string
{
    $r1 = activateMgmReward($msisdn, $accessToken, 'MGMBONUS1Go');
    if ($r1 === 'SUCCESS')          return 'SUCCESS_1GO';
    if ($r1 === 'ALREADY_CLAIMED')  return 'ALREADY_CLAIMED';
    if ($r1 === 'REWARD_NOT_EXIST') {
        $r2 = activateMgmReward($msisdn, $accessToken, 'MGMBONUS500Mo');
        if ($r2 === 'SUCCESS')         return 'SUCCESS_500MO';
        if ($r2 === 'ALREADY_CLAIMED') return 'ALREADY_CLAIMED';
        return 'REWARD_NOT_EXIST';
    }
    return 'ERROR';
}

// ════════════════════════════════════════════════════════════════════════════
// fetchSubscriptionHistory
// ════════════════════════════════════════════════════════════════════════════
function fetchSubscriptionHistory(string $msisdn, string $accessToken): ?array
{
    $url     = "https://apim.djezzy.dz/mobile-api/api/v1/subscribers/subscription-history/{$msisdn}";
    $headers = ['Accept: application/json', "Authorization: Bearer {$accessToken}", 'User-Agent: MobileApp/3.0.0', 'Connection: Keep-Alive', 'Accept-Language: fr'];
    $raw     = curlWithAllProxies($url, 'GET', '', $headers, 'SUB_HISTORY', 12);
    if ($raw === null) return null;
    $json = $raw['json'];
    if (is_array($json) && ($json['status'] ?? 0) == 200) return $json['data'] ?? [];
    return null;
}
function getLastWalkWinDate(array $history): ?int
{
    $latest = null;
    foreach ($history as $item) {
        $code = $item['packageCode'] ?? '';
        if (in_array($code, ['GIFTWALKWIN2GO', 'GIFTWALKWIN1GO'])) {
            $dt = $item['subscriptionDateTime'] ?? null;
            if ($dt) {
                $ts = strtotime($dt);
                if ($ts !== false && ($latest === null || $ts > $latest)) $latest = $ts;
            }
        }
    }
    return $latest;
}
function formatTimeRemaining(int $secondsLeft): string
{
    if ($secondsLeft <= 0) return "0 ثانية";
    $days    = (int)($secondsLeft / 86400);
    $hours   = (int)(($secondsLeft % 86400) / 3600);
    $minutes = (int)(($secondsLeft % 3600) / 60);
    $secs    = $secondsLeft % 60;
    $parts   = [];
    if ($days > 0)    $parts[] = "{$days} يوم";
    if ($hours > 0)   $parts[] = "{$hours} ساعة";
    if ($minutes > 0) $parts[] = "{$minutes} دقيقة";
    if ($secs > 0 && $days === 0 && $hours === 0) $parts[] = "{$secs} ثانية";
    return implode(' و', $parts);
}

// ════════════════════════════════════════════════════════════════════════════
// ملصقات عشوائية للرد على المرفقات بدون نص
// ════════════════════════════════════════════════════════════════════════════
function randomSticker(): string
{
    $stickers = ['👍🏻', '👍🏻', '👍🏻', '👍🏻', '👍🏻', '👍🏻', '👍🏻', '👍🏻', '👍🏻', '👍🏻'];
    return $stickers[array_rand($stickers)];
}

// ════════════════════════════════════════════════════════════════════════════
// Token Refresh — credentials قديمة
// ════════════════════════════════════════════════════════════════════════════
function refreshAccessToken(string $refreshToken, string $msisdn, string $psid): mixed
{
    $allProxies = getAllProxies();
    $maxAttempts = count($allProxies) * 3;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $pp     = parseProxy($allProxies[$i % count($allProxies)]);
        $result = refreshTokenRequest($refreshToken, $pp['host'], $pp['userpass']);
        if ($result === 'expired') {
            sendMessage($psid, "🔄 انتهت صلاحية الجلسة، سيتم إرسال رمز تحقق جديد...");
            sendOTPAndWait($psid, $msisdn, '0' . substr($msisdn, 3));
            return 'expired'; // تم إعلام المستخدم وإرسال رمز جديد بالفعل — على المستدعي التوقف بصمت
        }
        if ($result === 'html' || $result === false) { usleep(300000); continue; }
        saveUser($psid, array_merge(getUser($psid) ?? [], ['access_token' => $result['access_token'], 'refresh_token' => $result['refresh_token']]));
        return $result;
    }
    return false; // خطأ شبكة/بروكسي مؤقت — لم يتم إعلام المستخدم بشيء بعد
}
function refreshTokenRequest(string $refreshToken, string $proxyHost, string $proxyAuth): mixed
{
    $r = djezzyCurl('https://apim.djezzy.dz/oauth2/token',
        http_build_query(['scope' => 'djezzyAppV2', 'client_secret' => CLIENT_SECRET_OLD, 'client_id' => CLIENT_ID_OLD, 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]),
        $proxyHost, $proxyAuth, 'refresh');
    if ($r === 'html' || $r === false) return $r;
    $json = @json_decode($r['body'], true);
    if ($r['code'] === 400 && ($json['error'] ?? '') === 'invalid_grant') return 'expired';
    if ($r['code'] === 200 && isset($json['access_token'])) return ['access_token' => $json['access_token'], 'refresh_token' => $json['refresh_token'] ?? $refreshToken];
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// Token Refresh — credentials جديدة
// ════════════════════════════════════════════════════════════════════════════
function refreshAccessTokenNew(string $refreshToken, string $msisdn, string $psid, string $packageCode): mixed
{
    $allProxies  = getAllProxies();
    $maxAttempts = count($allProxies) * 3;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $pp     = parseProxy($allProxies[$i % count($allProxies)]);
        $result = refreshTokenRequestNew($refreshToken, $pp['host'], $pp['userpass']);
        if ($result === 'expired') {
            sendMessage($psid, "🔄 انتهت صلاحية الجلسة، سيتم إرسال رمز تحقق جديد...");
            sendNewOTPAndWaitForOffer($psid, $msisdn, '0' . substr($msisdn, 3), $packageCode);
            return 'expired';
        }
        if ($result === 'html' || $result === false) { usleep(300000); continue; }
        return $result;
    }
    return false;
}
function refreshTokenRequestNew(string $refreshToken, string $proxyHost, string $proxyAuth): mixed
{
    $r = djezzyCurl('https://apim.djezzy.dz/oauth2/token',
        http_build_query(['scope' => 'djezzyAppV2', 'client_secret' => CLIENT_SECRET_NEW, 'client_id' => CLIENT_ID_NEW, 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]),
        $proxyHost, $proxyAuth, 'refresh_new');
    if ($r === 'html' || $r === false) return $r;
    $json = @json_decode($r['body'], true);
    if ($r['code'] === 400 && ($json['error'] ?? '') === 'invalid_grant') return 'expired';
    if ($r['code'] === 200 && isset($json['access_token'])) return ['access_token' => $json['access_token'], 'refresh_token' => $json['refresh_token'] ?? $refreshToken];
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// OTP / Token — قديم
// ════════════════════════════════════════════════════════════════════════════
function sendDjezzyOTP(string $msisdn): bool
{
    foreach (getAllProxies() as $p) {
        $pp = parseProxy($p);
        if (djezzyOtpCurl($msisdn, CLIENT_ID_OLD, $pp['host'], $pp['userpass'])) return true;
    }
    return false;
}
function verifyOTP(string $msisdn, string $otp): mixed
{
    foreach (getAllProxies() as $p) {
        $pp  = parseProxy($p);
        $res = djezzyTokenReq($msisdn, $otp, $pp['host'], $pp['userpass']);
        if ($res === 'wrong_otp') return 'wrong_otp';
        if (is_array($res)) return $res;
    }
    return false;
}
function djezzyTokenReq(string $msisdn, string $otp, string $ph, string $pa): mixed
{
    $r = djezzyCurl('https://apim.djezzy.dz/oauth2/token',
        http_build_query(['scope' => 'djezzyAppV2', 'client_secret' => CLIENT_SECRET_OLD, 'client_id' => CLIENT_ID_OLD, 'otp' => $otp, 'mobileNumber' => $msisdn, 'grant_type' => 'mobile']),
        $ph, $pa, 'token');
    if ($r === 'html' || $r === false) return false;
    $json = @json_decode($r['body'], true);
    if ($r['code'] === 400 && ($json['error'] ?? '') === 'invalid_grant') return 'wrong_otp';
    if ($r['code'] === 200 && isset($json['access_token'])) return ['access_token' => $json['access_token'], 'refresh_token' => $json['refresh_token'] ?? ''];
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// OTP / Token — جديد
// ════════════════════════════════════════════════════════════════════════════
function sendDjezzyOTPNew(string $msisdn): bool
{
    foreach (getAllProxies() as $p) {
        $pp = parseProxy($p);
        if (djezzyOtpCurl($msisdn, CLIENT_ID_NEW, $pp['host'], $pp['userpass'])) return true;
    }
    return false;
}
function verifyOTPNew(string $msisdn, string $otp): mixed
{
    foreach (getAllProxies() as $p) {
        $pp  = parseProxy($p);
        $res = djezzyTokenReqNew($msisdn, $otp, $pp['host'], $pp['userpass']);
        if ($res === 'wrong_otp') return 'wrong_otp';
        if (is_array($res)) return $res;
    }
    return false;
}
function djezzyTokenReqNew(string $msisdn, string $otp, string $ph, string $pa): mixed
{
    $r = djezzyCurl('https://apim.djezzy.dz/oauth2/token',
        http_build_query(['scope' => 'djezzyAppV2', 'client_secret' => CLIENT_SECRET_NEW, 'client_id' => CLIENT_ID_NEW, 'otp' => $otp, 'mobileNumber' => $msisdn, 'grant_type' => 'mobile']),
        $ph, $pa, 'token_new');
    if ($r === 'html' || $r === false) return false;
    $json = @json_decode($r['body'], true);
    if ($r['code'] === 400 && ($json['error'] ?? '') === 'invalid_grant') return 'wrong_otp';
    if ($r['code'] === 200 && isset($json['access_token'])) return ['access_token' => $json['access_token'], 'refresh_token' => $json['refresh_token'] ?? ''];
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// OOREDOO — Proxy Toggle (يتحكم بها من تلقرام)
// ════════════════════════════════════════════════════════════════════════════
function ooredooProxyEnabled(): bool
{
    if (!file_exists(OOREDOO_PROXY_ENABLED_FILE)) return true;
    $d = json_decode(file_get_contents(OOREDOO_PROXY_ENABLED_FILE), true);
    return ($d['enabled'] ?? true) === true;
}
function setOoredooProxyEnabled(bool $v): void
{
    file_put_contents(OOREDOO_PROXY_ENABLED_FILE, json_encode(['enabled' => $v]));
}

// ── إدارة بروكسيات أوريدو المخصصة ──
function getOoredooProxies(): array
{
    if (file_exists(OOREDOO_PROXIES_FILE)) {
        $d = json_decode(file_get_contents(OOREDOO_PROXIES_FILE), true);
        if (!empty($d['proxies'])) return $d['proxies'];
    }
    return OOREDOO_DEFAULT_PROXIES;
}
function setOoredooProxies(array $list): void
{
    file_put_contents(OOREDOO_PROXIES_FILE, json_encode(['proxies' => $list]));
}
function parseOoredooProxy(string $raw): array
{
    // format: ip:port:user:pass
    $parts = explode(':', $raw, 4);
    return [
        'host'     => ($parts[0] ?? '') . ':' . ($parts[1] ?? ''),
        'userpass' => ($parts[2] ?? '') . ':' . ($parts[3] ?? ''),
    ];
}

// ════════════════════════════════════════════════════════════════════════════
// OOREDOO — Sticky Proxy Session
// نضمن أن checkpoint و token يخرجان من نفس الـ IP
// ════════════════════════════════════════════════════════════════════════════
function ooredooGetStickyProxy(string $psid): ?array
{
    $f = OOREDOO_SESSIONS_DIR . "/{$psid}_proxy.json";
    if (!file_exists($f)) return null;
    $d = json_decode(file_get_contents($f), true);
    if (!$d || (time() - ($d['ts'] ?? 0)) > 300) { @unlink($f); return null; }
    return $d;
}
function ooredooSetStickyProxy(string $psid, array $pp): void
{
    file_put_contents(OOREDOO_SESSIONS_DIR . "/{$psid}_proxy.json",
        json_encode(array_merge($pp, ['ts' => time()])));
}
function ooredooClearStickyProxy(string $psid): void
{
    $f = OOREDOO_SESSIONS_DIR . "/{$psid}_proxy.json";
    if (file_exists($f)) @unlink($f);
}

// ════════════════════════════════════════════════════════════════════════════
// OOREDOO — Smart cURL
// - 200 + HTML → IP block → انتقل لبروكسي آخر
// - يسجّل كل خطأ في OOREDOO_LOG
// - يدعم وضع direct (بدون بروكسي) إذا كانت البروكسيات معطّلة
// ════════════════════════════════════════════════════════════════════════════
function ooredooLog(string $msg): void
{
    file_put_contents(OOREDOO_LOG,
        date('Y-m-d H:i:s') . " $msg\n", FILE_APPEND);
}

function ooredooCurlRaw(
    string $url,
    string $method,
    string $payload,
    array  $headers,
    string $logTag,
    int    $timeout,
    ?array $proxy = null   // null = direct
): ?array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,      // نحتاج response headers للـ nonce/chronos
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false,     // لا نتبع redirect — نريد الـ headers الأصلية
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    } elseif ($method === 'HEAD') {
        $opts[CURLOPT_NOBODY]     = true;
    } else {
        $opts[CURLOPT_HTTPGET]    = true;
    }
    if ($proxy !== null) {
        $opts[CURLOPT_PROXY]        = $proxy['host'];
        $opts[CURLOPT_PROXYUSERPWD] = $proxy['userpass'];
        $opts[CURLOPT_PROXYTYPE]    = CURLPROXY_HTTP;
    }
    curl_setopt_array($ch, $opts);
    // ── REQUEST LOG ──────────────────────────────
    $reqLog  = date("Y-m-d H:i:s") . " [REQ:{$logTag}] {$method} {$url}";
    $reqLog .= " proxy=" . ($proxy ? $proxy["host"] : "direct") . "\n";
    foreach ($headers as $h) { $reqLog .= "  > {$h}\n"; }
    if ($payload !== "" && $payload !== "{}") {
        $reqLog .= "  BODY: " . substr($payload, 0, 400) . "\n";
    }
    file_put_contents(OOREDOO_LOG, $reqLog, FILE_APPEND);
    // ─────────────────────────────────────────────
    $raw      = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSz = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $errno    = curl_errno($ch);
    $errMsg   = curl_error($ch);
    curl_close($ch);

    if ($errno || $raw === false) {
        ooredooLog("[{$logTag}] cURL error errno={$errno} msg={$errMsg}");
        return null;
    }

    $rawStr      = (string)$raw;
    $rawHeaders  = substr($rawStr, 0, $headerSz);
    $body        = substr($rawStr, $headerSz);

    // فحص IP block: 200 + HTML = محجوب
    $isHtml = (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<html') !== false);
    if ($isHtml) {
        ooredooLog("[{$logTag}] IP_BLOCKED http={$httpCode} proxy=" . ($proxy ? $proxy['host'] : 'direct'));
        return ['ip_blocked' => true];
    }

    // استخراج response headers
    $respHeaders = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $respHeaders[strtolower(trim($k))] = trim($v);
        }
    }

    $json = @json_decode($body, true);
    ooredooLog("[{$logTag}] http={$httpCode} body=" . substr($body, 0, 200));

    return [
        'ip_blocked'  => false,
        'http_code'   => $httpCode,
        'body'        => $body,
        'json'        => $json,
        'resp_headers'=> $respHeaders,
    ];
}

/**
 * ooredooRequest — يحاول عبر البروكسيات (أو direct).
 * $stickyProxy: إذا مُرّر، يُجبر استخدام هذا البروكسي فقط (للـ nonce/chronos chain).
 * يُعيد ['result', 'proxy'] أو null
 */
function ooredooRequest(
    string $url,
    string $method,
    string $payload,
    array  $headers,
    string $logTag,
    int    $timeout = 12,
    ?array $stickyProxy = null
): ?array {
    $useProxy = ooredooProxyEnabled();

    // إذا كان sticky proxy مُحدَّد → نستخدمه فقط
    if ($stickyProxy !== null) {
        $r = ooredooCurlRaw($url, $method, $payload, $headers, $logTag, $timeout, $stickyProxy);
        if ($r === null || ($r['ip_blocked'] ?? false)) {
            ooredooLog("[{$logTag}] sticky proxy failed, giving up");
            return null;
        }
        return ['result' => $r, 'proxy' => $stickyProxy];
    }

    // وضع direct (بدون بروكسي)
    if (!$useProxy) {
        $r = ooredooCurlRaw($url, $method, $payload, $headers, $logTag, $timeout, null);
        if ($r === null || ($r['ip_blocked'] ?? false)) {
            ooredooLog("[{$logTag}] direct request blocked");
            return null;
        }
        return ['result' => $r, 'proxy' => null];
    }

    // تجربة البروكسيات واحدة تلو الأخرى
    $proxies = getAllProxies();
    if (empty($proxies)) {
        ooredooLog("[{$logTag}] No proxies available!");
        tgNotifyAdmin("⚠️ [Ooredoo] لا توجد بروكسيات! [{$logTag}]");
        return null;
    }
    shuffle($proxies); // توزيع عشوائي لتقليل الضغط
    foreach ($proxies as $p) {
        $pp = parseProxy($p);
        $r  = ooredooCurlRaw($url, $method, $payload, $headers, $logTag, $timeout, $pp);
        if ($r === null) { usleep(200000); continue; }
        if ($r['ip_blocked'] ?? false) { usleep(200000); continue; }
        return ['result' => $r, 'proxy' => $pp];
    }

    ooredooLog("[{$logTag}] ALL proxies failed or blocked");
    tgNotifyAdmin("🚨 [Ooredoo] جميع البروكسيات فشلت في [{$logTag}]!");
    return null;
}

// ════════════════════════════════════════════════════════════════════════════
// OOREDOO — Auth Flow (7 خطوات)
// ════════════════════════════════════════════════════════════════════════════
/**
 * ooredooBaseHeaders — كل القيم ديناميكية الآن (مثل Python _chk)
 * $device = ['instance_id' => ..., 'plat_sig' => ...]
 */
function ooredooBaseHeaders(string $msisdn, string $fingerprint, string $correlationId, string $timestamp, array $device = []): array
{
    $instanceId = $device['instance_id'] ?? ooredooNewInstanceId();
    $platSig    = $device['plat_sig']    ?? ooredooNewPlatSig($instanceId);
    return [
        'User-Agent: Dart/3.11 (dart:io)',
        'Accept-Encoding: gzip',
        "x-msisdn: {$msisdn}",
        "x-device-fingerprint: {$fingerprint}",
        'x-platform-origin: ' . OOREDOO_PLATFORM_ORIGIN,
        "x-correlation-id: {$correlationId}",
        'platform: ' . OOREDOO_PLATFORM,
        'x-version: ' . OOREDOO_X_VERSION,
        'x-signature: ' . OOREDOO_X_SIG,
        "x-platform-data-signature: {$platSig}",
        "x-timestamp: {$timestamp}",
        "x-instance-id: {$instanceId}",
    ];
}

/**
 * ooredooSendOTP — الخطوات 1-4:
 * Check status → check userInfo → checkpoint1 → token_no_otp (→ 403 = SMS أُرسل)
 * يُعيد ['nonce1','chronos1','proxy'] أو false
 */
function ooredooSendOTP(string $msisdn): array|false
{
    // ──────────────────────────────────────────────────────────────────────
    // استراتيجية: Step1+Step2 = أي proxy مقبول (BFF عادي)
    //             Step3 (checkpoint) + Step4 (token) = يحتاج TLS نظيف
    //             → نجرب checkpoint/token أولاً بـ direct، ثم بالبروكسيات
    //             → checkpoint و token يجب أن يكونا من نفس الـ IP (sticky)
    // ──────────────────────────────────────────────────────────────────────
    $useProxy  = ooredooProxyEnabled();
    $allProxies = $useProxy ? getAllProxies() : [];
    if (!empty($allProxies)) shuffle($allProxies);

    // إنشاء device identity ديناميكي لهذه الجلسة
    $ts     = ooredooNowMs();
    $corrId = ooredooNewCorrId();
    $iid    = ooredooNewInstanceId();
    $psig   = ooredooNewPlatSig($iid);
    $device = ['instance_id' => $iid, 'plat_sig' => $psig];

    // Step 1+2: نختار أي proxy يُكمل STATUS+USERINFO
    $workingProxy = null; // null = direct

    // ── Step1+2: STATUS+USERINFO — direct مقبول هنا ──
    $candidates12 = array_merge([null], array_map('parseProxy', $allProxies));
    $step12done   = false;
    foreach ($candidates12 as $proxy12) {
        $url1  = OOREDOO_BFF . '/users/status?msisdn=' . urlencode($msisdn);
        $hdrs1 = ooredooBaseHeaders($msisdn, ooredooNewFingerprint(), $corrId, $ts, $device);
        $r1    = ooredooCurlRaw($url1, 'GET', '', $hdrs1, 'OO_STATUS', 8, $proxy12);
        if (!$r1 || ($r1['ip_blocked'] ?? false) || ($r1['http_code'] ?? 0) !== 200) {
            ooredooLog('[OTP] Step1 fail proxy=' . ($proxy12['host'] ?? 'direct'));
            continue;
        }
        $url2  = OOREDOO_BFF . '/userInfo?msisdn=' . urlencode($msisdn);
        $hdrs2 = ooredooBaseHeaders($msisdn, ooredooNewFingerprint(), ooredooNewCorrId(), $ts, $device);
        $r2    = ooredooCurlRaw($url2, 'HEAD', '', $hdrs2, 'OO_USERINFO', 8, $proxy12);
        if (!$r2 || ($r2['ip_blocked'] ?? false) || ($r2['http_code'] ?? 0) !== 204) {
            ooredooLog('[OTP] Step2 fail proxy=' . ($proxy12['host'] ?? 'direct') . ' code=' . ($r2['http_code'] ?? 'null'));
            continue;
        }
        ooredooLog('[OTP] Step1+2 OK proxy=' . ($proxy12['host'] ?? 'direct'));
        $step12done = true;
        break;
    }
    if (!$step12done) {
        ooredooLog('[OTP] Step1+2 ALL failed');
        return false;
    }

    // ── Step3+4: checkpoint+token ──
    // CRITICAL: نفس الـ proxy للاثنين (nonce مرتبط بـ IP)
    // نستخدم بروكسيات أوريدو المخصصة أولاً (ip:port:user:pass format)
    $ooredooProxies = getOoredooProxies();
    if (empty($ooredooProxies)) {
        ooredooLog('[OTP] No Ooredoo-specific proxies configured');
        return false;
    }
    shuffle($ooredooProxies);
    $candidates34 = array_map('parseOoredooProxy', $ooredooProxies);
    foreach ($candidates34 as $chkProxy) {
        $ts     = ooredooNowMs();
        $corrId = ooredooNewCorrId();

        // ── 3: POST /checkpoint/token ──
        $hdrs3 = ooredooBaseHeaders($msisdn, ooredooNewFingerprint(), $corrId, $ts, $device);
        $hdrs3[] = 'content-type: application/json; charset=utf-8';
        $hdrs3[] = 'x-native-integrity-token: ' . OOREDOO_INTEGRITY_1;
        $hdrs3[] = 'x-path: /api/auth/realms/myooredoo/protocol/openid-connect/token';
        $hdrs3[] = 'x-method: POST';
        $r3 = ooredooCurlRaw(OOREDOO_BFF . '/checkpoint/token', 'POST', '{}', $hdrs3, 'OO_CHK1', 10, $chkProxy);
        if (!$r3 || ($r3['ip_blocked'] ?? false)) {
            ooredooLog('[OTP] Step3 fail proxy=' . ($chkProxy['host'] ?? 'direct') . ' errno noted');
            continue;
        }
        $nonce1   = $r3['resp_headers']['x-nonce-id']   ?? '';
        $chronos1 = $r3['resp_headers']['x-chronos-id'] ?? '';
        if (!$nonce1 || !$chronos1) {
            ooredooLog('[OTP] Step3 no nonce/chronos http=' . ($r3['http_code'] ?? '?') . ' proxy=' . ($chkProxy['host'] ?? 'direct'));
            continue;
        }
        ooredooLog('[OTP] Step3 OK nonce=' . substr($nonce1, 0, 12) . ' proxy=' . ($chkProxy['host'] ?? 'direct'));

        // ── 4: POST token بدون OTP — نفس الـ IP كـ checkpoint ──
        $hdrs4 = [
            'User-Agent: Dart/3.11 (dart:io)',
            'Accept-Encoding: gzip',
            "x-msisdn: {$msisdn}",
            'x-device-fingerprint: ' . ooredooNewFingerprint(),
            "x-nonce-id: {$nonce1}",
            'x-platform-origin: ' . OOREDOO_PLATFORM_ORIGIN,
            'platform: ' . OOREDOO_PLATFORM,
            'x-version: ' . OOREDOO_X_VERSION,
            'x-signature: ' . OOREDOO_X_SIG,
            "x-chronos-id: {$chronos1}",
            "x-timestamp: {$ts}",
            "x-platform-info: {$device['plat_sig']}",
            'x-force-update: true',
            "x-instance-id: {$device['instance_id']}",
        ];
        $body4 = http_build_query([
            'client_id'  => OOREDOO_CLIENT_ID,
            'grant_type' => 'password',
            'username'   => $msisdn,
        ]);
        $r4   = ooredooCurlRaw(OOREDOO_AUTH, 'POST', $body4, $hdrs4, 'OO_TOKEN1', 10, $chkProxy);
        if (!$r4 || ($r4['ip_blocked'] ?? false)) {
            ooredooLog('[OTP] Step4 cURL fail proxy=' . ($chkProxy['host'] ?? 'direct'));
            continue;
        }
        $code4 = $r4['http_code'] ?? 0;
        ooredooLog("[OTP] Step4 code={$code4} proxy=" . ($chkProxy['host'] ?? 'direct'));

        // 403 فقط = SMS أُرسل ✅ | 401 = فشل حقيقي
        if ($code4 === 403) {
            return ['proxy' => $chkProxy];
        }
        if ($code4 === 401) {
            ooredooLog('[OTP] Step4 401 Invalid Credentials proxy=' . ($chkProxy['host'] ?? 'direct'));
            continue;
        }
        ooredooLog("[OTP] Step4 unexpected code={$code4} trying next");
    }

    ooredooLog("[OTP] ALL proxies exhausted — failed to send OTP");
    return false;
}

/**
 * ooredooVerifyOTP — الخطوات 6-7:
 * checkpoint2 → token_with_otp → access_token
 * يُعيد ['access_token','refresh_token'] أو 'wrong_otp' أو false
 */
function ooredooVerifyOTP(string $msisdn, string $otp, ?array $stickyProxy): array|string|false
{
    // checkpoint2 + token2 من نفس الـ IP — نستخدم بروكسيات أوريدو المخصصة
    $ooredooProxies = getOoredooProxies();
    shuffle($ooredooProxies);
    // stickyProxy (من Step4) أولاً، ثم باقي بروكسيات أوريدو
    $candidates = [];
    if ($stickyProxy !== null) $candidates[] = $stickyProxy;
    foreach (array_map('parseOoredooProxy', $ooredooProxies) as $pp) {
        if ($stickyProxy === null || ($pp['host'] ?? '') !== ($stickyProxy['host'] ?? '')) {
            $candidates[] = $pp;
        }
    }
    if (empty($candidates)) { ooredooLog("[VERIFY] No proxies available"); return false; }

    // device identity جديدة لجلسة التحقق
    $verifyIid  = ooredooNewInstanceId();
    $verifyPsig = ooredooNewPlatSig($verifyIid);
    $verifyDevice = ['instance_id' => $verifyIid, 'plat_sig' => $verifyPsig];

    foreach ($candidates as $proxy) {
        $ts     = ooredooNowMs();
        $corrId = ooredooNewCorrId();

        // ── 6: POST /checkpoint/token → nonce2+chronos2 ──
        $hdrs6 = ooredooBaseHeaders($msisdn, ooredooNewFingerprint(), $corrId, $ts, $verifyDevice);
        $hdrs6[] = 'content-type: application/json; charset=utf-8';
        $hdrs6[] = 'x-native-integrity-token: ' . OOREDOO_INTEGRITY_2;
        $hdrs6[] = 'x-path: /api/auth/realms/myooredoo/protocol/openid-connect/token';
        $hdrs6[] = 'x-method: POST';
        $r6 = ooredooCurlRaw(OOREDOO_BFF . '/checkpoint/token', 'POST', '{}', $hdrs6, 'OO_CHK2', 12, $proxy);
        if (!$r6 || ($r6['ip_blocked'] ?? false)) {
            ooredooLog("[VERIFY] Step6 fail proxy=" . ($proxy['host'] ?? 'direct'));
            continue;
        }
        $nonce2   = $r6['resp_headers']['x-nonce-id']   ?? '';
        $chronos2 = $r6['resp_headers']['x-chronos-id'] ?? '';
        if (!$nonce2 || !$chronos2) {
            ooredooLog("[VERIFY] Step6 no nonce/chronos http=" . ($r6['http_code'] ?? '?'));
            continue;
        }

        // ── 7: POST token مع OTP — نفس الـ proxy (نفس IP) ──
        $fp7 = ooredooNewFingerprint();
        $hdrs7 = [
            'User-Agent: Dart/3.11 (dart:io)',
            'Accept-Encoding: gzip',
            "x-msisdn: {$msisdn}",
            "x-device-fingerprint: {$fp7}",
            "x-nonce-id: {$nonce2}",
            'x-platform-origin: ' . OOREDOO_PLATFORM_ORIGIN,
            'platform: ' . OOREDOO_PLATFORM,
            'x-version: ' . OOREDOO_X_VERSION,
            'x-signature: ' . OOREDOO_X_SIG,
            "x-chronos-id: {$chronos2}",
            "x-timestamp: {$ts}",
            "x-platform-info: {$verifyDevice['plat_sig']}",
            'x-force-update: true',
            "x-instance-id: {$verifyDevice['instance_id']}",
        ];
        $body7 = http_build_query([
            'client_id'  => OOREDOO_CLIENT_ID,
            'grant_type' => 'password',
            'username'   => $msisdn,
            'otp'        => $otp,
        ]);
        $r7   = ooredooCurlRaw(OOREDOO_AUTH, 'POST', $body7, $hdrs7, 'OO_TOKEN2', 12, $proxy);
        if (!$r7 || ($r7['ip_blocked'] ?? false)) {
            ooredooLog("[VERIFY] Step7 cURL fail proxy=" . ($proxy['host'] ?? 'direct'));
            continue;
        }
        $code7 = $r7['http_code'] ?? 0;
        ooredooLog("[VERIFY] Step7 code={$code7} proxy=" . ($proxy['host'] ?? 'direct'));
        // OTP خاطئ — نوقف مباشرة، لا جدوى من تجربة proxy آخر
        if ($code7 === 403 || $code7 === 401) return 'wrong_otp';
        if ($code7 === 200) {
            $json = $r7['json'] ?? [];
            if (isset($json['access_token'])) {
                // نحفظ القيم الدقيقة التي ولّدت التوكن — Ooredoo يقيّد التوكن بها
                return [
                    'access_token'      => $json['access_token'],
                    'refresh_token'     => $json['refresh_token'] ?? '',
                    // قيم headers الديناميكية التي ولّدت التوكن — محفوظة للطلبات اللاحقة
                    'tok_fingerprint'   => $fp7,
                    'tok_timestamp'     => $ts,
                    'tok_instance_id'   => ooredooExtractJwtDeviceId($json['access_token']) ?? $verifyDevice['instance_id'],
                    'tok_plat_sig'      => ooredooMakePlatSigFromJwt($json['access_token']),
                    'tok_correlation'   => $corrId,
                ];
            }
        }
        ooredooLog("[VERIFY] Step7 unexpected code={$code7}");
        // كود غير متوقع → نجرب proxy آخر
    }

    ooredooLog("[VERIFY] ALL candidates failed for msisdn={$msisdn}");
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// OOREDOO — Gamification: Status + ValidateUser + Play
// ════════════════════════════════════════════════════════════════════════════
function ooredooGamificationStatus(string $msisdn, string $accessToken, ?array $proxy = null, array $tokHdrs = []): ?array
{
    // ══════════════════════════════════════════════════════════════════════
    // الاستراتيجية المُثبَتة من Python: "3-web+full-android-hdrs" → 200 ✅
    // platform=web + x-platform-origin=web + Origin header
    // مع إبقاء باقي headers Android كاملة (msisdn, plat-sig, instance-id)
    // ══════════════════════════════════════════════════════════════════════
    $ts       = $tokHdrs['tok_timestamp'] ?? ooredooNowMs();
    $iid      = $tokHdrs['tok_instance_id'] ?? ooredooNewInstanceId();
    $platSig  = $tokHdrs['tok_plat_sig']   ?? ooredooNewPlatSig($iid);
    $corrId   = ooredooNewCorrId();

    $hdrs = [
        'User-Agent: Dart/3.11 (dart:io)',
        'Accept-Encoding: gzip',
        "x-msisdn: {$msisdn}",
        'x-device-fingerprint: ' . ooredooNewFingerprint(),
        'x-platform-origin: web',                  // ← web (يتجاوز Play Integrity)
        "authorization: Bearer {$accessToken}",
        "x-correlation-id: {$corrId}",
        'platform: web',                           // ← web
        'x-version: ' . OOREDOO_X_VERSION,
        'x-signature: ' . OOREDOO_X_SIG,
        "x-platform-data-signature: {$platSig}",  // ← نُبقي Android plat-sig
        "x-timestamp: {$ts}",
        "x-instance-id: {$iid}",
        'origin: https://my.ooredoo.dz',           // ← Origin يُفعّل web path
        'referer: https://my.ooredoo.dz/',
    ];
    $r = ooredooCurlRaw(OOREDOO_BFF . '/gamification/status', 'GET', '', $hdrs, 'OO_GSTATUS', 12, $proxy);
    if (!$r) return null;
    if ($r['ip_blocked'] ?? false) {
        ooredooLog("[GSTATUS] IP_BLOCKED proxy=" . ($proxy['host'] ?? 'direct'));
        return null;
    }
    $code = $r['http_code'] ?? 0;
    if ($code === 401) {
        ooredooLog("[GSTATUS] 401 proxy=" . ($proxy['host'] ?? 'direct') . " ts={$ts}");
        return null;
    }
    return $r['json'] ?? null;
}

function ooredooValidateUser(string $msisdn, string $accessToken, ?array $proxy = null, array $tokHdrs = []): ?array
{
    $ts      = $tokHdrs['tok_timestamp']   ?? ooredooNowMs();
    $iid     = $tokHdrs['tok_instance_id'] ?? ooredooNewInstanceId();
    $platSig = $tokHdrs['tok_plat_sig']    ?? ooredooNewPlatSig($iid);
    $corrId  = ooredooNewCorrId();

    $hdrs = [
        'User-Agent: Dart/3.11 (dart:io)',
        'Accept-Encoding: gzip',
        "x-msisdn: {$msisdn}",
        'x-device-fingerprint: ' . ooredooNewFingerprint(),
        'x-platform-origin: web',
        "authorization: Bearer {$accessToken}",
        "x-correlation-id: {$corrId}",
        'platform: web',
        'x-version: ' . OOREDOO_X_VERSION,
        'x-signature: ' . OOREDOO_X_SIG,
        "x-platform-data-signature: {$platSig}",
        "x-timestamp: {$ts}",
        "x-instance-id: {$iid}",
        'origin: https://my.ooredoo.dz',
        'referer: https://my.ooredoo.dz/',
    ];
    $url = OOREDOO_BFF . '/users/validateUser?msisdn=' . urlencode($msisdn);
    $r = ooredooCurlRaw($url, 'GET', '', $hdrs, 'OO_VALIDATE', 12, $proxy);
    if (!$r) return null;
    if ($r['ip_blocked'] ?? false) {
        ooredooLog("[VALIDATE] IP_BLOCKED proxy=" . ($proxy['host'] ?? 'direct'));
        return null;
    }
    $code = $r['http_code'] ?? 0;
    if ($code === 401) {
        ooredooLog("[VALIDATE] 401 proxy=" . ($proxy['host'] ?? 'direct') . " ts={$ts}");
        return null;
    }
    return $r['json'] ?? null;
}

function ooredooGamificationPlay(string $msisdn, string $accessToken, ?array $tokenProxy = null, array $tokHdrs = []): ?array
{
    // ─────────────────────────────────────────────────────────────────────
    // CRITICAL: checkpoint و play يجب أن يكونا من نفس الـ IP الذي أصدر التوكن
    // tokenProxy = البروكسي الذي أصدر access_token
    // ─────────────────────────────────────────────────────────────────────
    $ts     = ooredooNowMs();
    $corrId = ooredooNewCorrId();
    $iid    = $tokHdrs['tok_instance_id'] ?? ooredooNewInstanceId();
    $psig   = $tokHdrs['tok_plat_sig']    ?? ooredooNewPlatSig($iid);
    $device = ['instance_id' => $iid, 'plat_sig' => $psig];

    $hdrs3  = ooredooBaseHeaders($msisdn, ooredooNewFingerprint(), $corrId, $ts, $device);
    $hdrs3[] = 'content-type: application/json; charset=utf-8';
    $hdrs3[] = 'x-native-integrity-token: ' . OOREDOO_INTEGRITY_1;
    $hdrs3[] = 'x-path: /api/ooredoo-bff/gamification/play';
    $hdrs3[] = 'x-method: GET';

    // نبدأ بـ tokenProxy ثم نجرب البروكسيات الأخرى إذا فشل
    $ooredooProxies = getOoredooProxies();
    shuffle($ooredooProxies);
    $candidates = [];
    if ($tokenProxy !== null) $candidates[] = $tokenProxy;
    foreach (array_map('parseOoredooProxy', $ooredooProxies) as $pp) {
        if ($tokenProxy === null || ($pp['host'] ?? '') !== ($tokenProxy['host'] ?? '')) {
            $candidates[] = $pp;
        }
    }
    if (empty($candidates)) $candidates[] = null;

    $nonce = ''; $chronos = ''; $usedProxy = null;
    foreach ($candidates as $chkProxy) {
        $r3 = ooredooCurlRaw(OOREDOO_BFF . '/checkpoint/token', 'POST', '{}', $hdrs3, 'OO_GPLAY_CHK', 10, $chkProxy);
        if (!$r3 || ($r3['ip_blocked'] ?? false)) {
            ooredooLog('[PLAY] checkpoint fail proxy=' . ($chkProxy['host'] ?? 'direct'));
            continue;
        }
        $nonce   = $r3['resp_headers']['x-nonce-id']   ?? '';
        $chronos = $r3['resp_headers']['x-chronos-id'] ?? '';
        if ($nonce && $chronos) {
            $usedProxy = $chkProxy;
            ooredooLog('[PLAY] checkpoint OK proxy=' . ($chkProxy['host'] ?? 'direct'));
            break;
        }
        ooredooLog('[PLAY] checkpoint no nonce http=' . ($r3['http_code'] ?? '?') . ' proxy=' . ($chkProxy['host'] ?? 'direct'));
    }
    if (!$nonce || !$chronos) {
        ooredooLog("[PLAY] ALL checkpoints failed");
        return null;
    }

    // play يُرسل من نفس الـ proxy كـ checkpoint — استراتيجية web+full-android-hdrs
    $playTs   = $tokHdrs['tok_timestamp'] ?? $ts;
    $playCorrId = ooredooNewCorrId();
    $hdrsPlay = [
        'User-Agent: Dart/3.11 (dart:io)',
        'Accept-Encoding: gzip',
        "x-msisdn: {$msisdn}",
        "x-nonce-id: {$nonce}",
        'x-device-fingerprint: ' . ooredooNewFingerprint(),
        'x-platform-origin: web',
        "authorization: Bearer {$accessToken}",
        "x-correlation-id: {$playCorrId}",
        'platform: web',
        'x-version: ' . OOREDOO_X_VERSION,
        'x-signature: ' . OOREDOO_X_SIG,
        "x-chronos-id: {$chronos}",
        "x-platform-data-signature: {$device['plat_sig']}",
        "x-timestamp: {$playTs}",
        "x-instance-id: {$device['instance_id']}",
        'origin: https://my.ooredoo.dz',
        'referer: https://my.ooredoo.dz/',
    ];
    $rPlay = ooredooCurlRaw(OOREDOO_BFF . '/gamification/play', 'GET', '', $hdrsPlay, 'OO_GPLAY', 15, $usedProxy);
    if (!$rPlay) { ooredooLog("[PLAY] cURL fail on play request"); return null; }
    if ($rPlay['ip_blocked'] ?? false) { ooredooLog("[PLAY] IP_BLOCKED on play"); return null; }
    $code = $rPlay['http_code'] ?? 0;
    ooredooLog("[PLAY] play response code={$code} proxy=" . ($usedProxy['host'] ?? 'direct'));
    if ($code === 500) return ['already_played' => true];
    if ($code === 200) return array_merge(['already_played' => false], ($rPlay['json'] ?? []));
    if ($code === 401) {
        ooredooLog("[PLAY] 401 — token/IP mismatch on play, tokenProxy=" . ($tokenProxy['host'] ?? 'none') . " usedProxy=" . ($usedProxy['host'] ?? 'direct'));
        return ['auth_error' => true];
    }
    ooredooLog("[PLAY] unexpected code={$code}");
    return null;
}

// ════════════════════════════════════════════════════════════════════════════
// OOREDOO — Messenger Flow Handlers
// ════════════════════════════════════════════════════════════════════════════
function ooredooSaveSession(string $psid, array $data): void
{
    file_put_contents(OOREDOO_SESSIONS_DIR . "/{$psid}.json", json_encode($data));
}
function ooredooGetSession(string $psid): ?array
{
    $f = OOREDOO_SESSIONS_DIR . "/{$psid}.json";
    return file_exists($f) ? json_decode(file_get_contents($f), true) : null;
}
function ooredooClearSession(string $psid): void
{
    $f = OOREDOO_SESSIONS_DIR . "/{$psid}.json";
    if (file_exists($f)) @unlink($f);
    ooredooClearStickyProxy($psid);
}

/**
 * handleNewOoredooPhone — نقطة الدخول عند ارسال رقم 05xxxxxxxx
 */
function handleNewOoredooPhone(string $psid, string $phone): void
{
    $msisdn = '213' . substr($phone, 1);

    // هل عنده حساب مسجّل مسبقاً؟
    $owner = getPhoneOwner($msisdn);
    if ($owner === $psid) {
        $user = getUser($psid);
        if ($user && !empty($user['access_token']) && !empty($user['refresh_token'])) {
            // نجدّد التوكن ونذهب مباشرة للقائمة
            sendMessage($psid, "🔄 جاري التحقق من حسابك أوريدو...");
            $tokenProxyForRefresh = ooredooGetTokenProxy($user);
            $refreshed = ooredooRefreshToken($user['refresh_token'], $msisdn, $tokenProxyForRefresh);
            if (is_array($refreshed)) {
                $user = array_merge($user, $refreshed, ['msisdn' => $msisdn]);
                saveUser($psid, $user);
                setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn, 'operator' => 'ooredoo']);
                sendMessage($psid, "✅ تم التعرف على رقمك أوريدو!");
                ooredooSendPostLoginInfo($psid, $user);
                return;
            }
        }
    }

    sendMessage($psid, "📲 جاري إرسال رمز التحقق إلى {$phone}...");
    $otpRes = ooredooSendOTP($msisdn);
    if ($otpRes === false) {
        sendMessage($psid, "❌ سيرفر أوريدو غير متاح حالياً، حاول مجدداً لاحقاً.\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
        return;
    }
    // حفظ nonce1/chronos1/proxy للخطوة التالية
    ooredooSaveSession($psid, [
        'step'     => 'awaiting_otp',
        'msisdn'   => $msisdn,
        'ts'       => time(),
    ]);
    ooredooSetStickyProxy($psid, $otpRes['proxy'] ?? []);
    // حفظ nonce1/chronos1 في ملف مؤقت — لا نحتاجها لأن الـ verifyOTP يُنشئ checkpoint2 جديد بنفس الـ proxy
    setSession($psid, ['state' => 'awaiting_ooredoo_otp', 'msisdn' => $msisdn, 'operator' => 'ooredoo']);
    sendMessage($psid,
        "✅ تم إرسال رمز التحقق إلى {$phone}.\n\n" .
        "🔢 الرجاء إدخال الرمز المكوّن من 6 أرقام:\n\n" .
        "📱 أو أرسل رقمك مجدداً لاستقبال رمز جديد\n\n" .
        "❌ لإلغاء العملية أرسل: 0"
    );
}

function handleAwaitingOoredooOtp(string $psid, string $text, array $session): void
{
    $msisdn = $session['msisdn'] ?? '';
    $phone  = '0' . substr($msisdn, 3);

    if (trim($text) === '0') {
        clearSession($psid); ooredooClearSession($psid);
        sendMessage($psid, "✅ تم إلغاء العملية.\n\n📱 أرسل رقمك في أي وقت للبدء من جديد.");
        return;
    }
    $digitsNew = preg_replace('/\D/', '', $text);
    // إعادة التوجيه لرقم جيزي
    if (preg_match('/^07\d{8}$/', $digitsNew)) {
        clearSession($psid); ooredooClearSession($psid);
        handleNewPhone($psid, $digitsNew);
        return;
    }
    // إعادة إرسال رمز أوريدو لنفس الرقم أو رقم آخر
    if (preg_match('/^05\d{8}$/', $digitsNew)) {
        $newPhone  = $digitsNew;
        $newMsisdn = '213' . substr($newPhone, 1);
        ooredooClearSession($psid);
        sendMessage($psid, "📲 جاري إعادة إرسال رمز التحقق إلى {$newPhone}...");
        $otpRes = ooredooSendOTP($newMsisdn);
        if ($otpRes === false) {
            sendMessage($psid, "❌ تعذر إرسال الرمز، حاول مجدداً."); return;
        }
        ooredooSetStickyProxy($psid, $otpRes['proxy'] ?? []);
        setSession($psid, ['state' => 'awaiting_ooredoo_otp', 'msisdn' => $newMsisdn, 'operator' => 'ooredoo']);
        ooredooSaveSession($psid, ['step' => 'awaiting_otp', 'msisdn' => $newMsisdn, 'ts' => time()]);
        sendMessage($psid, "✅ تم إرسال رمز التحقق إلى {$newPhone}.\n\n🔢 أدخل الرمز:\n\n❌ للإلغاء أرسل: 0");
        return;
    }
    if (!preg_match('/\b(\d{6})\b/', $text, $m)) {
        sendMessage($psid, "⚠️ الرجاء إدخال رمز التحقق المكوّن من 6 أرقام.\n\n📱 أو أرسل رقمك مجدداً\n\n❌ للإلغاء أرسل: 0");
        return;
    }
    if (empty($msisdn)) { clearSession($psid); sendMessage($psid, "❌ خطأ في الجلسة، أرسل رقمك مجدداً."); return; }

    $stickyProxy = ooredooGetStickyProxy($psid);
    if (!$stickyProxy) {
        // انتهت صلاحية الـ proxy session → نعيد الإرسال
        sendMessage($psid, "⚠️ انتهت صلاحية الجلسة، جاري إعادة إرسال الرمز...");
        $otpRes = ooredooSendOTP($msisdn);
        if ($otpRes === false) { sendMessage($psid, "❌ تعذر إرسال الرمز."); return; }
        ooredooSetStickyProxy($psid, $otpRes['proxy'] ?? []);
        sendMessage($psid, "✅ تم إعادة إرسال الرمز. أدخله مجدداً:");
        return;
    }

    $result = ooredooVerifyOTP($msisdn, $m[1], $stickyProxy);
    if ($result === 'wrong_otp') {
        sendMessage($psid, "❌ الرمز المُدخل خاطئ!\n\n🔄 أعد إرسال الرمز الصحيح\n📱 أو أرسل رقمك مجدداً\n\n❌ للإلغاء أرسل: 0");
        return;
    }
    if ($result === false) {
        sendMessage($psid, "❌ حدث خطأ، حاول مجدداً.\n\n📱 يمكنك إرسال رقمك مجدداً\n\n❌ للإلغاء أرسل: 0");
        return;
    }

    // ✅ نجح — نحفظ التوكن + البروكسي + جميع قيم الـ headers التي ولّدت التوكن
    $tokenProxyHost = $stickyProxy['host'] ?? 'direct';
    $tokenProxyUser = $stickyProxy['userpass'] ?? '';
    $user = [
        'user_id'          => $psid,
        'msisdn'           => $msisdn,
        'operator'         => 'ooredoo',
        'access_token'     => $result['access_token'],
        'refresh_token'    => $result['refresh_token'],
        'token_proxy_host' => $tokenProxyHost,
        'token_proxy_user' => $tokenProxyUser,
        // قيم headers مرتبطة بالتوكن — يجب استخدامها في كل طلب بعده
        'tok_fingerprint'  => $result['tok_fingerprint']  ?? ooredooNewFingerprint(),
        'tok_timestamp'    => $result['tok_timestamp']    ?? ooredooNowMs(),
        'tok_instance_id'  => ooredooExtractJwtDeviceId($result['access_token']) ?? ($result['tok_instance_id'] ?? ooredooNewInstanceId()),
        'tok_plat_sig'     => ooredooMakePlatSigFromJwt($result['access_token']),
        'tok_correlation'  => $result['tok_correlation']  ?? '',
    ];
    saveUser($psid, $user);
    savePhoneOwner($msisdn, $psid);
    setSession($psid, ['state' => 'menu', 'msisdn' => $msisdn, 'operator' => 'ooredoo']);
    ooredooClearSession($psid);
    ooredooLog("[AUTH] success msisdn={$msisdn} token_proxy={$tokenProxyHost}");
    sendMessage($psid, "✅ تم تسجيل الدخول بنجاح! مرحباً بك في أوريدو 🟡");
    ooredooSendPostLoginInfo($psid, $user);
}

/**
 * ooredooSendPostLoginInfo — يرسل نوع الشريحة + حالة الهدية مباشرة بعد تسجيل الدخول
 */
// ════════════════════════════════════════════════════════════════════════════
// OOREDOO — استرجاع الـ proxy الذي أصدر التوكن من بيانات المستخدم
// ════════════════════════════════════════════════════════════════════════════
function ooredooGetTokenProxy(array $user): ?array
{
    $host = $user['token_proxy_host'] ?? 'direct';
    $up   = $user['token_proxy_user'] ?? '';
    if ($host === 'direct' || empty($host)) return null;
    return ['host' => $host, 'userpass' => $up];
}

/**
 * استرجاع قيم الـ headers المرتبطة بالتوكن
 * Ooredoo يقيّد التوكن بالـ timestamp/fingerprint/instance_id الأصلية
 */
function ooredooGetTokenHeaders(array $user): array
{
    return [
        'fingerprint'      => $user['tok_fingerprint'] ?? ooredooNewFingerprint(),
        'tok_timestamp'    => $user['tok_timestamp']   ?? ooredooNowMs(),
        'tok_instance_id'  => $user['tok_instance_id'] ?? ooredooNewInstanceId(),
        'tok_plat_sig'     => $user['tok_plat_sig']    ?? '',
        'tok_correlation'  => $user['tok_correlation'] ?? ooredooNewCorrId(),
    ];
}

function ooredooSendPostLoginInfo(string $psid, array $user): void
{
    $msisdn        = $user['msisdn'];
    $token         = $user['access_token'];
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);

    $tokenProxy = ooredooGetTokenProxy($user);
    $tokHdrs    = ooredooGetTokenHeaders($user);
    $tokHdrs    = ooredooGetTokenHeaders($user);
    ooredooLog("[POST_LOGIN] msisdn={$msisdn} token_proxy=" . ($tokenProxy['host'] ?? 'direct') . " tok_ts=" . ($tokHdrs['tok_timestamp'] ?? '?'));

    $validateData = ooredooValidateUser($msisdn, $token, $tokenProxy, $tokHdrs);
    $giftData     = ooredooGamificationStatus($msisdn, $token, $tokenProxy, $tokHdrs);

    $planType     = $validateData['planType']     ?? '—';
    $contractType = $validateData['contractType'] ?? '—';

    // هل الشريحة YOOZ أو N'YOOZ؟
    $isYooz = in_array(strtoupper(trim($planType)), ['YOOZ', "N'YOOZ", 'NYOOZ'], true);

    // حساب الوقت المتبقي على الهدية
    $giftLine  = '';
    $giftReady = false;
    if ($giftData !== null) {
        $played     = $giftData['played'] ?? false;
        $lastPlayed = $giftData['lastPlayedTime'] ?? null;
        if ($played && $lastPlayed) {
            $lastTs    = strtotime($lastPlayed);
            $remaining = ($lastTs + 86400) - time();
            if ($remaining > 0) {
                $h  = floor($remaining / 3600);
                $mn = floor(($remaining % 3600) / 60);
                $giftLine = "🎁 الهدية اليومية: متاحة بعد {$h}س {$mn}د";
            } else {
                $giftLine  = "🎁 الهدية اليومية: ✅ جاهزة الآن!";
                $giftReady = true;
            }
        } elseif (!$played) {
            $giftLine  = "🎁 الهدية اليومية: ✅ جاهزة الآن!";
            $giftReady = true;
        }
    }

    $giftStatusLine = $giftLine ? "\n{$giftLine}\n" : '';

    $text  = "📱 معلومات شريحتك أوريدو\n\n";
    $text .= "🔢 الرقم: {$displayMasked}\n";
    $text .= "📦 نوع الشريحة: {$planType}\n";
    $text .= "📋 نوع العقد: {$contractType}\n";
    $text .= $giftStatusLine;
    $text .= "\n━━━━━━━━━━━━━━\n\n";
    $text .= "📌 اختر ما تريد من الأزرار أو أرسل رقم الخيار:\n\n";
    $text .= "1️⃣ تفعيل الهدية اليومية" . ($giftLine ? "\n({$giftLine})" : '') . "\n📩 أرسل: 1\n\n";
    $text .= "2️⃣ تفعيل عروض\n📩 أرسل: 2\n\n";
    if ($isYooz) {
        $text .= "3️⃣ تفعيل سناب شات (يوز)\n📩 أرسل: 3\n\n";
    }
    $text .= "4️⃣ معلوماتي واشتراكاتي\n📩 أرسل: 4\n";
    $text .= "━━━━━━━━━━━━━━";

    $btnGift = $giftReady ? '🎁 تفعيل الهدية اليومية' : '⏳ الهدية غير متاحة بعد';

    $quickReplies = [
        ['content_type' => 'text', 'title' => $btnGift,         'payload' => 'OOREDOO_DAILY_GIFT'],
        ['content_type' => 'text', 'title' => '📦 تفعيل عروض', 'payload' => 'OOREDOO_OFFERS_MENU'],
        ['content_type' => 'text', 'title' => '📊 معلوماتي',   'payload' => 'OOREDOO_MY_INFO'],
    ];
    if ($isYooz) {
        $quickReplies[] = ['content_type' => 'text', 'title' => '👻 سناب شات (يوز)', 'payload' => 'OOREDOO_SNAPCHAT'];
    }

    fbApiCall(json_encode([
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => [
            'text'          => $text,
            'quick_replies' => $quickReplies,
        ],
    ], JSON_UNESCAPED_UNICODE));
}

// ════════════════════════════════════════════════════════════════════════════
// OOREDOO — عروض الإنترنت (5-19)
// ════════════════════════════════════════════════════════════════════════════

/**
 * قائمة عروض أوريدو — indexed 5..19 تطابق spec المُقدَّم
 */
function getOoredooOffersList(): array
{
    return [
        5  => ['label' => '5GB 🔥',             'price' => '90',   'duration' => '24 ساعة',  'type' => 'dynamic', 'bundle_code' => 'ATL_24H_DATA_90DZD_5Go'],
        6  => ['label' => '10GB 🔥',            'price' => '190',  'duration' => '3 أيام',   'type' => 'dynamic', 'bundle_code' => 'ATL_72H_DATA_190DZD_10Go'],
        7  => ['label' => '30GB 🔥',            'price' => '490',  'duration' => '7 أيام',   'type' => 'dynamic', 'bundle_code' => 'ATL_7D_DATA_490DZD_30Go'],
        8  => ['label' => '30GB 🌐',            'price' => '950',  'duration' => 'شهر',      'type' => 'byop',    'payload' => ['validity' => 'Monthly',    'limitedBundleDetails' => [['account' => 'data', 'allocation' => 30]], 'unlimitedBundleDetails' => []]],
        9  => ['label' => '50GB 🌐',            'price' => '1250', 'duration' => 'شهر',      'type' => 'byop',    'payload' => ['validity' => 'Monthly',    'limitedBundleDetails' => [['account' => 'data', 'allocation' => 50]], 'unlimitedBundleDetails' => []]],
        10 => ['label' => '60GB 🌐',            'price' => '1350', 'duration' => 'شهر',      'type' => 'byop',    'payload' => ['validity' => 'Monthly',    'limitedBundleDetails' => [['account' => 'data', 'allocation' => 60]], 'unlimitedBundleDetails' => []]],
        11 => ['label' => '70GB 🌐',            'price' => '1475', 'duration' => 'شهر',      'type' => 'byop',    'payload' => ['validity' => 'Monthly',    'limitedBundleDetails' => [['account' => 'data', 'allocation' => 70]], 'unlimitedBundleDetails' => []]],
        12 => ['label' => 'WhatsApp 💬',        'price' => '50',   'duration' => '15 يوم',   'type' => 'byop',    'payload' => ['validity' => 'Biweekly',   'limitedBundleDetails' => [], 'unlimitedBundleDetails' => ['WA']]],
        13 => ['label' => 'Facebook 📘 أسبوع',  'price' => '80',   'duration' => 'أسبوع',    'type' => 'byop',    'payload' => ['validity' => 'Weekly',     'limitedBundleDetails' => [], 'unlimitedBundleDetails' => ['FB']]],
        14 => ['label' => 'Facebook 📘 15يوم', 'price' => '100',  'duration' => '15 يوم',   'type' => 'byop',    'payload' => ['validity' => 'Biweekly',   'limitedBundleDetails' => [], 'unlimitedBundleDetails' => ['FB']]],
        15 => ['label' => 'Instagram 📸 15يوم','price' => '100',  'duration' => '15 يوم',   'type' => 'byop',    'payload' => ['validity' => 'Biweekly',   'limitedBundleDetails' => [], 'unlimitedBundleDetails' => ['IG']]],
        16 => ['label' => 'YouTube ▶️ 15يوم',  'price' => '100',  'duration' => '15 يوم',   'type' => 'byop',    'payload' => ['validity' => 'Biweekly',   'limitedBundleDetails' => [], 'unlimitedBundleDetails' => ['YT']]],
        17 => ['label' => 'Facebook 📘 شهر',   'price' => '200',  'duration' => 'شهر',      'type' => 'byop',    'payload' => ['validity' => 'Monthly',    'limitedBundleDetails' => [], 'unlimitedBundleDetails' => ['FB']]],
        18 => ['label' => 'Instagram 📸 شهر',  'price' => '200',  'duration' => 'شهر',      'type' => 'byop',    'payload' => ['validity' => 'Monthly',    'limitedBundleDetails' => [], 'unlimitedBundleDetails' => ['IG']]],
        19 => ['label' => 'YouTube ▶️ شهر',    'price' => '200',  'duration' => 'شهر',      'type' => 'byop',    'payload' => ['validity' => 'Monthly',    'limitedBundleDetails' => [], 'unlimitedBundleDetails' => ['YT']]],
    ];
}

/**
 * sendOoredooOffersMenu — قائمة العروض مع أزرار
 */
function sendOoredooOffersMenu(string $psid): void
{
    $offers = getOoredooOffersList();

    $text  = "📦 قائمة عروض الإنترنت المتوفرة 📦\n\n";
    $text .= "━━━━━━━━━━━ 📅 العروض اليومية ━━━━━━━━━━━\n\n";
    $text .= "5️⃣ 5GB 🔥\n🌐 الإنترنت: 5GB\n💰 السعر: 90 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 5\n\n";
    $text .= "6️⃣ 10GB 🔥\n🌐 الإنترنت: 10GB\n💰 السعر: 190 دج\n⏳ المدة: 3 أيام\n📩 للتفعيل أرسل: 6\n\n";
    $text .= "━━━━━━━━━━━ 📆 العروض الأسبوعية ━━━━━━━━━━━\n\n";
    $text .= "7️⃣ 30GB 🔥\n🌐 الإنترنت: 30GB\n💰 السعر: 490 دج\n⏳ المدة: 7 أيام\n📩 للتفعيل أرسل: 7\n\n";
    $text .= "━━━━━━━━━━━ 🗓️ العروض الشهرية ━━━━━━━━━━━\n\n";
    $text .= "8️⃣ 30GB 🌐\n🌐 الإنترنت: 30GB\n💰 السعر: 950 دج\n⏳ المدة: شهر\n📩 للتفعيل أرسل: 8\n\n";
    $text .= "9️⃣ 50GB 🌐\n🌐 الإنترنت: 50GB\n💰 السعر: 1250 دج\n⏳ المدة: شهر\n📩 للتفعيل أرسل: 9\n\n";
    $text .= "🔟 60GB 🌐\n🌐 الإنترنت: 60GB\n💰 السعر: 1350 دج\n⏳ المدة: شهر\n📩 للتفعيل أرسل: 10\n\n";
    $text .= "1️⃣1️⃣ 70GB 🌐\n🌐 الإنترنت: 70GB\n💰 السعر: 1475 دج\n⏳ المدة: شهر\n📩 للتفعيل أرسل: 11\n\n";
    $text .= "━━━━━━━━━━━ 🏷️ العروض الخاصة ━━━━━━━━━━━\n\n";
    $text .= "1️⃣2️⃣ WhatsApp 💬\n💰 السعر: 50 دج\n⏳ المدة: 15 يوم\n📩 للتفعيل أرسل: 12\n\n";
    $text .= "1️⃣3️⃣ Facebook 📘 أسبوع\n💰 السعر: 80 دج\n⏳ المدة: أسبوع\n📩 للتفعيل أرسل: 13\n\n";
    $text .= "1️⃣4️⃣ Facebook 📘 15يوم\n💰 السعر: 100 دج\n⏳ المدة: 15 يوم\n📩 للتفعيل أرسل: 14\n\n";
    $text .= "1️⃣5️⃣ Instagram 📸 15يوم\n💰 السعر: 100 دج\n⏳ المدة: 15 يوم\n📩 للتفعيل أرسل: 15\n\n";
    $text .= "1️⃣6️⃣ YouTube ▶️ 15يوم\n💰 السعر: 100 دج\n⏳ المدة: 15 يوم\n📩 للتفعيل أرسل: 16\n\n";
    $text .= "1️⃣7️⃣ Facebook 📘 شهر\n💰 السعر: 200 دج\n⏳ المدة: شهر\n📩 للتفعيل أرسل: 17\n\n";
    $text .= "1️⃣8️⃣ Instagram 📸 شهر\n💰 السعر: 200 دج\n⏳ المدة: شهر\n📩 للتفعيل أرسل: 18\n\n";
    $text .= "1️⃣9️⃣ YouTube ▶️ شهر\n💰 السعر: 200 دج\n⏳ المدة: شهر\n📩 للتفعيل أرسل: 19\n\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━━\n📨 أرسل رقم العرض فقط للتفعيل المباشر";

    $qr = [
        ['content_type' => 'text', 'title' => '5 - 5GB 90دج',     'payload' => 'OOREDOO_OFFER_5'],
        ['content_type' => 'text', 'title' => '6 - 10GB 190دج',   'payload' => 'OOREDOO_OFFER_6'],
        ['content_type' => 'text', 'title' => '7 - 30GB 490دج',   'payload' => 'OOREDOO_OFFER_7'],
        ['content_type' => 'text', 'title' => '8 - 30GB 950دج',   'payload' => 'OOREDOO_OFFER_8'],
        ['content_type' => 'text', 'title' => '9 - 50GB 1250دج',  'payload' => 'OOREDOO_OFFER_9'],
        ['content_type' => 'text', 'title' => '10 - 60GB 1350دج', 'payload' => 'OOREDOO_OFFER_10'],
        ['content_type' => 'text', 'title' => '11 - 70GB 1475دج', 'payload' => 'OOREDOO_OFFER_11'],
        ['content_type' => 'text', 'title' => '12 - WA 50دج',     'payload' => 'OOREDOO_OFFER_12'],
        ['content_type' => 'text', 'title' => '13 - FB أسبوع',    'payload' => 'OOREDOO_OFFER_13'],
        ['content_type' => 'text', 'title' => '🔙 رجوع',          'payload' => 'OOREDOO_BACK_MENU'],
    ];

    fbApiCall(json_encode([
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => ['text' => $text, 'quick_replies' => $qr],
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * ooredooAuthedHeaders — رؤوس ثابتة لطلبات ما بعد المصادقة
 * نفس القيم بالضبط كما في الطلبات الموجودة
 */
/**
 * ooredooAuthedHeaders — رؤوس ديناميكية لطلبات ما بعد المصادقة
 * $tokHdrs: قيم من ooredooGetTokenHeaders (tok_timestamp, tok_instance_id, tok_plat_sig)
 */
function ooredooAuthedHeaders(string $msisdn, string $accessToken, string $contentType = '', array $tokHdrs = []): array
{
    $ts      = $tokHdrs['tok_timestamp']   ?? ooredooNowMs();
    $iid     = $tokHdrs['tok_instance_id'] ?? ooredooNewInstanceId();
    $platSig = $tokHdrs['tok_plat_sig']    ?? ooredooNewPlatSig($iid);
    $corrId  = ooredooNewCorrId();
    $hdrs = [
        'User-Agent: Dart/3.11 (dart:io)',
        'Accept-Encoding: gzip',
        "x-msisdn: {$msisdn}",
        'x-device-fingerprint: ' . ooredooNewFingerprint(),
        'x-platform-origin: web',
        "authorization: Bearer {$accessToken}",
        "x-correlation-id: {$corrId}",
        'platform: web',
        'x-version: ' . OOREDOO_X_VERSION,
        'x-signature: ' . OOREDOO_X_SIG,
        "x-platform-data-signature: {$platSig}",
        "x-timestamp: {$ts}",
        "x-instance-id: {$iid}",
        'origin: https://my.ooredoo.dz',
        'referer: https://my.ooredoo.dz/',
    ];
    if ($contentType !== '') {
        $hdrs[] = "content-type: {$contentType}";
    }
    return $hdrs;
}

/**
 * activateOoredooOffer — تفعيل عرض (dynamic bundle أو byop)
 */
function activateOoredooOffer(string $psid, array $user, int $offerNum): void
{
    $offers = getOoredooOffersList();
    if (!isset($offers[$offerNum])) {
        sendMessage($psid, "❌ عرض غير معروف."); return;
    }
    $offer  = $offers[$offerNum];
    $msisdn = $user['msisdn'];
    $token  = $user['access_token'];
    $tokenProxy = ooredooGetTokenProxy($user);
    $tokHdrs    = ooredooGetTokenHeaders($user);
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);

    sendMessage($psid, "🔄 جاري تفعيل {$offer['label']}...");

    if ($offer['type'] === 'dynamic') {
        // /cvm/dynamic-bundle-purchase
        $url     = OOREDOO_BFF . '/cvm/dynamic-bundle-purchase';
        $body    = json_encode(['bundleCode' => $offer['bundle_code']]);
        $hdrs    = ooredooAuthedHeaders($msisdn, $token, 'application/json; charset=utf-8', $tokHdrs);

        $res = ooredooCurlRaw($url, 'POST', $body, $hdrs, 'OO_DYN_OFFER_' . $offerNum, 15, $tokenProxy);
        if (!$res || ($res['ip_blocked'] ?? false)) {
            sendMessage($psid, "❌ تعذر الاتصال بالسيرفر، حاول مجدداً."); return;
        }
        $code = $res['http_code'] ?? 0;
        $json = $res['json'] ?? [];
        ooredooLog("[DYN_OFFER_{$offerNum}] msisdn={$msisdn} code={$code}");

        if ($code === 200) {
            sendMessage($psid, "✅ تم تفعيل {$offer['label']} بنجاح!\n\n🌐 المدة: {$offer['duration']}\n💰 السعر: {$offer['price']} دج\n📱 الرقم: {$displayMasked}\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
            return;
        }
        if ($code === 409) {
            $errCode = $json['code'] ?? '';
            if ($errCode === 'DYNAMIC_CREDIT_LIMIT_NOT_ENOUGH') {
                sendMessage($psid, "⚠️ رصيدك غير كافٍ لتفعيل هذا العرض.\n\n💰 السعر المطلوب: {$offer['price']} دج\n\n⚡ قناة التلقرام: https://t.me/tasjilbott"); return;
            }
            sendMessage($psid, "⚠️ عذراً، العرض مفعّل مسبقاً أو غير متاح الآن.\n\n⚡ قناة التلقرام: https://t.me/tasjilbott"); return;
        }
        sendMessage($psid, "❌ حدث خطأ في السيرفر (HTTP {$code}). حاول مجدداً.\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
        return;
    }

    // byop — /bundle/purchase/byop
    if ($offer['type'] === 'byop') {
        $url  = OOREDOO_BFF . '/bundle/purchase/byop';
        $body = json_encode($offer['payload']);
        $hdrs = ooredooAuthedHeaders($msisdn, $token, 'application/json; charset=utf-8', $tokHdrs);

        $res = ooredooCurlRaw($url, 'POST', $body, $hdrs, 'OO_BYOP_OFFER_' . $offerNum, 15, $tokenProxy);
        if (!$res || ($res['ip_blocked'] ?? false)) {
            sendMessage($psid, "❌ تعذر الاتصال بالسيرفر، حاول مجدداً."); return;
        }
        $code = $res['http_code'] ?? 0;
        $json = $res['json'] ?? [];
        ooredooLog("[BYOP_OFFER_{$offerNum}] msisdn={$msisdn} code={$code}");

        if ($code === 200) {
            sendMessage($psid, "✅ تم تفعيل {$offer['label']} بنجاح!\n\n🌐 المدة: {$offer['duration']}\n💰 السعر: {$offer['price']} دج\n📱 الرقم: {$displayMasked}\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
            return;
        }
        if ($code === 409) {
            $msg = $json['message'] ?? ($json['success'] === false ? ($json['message'] ?? '') : '');
            if (stripos($msg, 'CREDIT_LIMIT') !== false || stripos($msg, 'Credit Balance') !== false) {
                sendMessage($psid, "⚠️ رصيدك غير كافٍ لتفعيل هذا العرض.\n\n💰 السعر المطلوب: {$offer['price']} دج\n\n⚡ قناة التلقرام: https://t.me/tasjilbott"); return;
            }
            sendMessage($psid, "⚠️ عذراً، العرض مفعّل مسبقاً أو غير متاح الآن.\n\n⚡ قناة التلقرام: https://t.me/tasjilbott"); return;
        }
        sendMessage($psid, "❌ حدث خطأ في السيرفر (HTTP {$code}). حاول مجدداً.\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
    }
}

/**
 * activateOoredooSnapchat — تفعيل سناب شات (YOOZ/N'YOOZ فقط)
 */
function activateOoredooSnapchat(string $psid, array $user): void
{
    $msisdn        = $user['msisdn'];
    $token         = $user['access_token'];
    $tokenProxy    = ooredooGetTokenProxy($user);
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);

    sendMessage($psid, "🔄 جاري تفعيل سناب شات (يوز)...");

    $url  = OOREDOO_BFF . '/snap-chat/apply';
    $hdrs = ooredooAuthedHeaders($msisdn, $token, '', $tokHdrs);
    // طلب POST بدون body — كما في النموذج الأصلي
    $res  = ooredooCurlRaw($url, 'POST', '', $hdrs, 'OO_SNAPCHAT', 15, $tokenProxy);

    if (!$res || ($res['ip_blocked'] ?? false)) {
        sendMessage($psid, "❌ تعذر الاتصال بالسيرفر، حاول مجدداً."); return;
    }
    $code = $res['http_code'] ?? 0;
    ooredooLog("[SNAPCHAT] msisdn={$msisdn} code={$code}");

    if ($code === 200) {
        sendMessage($psid, "✅ تم تفعيل سناب شات بنجاح!\n\n👻 استمتع بسناب شات\n📱 الرقم: {$displayMasked}\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
        return;
    }
    if ($code === 500) {
        // 500 = العرض مفعّل مسبقاً ولم تمر 24 ساعة
        sendMessage($psid, "⚠️ سناب شات مفعّل مسبقاً، لم تمر 24 ساعة بعد.\n\nحاول مجدداً غداً 🕐\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
        return;
    }
    sendMessage($psid, "❌ حدث خطأ (HTTP {$code}). حاول مجدداً.\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
}

/**
 * sendOoredooMyInfo — معلومات المستخدم + الاشتراكات النشطة
 */
function sendOoredooMyInfo(string $psid, array $user): void
{
    $msisdn        = $user['msisdn'];
    $token         = $user['access_token'];
    $tokenProxy    = ooredooGetTokenProxy($user);
    $tokHdrs       = ooredooGetTokenHeaders($user);
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);

    sendMessage($psid, "🔄 جاري تحميل معلوماتك...");

    // معلومات المستخدم
    $validateData = ooredooValidateUser($msisdn, $token, $tokenProxy, $tokHdrs);
    $planType     = $validateData['planType']     ?? '—';
    $contractType = $validateData['contractType'] ?? '—';

    // getActivePackages
    $url  = OOREDOO_BFF . '/bundle/getActivePackages?msisdn=' . urlencode($msisdn);
    $hdrs = ooredooAuthedHeaders($msisdn, $token, '', $tokHdrs);
    $res  = ooredooCurlRaw($url, 'GET', '', $hdrs, 'OO_PACKAGES', 15, $tokenProxy);

    $balance = '—';
    $subs    = [];

    if ($res && !($res['ip_blocked'] ?? false) && ($res['http_code'] ?? 0) === 200) {
        $json    = $res['json'] ?? [];
        $balance = $json['accountBalance'] ?? '—';

        // استخراج الاشتراكات النشطة (القيم غير الفارغة)
        // bundleInstances
        if (!empty($json['bundleInstances'])) {
            foreach ($json['bundleInstances'] as $b) {
                $name = $b['bundleName'] ?? ($b['bundleCode'] ?? '');
                $exp  = $b['expirationDate'] ?? '';
                if ($name) $subs[] = "📦 {$name}" . ($exp ? " (ينتهي: {$exp})" : '');
            }
        }
        // dynamicBundlesPackage
        $dynBundle = $json['dynamicBundlesPackage']['dynamicBundle'] ?? null;
        if ($dynBundle) {
            $name = $dynBundle['bundleName'] ?? ($dynBundle['bundleCode'] ?? '');
            $exp  = $dynBundle['expirationDate'] ?? '';
            if ($name) $subs[] = "📡 {$name}" . ($exp ? " (ينتهي: {$exp})" : '');
        }
        // byobBundlesPackage
        $byobBundle = $json['byobBundlesPackage']['dynamicBundle'] ?? null;
        if ($byobBundle) {
            $name = $byobBundle['bundleName'] ?? ($byobBundle['bundleCode'] ?? '');
            $exp  = $byobBundle['expirationDate'] ?? '';
            if ($name) $subs[] = "📡 {$name}" . ($exp ? " (ينتهي: {$exp})" : '');
        }
        // dailyBundlePurchases
        $dailyBundles = $json['dailyBundlePurchases']['dailyBundles'] ?? [];
        foreach ($dailyBundles as $b) {
            $name = $b['bundleName'] ?? ($b['bundleCode'] ?? '');
            $exp  = $b['expirationDate'] ?? '';
            if ($name) $subs[] = "📅 {$name}" . ($exp ? " (ينتهي: {$exp})" : '');
        }
        // monthlyDataSmartBundlePurchases
        foreach (['dataBundles', 'smartBundles'] as $key) {
            $list = $json['monthlyDataSmartBundlePurchases'][$key] ?? [];
            foreach ($list as $b) {
                $name = $b['bundleName'] ?? ($b['bundleCode'] ?? '');
                $exp  = $b['expirationDate'] ?? '';
                if ($name) $subs[] = "🗓️ {$name}" . ($exp ? " (ينتهي: {$exp})" : '');
            }
        }
        // weeklyBundlePurchases
        $weeklyBundles = $json['weeklyBundlePurchases']['dataBundles'] ?? [];
        foreach ($weeklyBundles as $b) {
            $name = $b['bundleName'] ?? ($b['bundleCode'] ?? '');
            $exp  = $b['expirationDate'] ?? '';
            if ($name) $subs[] = "📆 {$name}" . ($exp ? " (ينتهي: {$exp})" : '');
        }
        // snapchatAllocation
        $snapName = $json['snapchatAllocation']['allocationName'] ?? null;
        if ($snapName) $subs[] = "👻 سناب شات: {$snapName}";
        // bonusAllocations
        if (!empty($json['bonusAllocations'])) {
            foreach ($json['bonusAllocations'] as $b) {
                $name = $b['name'] ?? '';
                $val  = $b['value'] ?? '';
                if ($name) $subs[] = "🎁 {$name}" . ($val ? ": {$val}" : '');
            }
        }
        // noudjoumAllocation
        $nouj = $json['noudjoumAllocation'] ?? [];
        foreach ($nouj as $k => $v) {
            if ($v !== null && $v !== '') $subs[] = "⭐ نجوم {$k}: {$v}";
        }
        // remainigDataAllocation
        $remData = $json['remainigDataAllocation'] ?? null;
        if ($remData !== null && $remData !== '') $subs[] = "📶 الإنترنت المتبقي: {$remData}";
    }

    $text  = "📱 معلوماتي — أوريدو\n\n";
    $text .= "🔢 الرقم: {$displayMasked}\n";
    $text .= "📦 نوع الشريحة: {$planType}\n";
    $text .= "📋 نوع العقد: {$contractType}\n";
    $text .= "💳 الرصيد: {$balance} دج\n\n";
    $text .= "━━━━━━━━━━━━━━\n\n";

    if (empty($subs)) {
        $text .= "📭 لا توجد اشتراكات نشطة حالياً\n";
    } else {
        $text .= "📋 الاشتراكات النشطة:\n\n";
        foreach ($subs as $s) {
            $text .= "• {$s}\n";
        }
    }
    $text .= "\n━━━━━━━━━━━━━━\n⚡ قناة التلقرام: https://t.me/tasjilbott";

    fbApiCall(json_encode([
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => [
            'text'          => $text,
            'quick_replies' => [
                ['content_type' => 'text', 'title' => '🔙 رجوع للقائمة', 'payload' => 'OOREDOO_BACK_MENU'],
                ['content_type' => 'text', 'title' => '📦 تفعيل عروض',   'payload' => 'OOREDOO_OFFERS_MENU'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * activateOoredooDailyGift — تفعيل الهدية اليومية مع التحقق المسبق
 */
function activateOoredooDailyGift(string $psid, array $user): void
{
    $msisdn = $user['msisdn'];
    $token  = $user['access_token'];

    $tokenProxy = ooredooGetTokenProxy($user);
    $tokHdrs    = ooredooGetTokenHeaders($user);
    $tokHdrs    = ooredooGetTokenHeaders($user);
    ooredooLog("[ACTIVATE] msisdn={$msisdn} token_proxy=" . ($tokenProxy['host'] ?? 'direct') . " tok_ts=" . ($tokHdrs['tok_timestamp'] ?? '?'));
    $giftData = ooredooGamificationStatus($msisdn, $token, $tokenProxy, $tokHdrs);
    if ($giftData !== null) {
        $played     = $giftData['played'] ?? false;
        $lastPlayed = $giftData['lastPlayedTime'] ?? null;
        if ($played && $lastPlayed) {
            $lastTs    = strtotime($lastPlayed);
            $remaining = ($lastTs + 86400) - time();
            if ($remaining > 0) {
                $h  = floor($remaining / 3600);
                $mn = floor(($remaining % 3600) / 60);
                sendMessage($psid,
                    "⏳ لقد لعبت الهدية اليومية مسبقاً.\n\n" .
                    "⏰ الوقت المتبقي: {$h} ساعة و {$mn} دقيقة\n\n" .
                    "⚡ قناة التلقرام: https://t.me/tasjilbott"
                );
                return;
            }
        }
    }

    sendMessage($psid, "🎲 جاري تفعيل الهدية اليومية...");
    $result = ooredooGamificationPlay($msisdn, $token, $tokenProxy, $tokHdrs);

    if ($result === null) {
        sendMessage($psid, "❌ حدث خطأ أثناء تفعيل الهدية، حاول مجدداً.\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
        return;
    }
    if ($result['auth_error'] ?? false) {
        sendMessage($psid, "🔄 جاري تجديد جلسة أوريدو...");
        $refreshed = ooredooRefreshToken($user['refresh_token'] ?? '', $msisdn, $tokenProxy);
        if (is_array($refreshed)) {
            $user = array_merge($user, $refreshed);
            saveUser($psid, $user);
            sendMessage($psid, "⚠️ تم تجديد الجلسة. أعد إرسال رقم 1 لتفعيل الهدية.");
        } else {
            sendMessage($psid, "❌ انتهت الجلسة. أرسل رقمك مجدداً لتسجيل الدخول.");
            clearSession($psid);
        }
        return;
    }
    if ($result['already_played'] ?? false) {
        // 500 من السيرفر = لعبت → نعيد الفحص
        $giftData2 = ooredooGamificationStatus($msisdn, $token, $tokenProxy, $tokHdrs);
        $lastPlayed = $giftData2['lastPlayedTime'] ?? null;
        if ($lastPlayed) {
            $remaining = (strtotime($lastPlayed) + 86400) - time();
            $h  = max(0, floor($remaining / 3600));
            $mn = max(0, floor(($remaining % 3600) / 60));
            sendMessage($psid, "⚠️ لقد لعبت الهدية اليومية مسبقاً.\n\n⏰ الوقت المتبقي: {$h} ساعة و {$mn} دقيقة\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
        } else {
            sendMessage($psid, "⚠️ لقد لعبت الهدية اليومية مسبقاً.\n\n⚡ قناة التلقرام: https://t.me/tasjilbott");
        }
        return;
    }

    $giftName    = $result['giftName']    ?? 'هدية';
    $validityHour = $result['validityHour'] ?? '';
    $playedTime  = $result['playedTime']  ?? '';
    $displayMasked = substr($msisdn, 0, 4) . 'xxxx' . substr($msisdn, -2);

    recordFinalResult($psid);
    sendMessage($psid,
        "🎉 تهانيك! ربحت هديتك اليومية!\n\n" .
        "🎁 الهدية: {$giftName}\n" .
        ($validityHour ? "⏳ الصلاحية: {$validityHour} ساعة\n" : '') .
        "📱 الرقم: {$displayMasked}\n\n" .
        "⚡ قناة التلقرام: https://t.me/tasjilbott"
    );
}

/**
 * ooredooRefreshToken — تجديد التوكن
 */
function ooredooRefreshToken(string $refreshToken, string $msisdn, ?array $tokenProxy = null): array|false
{
    // Refresh يحتاج نفس بروكسي التوكن الأصلي
    $ts      = ooredooNowMs();
    $freshIid  = ooredooNewInstanceId();
    $freshPsig = ooredooNewPlatSig($freshIid);
    $hdrs = [
        'User-Agent: Dart/3.11 (dart:io)',
        'Accept-Encoding: gzip',
        'Content-Type: application/x-www-form-urlencoded',
        "x-msisdn: {$msisdn}",
        'x-platform-origin: mobile-android',
        'platform: android',
        'x-version: ' . OOREDOO_X_VERSION,
        'x-signature: ' . OOREDOO_X_SIG,
        "x-platform-data-signature: {$freshPsig}",
        "x-timestamp: {$ts}",
        "x-instance-id: {$freshIid}",
    ];
    $body = http_build_query([
        'client_id'     => OOREDOO_CLIENT_ID,
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
    ]);

    // نجرب بروكسيات أوريدو المخصصة للـ refresh
    $ooredooProxies = getOoredooProxies();
    shuffle($ooredooProxies);
    $candidates = [];
    if ($tokenProxy !== null) $candidates[] = $tokenProxy;
    foreach (array_map('parseOoredooProxy', $ooredooProxies) as $pp) {
        if ($tokenProxy === null || ($pp['host'] ?? '') !== ($tokenProxy['host'] ?? '')) {
            $candidates[] = $pp;
        }
    }
    $candidates[] = null; // direct كآخر محاولة

    foreach ($candidates as $proxy) {
        $r = ooredooCurlRaw(OOREDOO_AUTH, 'POST', $body, $hdrs, 'OO_REFRESH', 12, $proxy);
        if (!$r || ($r['ip_blocked'] ?? false)) {
            ooredooLog('[REFRESH] fail proxy=' . ($proxy['host'] ?? 'direct'));
            continue;
        }
        $code = $r['http_code'] ?? 0;
        $json = $r['json'] ?? [];
        ooredooLog("[REFRESH] http={$code} proxy=" . ($proxy['host'] ?? 'direct'));
        if ($code === 200 && isset($json['access_token'])) {
            // التوكن الجديد = headers جديدة مرتبطة بنفس البروكسي الذي نجح
            return [
                'access_token'      => $json['access_token'],
                'refresh_token'     => $json['refresh_token'] ?? $refreshToken,
                'token_proxy_host'  => $proxy['host'] ?? 'direct',
                'token_proxy_user'  => $proxy['userpass'] ?? '',
                'tok_fingerprint'   => ooredooNewFingerprint(),
                'tok_timestamp'     => $ts,
                'tok_instance_id'   => ooredooExtractJwtDeviceId($json['access_token']) ?? $freshIid,
                'tok_plat_sig'      => ooredooMakePlatSigFromJwt($json['access_token']),
                'tok_correlation'   => ooredooNewCorrId(),
            ];
        }
    }
    return false;
}

// ════════════════════════════════════════════════════════════════════════════
// djezzyCurl — مشترك
// ════════════════════════════════════════════════════════════════════════════
function djezzyCurl(string $url, string $data, string $ph, string $pa, string $tag): mixed
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: */*', 'User-Agent: Dalvik/2.1.0 (Linux; U; Android 6.0; PGN610 Build/MRA58K)', 'Connection: Keep-Alive', 'Accept-Encoding: gzip'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_PROXY          => $ph,
        CURLOPT_PROXYUSERPWD   => $pa,
        CURLOPT_PROXYTYPE      => CURLPROXY_HTTP,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    file_put_contents('/tmp/djezzy.log', date('Y-m-d H:i:s') . " [$tag] CODE:$code ERR:$err BODY:" . substr((string)$body, 0, 400) . "\n", FILE_APPEND);
    if ($err || $body === false) return false;
    if (stripos((string)$body, '<!DOCTYPE') !== false || stripos((string)$body, '<html') !== false) return 'html';
    if (str_starts_with($tag, 'otp')) return ($code >= 200 && $code < 300) ? true : false;
    return ['code' => $code, 'body' => (string)$body];
}

// ════════════════════════════════════════════════════════════════════════════

// djezzyOtpCurl — طلب OTP بالـ endpoint والـ headers الجديدة
function djezzyOtpCurl(string $msisdn, string $clientId, string $ph, string $pa): bool
{
    $url    = "https://apim.djezzy.dz/mobile-api/oauth2/registration";
    $params = http_build_query(["msisdn" => $msisdn, "client_id" => $clientId, "scope" => "smsotp"]);
    $body   = json_encode(["consent-agreement" => [["marketing-notifications" => false]], "is-consent" => true]);
    $ch     = curl_init($url . "?" . $params);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Accept: application/json", "Accept-Encoding: gzip", "User-Agent: MobileApp/3.0.9", "accept-language: fr"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => "gzip",
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_PROXY          => $ph,
        CURLOPT_PROXYUSERPWD   => $pa,
        CURLOPT_PROXYTYPE      => CURLPROXY_HTTP,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    file_put_contents("/tmp/djezzy.log", date("Y-m-d H:i:s") . " [otp_reg] CODE:$code ERR:$err BODY:" . substr((string)$resp, 0, 300) . "\n", FILE_APPEND);
    if ($err || $resp === false) return false;
    return ($code >= 200 && $code < 300);
}






// Session / User / PhoneMap
// ════════════════════════════════════════════════════════════════════════════
function getSession(string $p): array  { $f = SESSIONS_DIR . "/$p.json"; return file_exists($f) ? (json_decode(file_get_contents($f), true) ?? []) : []; }
function setSession(string $p, array $d): void {
    $f    = SESSIONS_DIR . "/{$p}.json";
    $tmp  = $f . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($d));
    rename($tmp, $f); // atomic on Linux
}
function clearSession(string $p): void { $f = SESSIONS_DIR . "/$p.json"; if (file_exists($f)) unlink($f); }
function saveUser(string $p, array $d): void {
    $f   = USERS_DIR . "/{$p}.json";
    $tmp = $f . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, $f);
}
function getUser(string $p): ?array { $f = USERS_DIR . "/$p.json"; return file_exists($f) ? json_decode(file_get_contents($f), true) : null; }
function savePhoneOwner(string $m, string $p): void { $map = file_exists(PHONE_MAP_FILE) ? (json_decode(file_get_contents(PHONE_MAP_FILE), true) ?? []) : []; $map[$m] = $p; file_put_contents(PHONE_MAP_FILE, json_encode($map)); }
function getPhoneOwner(string $m): ?string { if (!file_exists(PHONE_MAP_FILE)) return null; return (json_decode(file_get_contents(PHONE_MAP_FILE), true) ?? [])[$m] ?? null; }

// ════════════════════════════════════════════════════════════════════════════
// Messenger UI
// ════════════════════════════════════════════════════════════════════════════
function sendWelcomeNew(string $psid): void
{
    sendMessage($psid,
        "👋 أهلاً وسهلاً بك في Tasjil BOT! 🎉\n\n🌟 نرحب بك كمستخدم جديد!\n\n📌 مزايا البوت:\n\n✅ تفعيل 2G الأسبوعية 🎁\n✅ إرسال الدعوات 📨\n✅ تفعيل عرض 4GB بـ 70دج 🏷️\n✅ جميع عروض الإنترنت متوفرة ⭐\n\n━━━━━━━━━━━━━━\n\n📱 للبدء، أرسل رقم هاتفك (جيزي)\n🔹 مثال: 0770000000\n\n⚡ قناة التلغرام:\nhttps://t.me/tasjilbott"
    );
}
function sendWelcome(string $psid): void { sendMessage($psid, "يرجى ارسال أرقام هواتف فقط 📱\n"); }

function sendMenu(string $psid): void
{
    setSession($psid, array_merge(getSession($psid), ['state' => 'menu']));
    $cfg = getMatchGiftConfig();
    $extraRow = $cfg['enabled'] ? [['content_type' => 'text', 'title' => '🇩🇿 هدية المباراة', 'payload' => 'ACTIVATE_ALGERIA_MATCH']] : [];

    fbApiCall(json_encode([
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => [
            'text' =>
                "📱 اختر العرض المناسب\n\n📌 إذا لم تظهر لك الأزرار أرسل الرقم المناسب 👇\n\n━━━━━━━━━━━━━━\n\n" .
                "1️⃣ لتفعيل 2G الأسبوعية\n📩 أرسل: 1\n\n" .
                "2️⃣ لتفعيل عرض 4GB بـ 70دج 🏷️\n📩 أرسل: 2\n\n" .
                "3️⃣ لإرسال دعوة 🎁\n📩 أرسل: 3\n\n" .
                "4️⃣ للمزيد من العروض 📦\n📩 أرسل: 4\n\n━━━━━━━━━━━━━━\n\n" .
                ($cfg['enabled'] ? "🇩🇿 هدية المباراة متاحة! أرسل: 30\n\n" : ""),
            'quick_replies' => array_merge([
                ['content_type' => 'text', 'title' => '📶 تفعيل 2G',         'payload' => 'MENU_2G'],
                ['content_type' => 'text', 'title' => '💰 عرض 70دج - 4جيقا', 'payload' => 'MENU_70DZ'],
                ['content_type' => 'text', 'title' => '📨 إرسال دعوة',        'payload' => 'MENU_INVITE'],
                ['content_type' => 'text', 'title' => '📦 المزيد من العروض',  'payload' => 'MENU_MORE_OFFERS'],
            ], $extraRow),
        ],
    ], JSON_UNESCAPED_UNICODE));
}

function sendMoreOffers(string $psid): void
{
    setSession($psid, array_merge(getSession($psid), ['state' => 'offers']));
    $text  = "📦 قائمة عروض الإنترنت المتوفرة 📦\n\n";
    $text .= "━━━━━━━━━━━ 📅 العروض اليومية ━━━━━━━━━━━\n\n";
    $text .= "5️⃣ 5GB 🔥\n🌐 الانترنت : 5GB\n💰 السعر: 90 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 5\n\n\n\n";
    $text .= "6️⃣ 300MB\n🌐 الانترنت : 300Mo\n💰 السعر: 30 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 6\n\n\n\n";
    $text .= "7️⃣ 600MB\n🌐 الانترنت : 600Mo\n💰 السعر: 50 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 7\n\n\n\n";
    $text .= "8️⃣ 2GB\n🌐 الانترنت : 2G\n💰 السعر: 100 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 8\n\n\n\n";
    $text .= "9️⃣ 1GB\n🌐 الانترنت : 1G\n💰 السعر: 50 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 9\n\n\n\n";
    $text .= "🔟 4GB 🏷️\n🌐 الانترنت : 4G\n💰 السعر: 70 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 10\n\n\n\n";
    $text .= "1️⃣1️⃣ 5GB\n🌐 الانترنت : 5G\n💰 السعر: 190 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 11\n\n\n\n";
    $text .= "1️⃣2️⃣ 4GB\n🌐 الانترنت : 4G\n💰 السعر: 140 دج\n⏳ المدة: 24 ساعة\n📩 للتفعيل أرسل: 12\n\n\n\n";
    $text .= "━━━━━━━━━━━ 📆 العروض الأسبوعية ━━━━━━━━━━━\n\n";
    $text .= "1️⃣3️⃣ 4GB\n🌐 الانترنت : 4G\n💰 السعر: 150 دج\n⏳ المدة: 7 أيام\n📩 للتفعيل أرسل: 13\n\n\n\n";
    $text .= "1️⃣4️⃣ 10GB\n🌐 الانترنت : 10G\n💰 السعر: 300 دج\n⏳ المدة: 7 أيام\n📩 للتفعيل أرسل: 14\n\n\n\n";
    $text .= "1️⃣5️⃣ 4GB\n🌐 الانترنت : 4G\n💰 السعر: 400 دج\n⏳ المدة: 15 يوم\n📩 للتفعيل أرسل: 15\n\n\n\n";
    $text .= "1️⃣6️⃣ 1GB Facebook 📘\n🌐 الانترنت : 1G فيسبوك فقط\n💰 السعر: 70 دج\n⏳ المدة: 3 أيام\n📩 للتفعيل أرسل: 16\n\n\n\n";
    $text .= "━━━━━━━━━━━ 🗓️ العروض الشهرية ━━━━━━━━━━━\n\n";
    $text .= "1️⃣7️⃣ 12GB\n🌐 الانترنت : 12G\n💰 السعر: 500 دج\n⏳ المدة: 30 يوم\n📩 للتفعيل أرسل: 17\n\n\n\n";
    $text .= "1️⃣8️⃣ 30GB\n🌐 الانترنت : 30G\n💰 السعر: 1000 دج\n⏳ المدة: 30 يوم\n📩 للتفعيل أرسل: 18\n\n\n\n";
    $text .= "1️⃣9️⃣ 60GB\n🌐 الانترنت : 60G\n💰 السعر: 1500 دج\n⏳ المدة: 30 يوم\n📩 للتفعيل أرسل: 19\n\n\n\n";
    $text .= "2️⃣0️⃣ 3GB\n🌐 الانترنت : 3G\n💰 السعر: 250 دج\n⏳ المدة: 30 يوم\n📩 للتفعيل أرسل: 20\n\n\n\n";
    $text .= "━━━━━━━━━━━ ⚡ العروض الخاصة ━━━━━━━━━━━\n\n";
    $text .= "2️⃣1️⃣ 1GB سريع ⚡\n🌐 الانترنت : 1G\n💰 السعر: 40 دج\n⏳ المدة: 1 ساعة\n📩 للتفعيل أرسل: 21\n\n\n\n";
    $text .= "2️⃣2️⃣ Facebook غير محدود 📘\n🌐 الانترنت : فيسبوك فقط غير محدود\n💰 السعر: 50 دج\n⏳ المدة: 4 ساعات\n📩 للتفعيل أرسل: 22\n\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━━\n\n📨 أرسل رقم العرض فقط لتفعيله مباشرة";

    fbApiCall(json_encode([
        'recipient'      => ['id' => $psid],
        'messaging_type' => 'RESPONSE',
        'message'        => [
            'text'          => $text,
            'quick_replies' => [
                ['content_type' => 'text', 'title' => '5 - 5GB 90دج 🔥',   'payload' => 'ACTIVATE_OFFER_BTL500MBDAY'],
                ['content_type' => 'text', 'title' => '6 - 300Mo 30دج',    'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDDAY100MoPRE'],
                ['content_type' => 'text', 'title' => '7 - 600Mo 50دج',    'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDDAY250MoPRE'],
                ['content_type' => 'text', 'title' => '8 - 2Go 100دج',     'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDDAY1GoPRE'],
                ['content_type' => 'text', 'title' => '9 - 1Go 50دج',      'payload' => 'ACTIVATE_OFFER_OFFREJEUNE50'],
                ['content_type' => 'text', 'title' => '10 - 4GB 70دج',     'payload' => 'ACTIVATE_OFFER_BTLINTSPEEDDAY2Go'],
                ['content_type' => 'text', 'title' => '11 - 5GB 190دج',    'payload' => 'ACTIVATE_OFFER_BTL4GBDAY'],
                ['content_type' => 'text', 'title' => '13 - 4Go 150دج',    'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDWEEK2GoPRE'],
                ['content_type' => 'text', 'title' => '14 - 10Go 300دج',   'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDWEEK3GoPRE'],
                ['content_type' => 'text', 'title' => '17 - 12Go 500دج',   'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDMONTH6GoPRE'],
                ['content_type' => 'text', 'title' => '18 - 30Go 1000دج',  'payload' => 'ACTIVATE_OFFER_DOVINTSPEEDMONTH15GoPRE'],
                ['content_type' => 'text', 'title' => '21 - 1GB 40دج⚡',   'payload' => 'ACTIVATE_OFFER_BTL500MBHOUR'],
                ['content_type' => 'text', 'title' => '🔙 رجوع للقائمة',   'payload' => 'BACK_MENU'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE));
}

// ════════════════════════════════════════════════════════════════════════════
// Facebook Send Helpers
// ════════════════════════════════════════════════════════════════════════════

/**
 * sendFbMessage — يُعيد true/false بدل void لدعم البث
 */
function sendFbMessage(string $psid, string $text): bool
{
    $ch = curl_init('https://graph.facebook.com/v19.0/me/messages?access_token=' . FB_TOKEN);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['recipient' => ['id' => $psid], 'message' => ['text' => $text], 'messaging_type' => 'RESPONSE'], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    $data = @json_decode($resp, true);
    return !$err && isset($data['message_id']);
}

function sendMessage(string $psid, string $text): void
{
    fbApiCall(json_encode(['recipient' => ['id' => $psid], 'message' => ['text' => $text], 'messaging_type' => 'RESPONSE'], JSON_UNESCAPED_UNICODE));
}
function fbApiCall(string $payload): void
{
    $ch = curl_init('https://graph.facebook.com/v19.0/me/messages?access_token=' . FB_TOKEN);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    file_put_contents('/tmp/fb_send.log', date('Y-m-d H:i:s') . " ERR:$err RESP:$resp\n", FILE_APPEND);
}
