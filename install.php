<?php
/**
 * SpeedMIS v7 (MariaDB 배포판) — Install Wizard
 *
 * 워드프레스식 최초 구동 설치:
 *   1) MariaDB/MySQL 접속정보 입력 → 연결 테스트 → DB 생성(없으면)
 *   2) 초기 데이터(mago) 자동 적재
 *        · 로컬 db/mago.sql(.gz) 우선 → 없으면 GitHub Public 레포에서 자동 다운로드
 *        · gapm.kr 실시간 연결 없음
 *   3) SITE_ID 를 접속 URL 에서 자동 생성 (소문자/숫자 3~8자)
 *   4) .env 자동 생성 (MASTER_PASSWORD=4321 → 마스킹된 비밀번호 대신 만능비번으로 로그인)
 *   5) 완료
 *
 * 설치 후에는 user_id='gadmin' 또는 'admin' 로그인 사용자만 접근 가능.
 *
 * ⚠ 이 레포(speedmis_v7_mariadb)는 MSSQL/PostgreSQL 배포판과 별개입니다.
 *    설치는 MariaDB 10.4+ 또는 MySQL 8.0+ 전용입니다.
 */

require_once __DIR__ . '/core/src/InstallAuth.php';
require_once __DIR__ . '/core/src/SiteId.php';

use App\InstallAuth;
use App\SiteId;

/** DB 번들 기본 다운로드 위치 (Public 레포 raw). 로컬 db/ 가 있으면 그쪽 우선 */
const DB_BUNDLE_URL_DEFAULT = 'https://raw.githubusercontent.com/speedmis/speedmis_v7_mariadb/main/db/mago.sql.gz';

$envPath     = InstallAuth::resolveEnvPath();      // 표준 배포(심링크 없음)에서는 __DIR__/.env
$isInstalled = file_exists($envPath);
// DB 재적재(force) 는 데모 호스트(v7*.speedmis.com)에서만 허용 — 고객 설치본에서는 숨김·차단
$__host      = preg_replace('/:\d+$/', '', strtolower($_SERVER['HTTP_HOST'] ?? ''));
$isDemoHost  = (bool) preg_match('/^v7[a-z0-9-]*\.speedmis\.com$/', $__host);
$isForce     = $isInstalled && isset($_GET['force']) && $isDemoHost;   // force=1 + 이미 설치 + 데모호스트 = DB 재적재 모드 (.env 보존)
$envData     = $isForce ? InstallAuth::parseEnvFile($envPath) : [];

// 이미 설치된 경우: admin/gadmin 인증 (또는 복구키) 필요
if ($isInstalled) {
    $authUid = InstallAuth::requireAccess('설치 마법사 (install.php)');
}

if ($isInstalled && !$isForce) {
    $uidLabel = (($authUid ?? '') === '__recovery__') ? '복구 키 인증' : ('관리자(' . htmlspecialchars($authUid ?? '') . ') 로그인');
    $distroLabel = 'MariaDB';
    $distroRepo  = 'speedmis_v7_mariadb';
    $bundleLabel = 'mago';
    ?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SpeedMIS v7 (<?= $distroLabel ?>) — Admin</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Pretendard', -apple-system, sans-serif; background:#f4f5f7; color:#1a1d27; min-height: 100vh; padding: 40px 20px; }
  .wrap { width: 580px; max-width: 100%; margin: 0 auto; }
  .header-card { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.08); padding:28px 32px; margin-bottom:16px; text-align:center; }
  .header-card .ok-icon { font-size:42px; color:#16a34a; line-height:1; margin-bottom:8px; }
  .header-card h1 { font-size:20px; font-weight:700; margin-bottom:4px; }
  .header-card .sub { color:#8c93b0; font-size:13px; }
  .group { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.05); padding:6px; margin-bottom:12px; }
  .group .group-title { padding:10px 14px 6px; font-size:11px; font-weight:700; color:#8c93b0; letter-spacing:.5px; text-transform: uppercase; }
  .row { display:flex; align-items:center; gap:14px; padding:14px 14px; border-radius:8px; text-decoration:none; color:inherit; transition: background 0.12s; }
  .row:hover { background:#f8f9fb; text-decoration:none; }
  .row .icon { font-size:22px; width:28px; flex-shrink:0; text-align:center; }
  .row .body { flex:1; }
  .row .title { font-size:14px; font-weight:600; color:#1a1d27; margin-bottom:3px; }
  .row .desc { font-size:12px; color:#8c93b0; line-height:1.55; }
  .row .arrow { color:#c8ccda; font-size:18px; flex-shrink:0; }
  .row.danger { background:#fef2f2; }
  .row.danger:hover { background:#fee2e2; }
  .row.danger .title { color:#b91c1c; }
  .row.danger .desc { color:#dc2626; opacity:0.85; }
  .footer { text-align:center; color:#8c93b0; font-size:11px; padding:14px 0; }
  code { font-family: ui-monospace, monospace; background:#f0f1f5; padding:1px 5px; border-radius:3px; font-size:0.92em; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header-card">
    <div class="ok-icon">✓</div>
    <h1>SpeedMIS v7 (<?= $distroLabel ?>) 설치 완료</h1>
    <p class="sub"><?= $uidLabel ?> 확인됨</p>
  </div>

  <div class="group">
    <div class="group-title">사이트</div>
    <a href="/" class="row">
      <span class="icon">🏠</span>
      <div class="body">
        <div class="title">메인으로 이동</div>
        <div class="desc">설치된 사이트의 메인 페이지로 이동합니다.</div>
      </div>
      <span class="arrow">›</span>
    </a>
  </div>

  <div class="group">
    <div class="group-title">설정·관리</div>
    <a href="envmanage.php" class="row">
      <span class="icon">⚙️</span>
      <div class="body">
        <div class="title">환경설정 (.env) 관리</div>
        <div class="desc">DB 접속 정보·사이트 제목·마스터 비밀번호 등 환경변수를 직접 편집합니다.</div>
      </div>
      <span class="arrow">›</span>
    </a>
    <a href="update.php" class="row">
      <span class="icon">🔄</span>
      <div class="body">
        <div class="title">파일 업데이트</div>
        <div class="desc">GitHub <code><?= $distroRepo ?></code> 의 최신 소스 파일을 받아 변경/추가분만 덮어쓰기 합니다. <strong>DB 는 절대 건드리지 않습니다.</strong></div>
      </div>
      <span class="arrow">›</span>
    </a>
  </div>

  <?php if ($isDemoHost): ?>
  <div class="group">
    <div class="group-title">위험 — 데이터 손실</div>
    <a href="?force=1" class="row danger" onclick="return confirm('정말 DB 를 재적재할까요?\n기존에 입력한 모든 데이터가 사라집니다.\n(.env 는 보존됩니다)');">
      <span class="icon">⚠️</span>
      <div class="body">
        <div class="title">DB 재적재 (force)</div>
        <div class="desc">DB 의 모든 테이블을 초기 상태(<code><?= $bundleLabel ?></code> 번들)로 되돌립니다. <strong>기존 입력 데이터가 모두 사라집니다.</strong><br><code>.env</code> (APP_PWD_KEY · MASTER_PASSWORD 등) 는 <strong>변경되지 않습니다</strong>.</div>
      </div>
      <span class="arrow">›</span>
    </a>
  </div>
  <?php endif; ?>

  <p class="footer">보안을 위해 운영 전환 후 install.php 삭제 권장</p>
</div>
</body>
</html>
<?php
    exit;
}

$step    = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$errors  = [];
$log     = [];

// ── SQL 번들 로더 ────────────────────────────────────────────────────────────
function load_bundle_sql(string $baseDir, string $url, array &$log): ?string
{
    foreach (['db/mago.sql.gz', 'db/mago.sql'] as $rel) {
        $p = $baseDir . '/' . $rel;
        if (is_file($p)) {
            $raw = @file_get_contents($p);
            if ($raw !== false && str_ends_with($rel, '.gz')) $raw = @gzdecode($raw);
            if (is_string($raw) && $raw !== '') {
                $log[] = "로컬 초기데이터 사용: {$rel} (" . round(strlen($raw) / 1024) . " KB)";
                return $raw;
            }
        }
    }
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: SpeedMIS-Installer\r\n",
        'timeout' => 180,
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) return null;
    if (str_ends_with($url, '.gz')) {
        $d = @gzdecode($data);
        if ($d !== false) $data = $d;
    }
    $log[] = "원격 초기데이터 다운로드: " . round(strlen($data) / 1024) . " KB";
    return $data;
}

// ── MariaDB 다문장 적재 (DELIMITER 인식 splitter) ───────────────────────────
// mysqldump 결과는 대부분 `;` 종결 + trigger/proc 은 `DELIMITER //` … `END //` 블록.
// 단순 explode(';') 는 stored proc 본문에서 깨지므로 라인 단위 스캐너 필요.
function split_sql_mysql(string $sql): array
{
    $statements = [];
    $delim = ';';
    $buf = '';
    foreach (preg_split("/\r?\n/", $sql) as $line) {
        $trim = trim($line);
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trim, $m)) {
            if (trim($buf) !== '') $statements[] = trim($buf);
            $buf = '';
            $delim = $m[1];
            continue;
        }
        $buf .= $line . "\n";
        $r = rtrim($line);
        if ($delim !== '' && str_ends_with($r, $delim)) {
            $stmt = trim($buf);
            if (str_ends_with($stmt, $delim)) $stmt = trim(substr($stmt, 0, -strlen($delim)));
            if ($stmt !== '') $statements[] = $stmt;
            $buf = '';
        }
    }
    if (trim($buf) !== '') $statements[] = trim($buf);
    return $statements;
}

function exec_mysql_statements(PDO $pdo, string $sql): array
{
    $stmts = split_sql_mysql($sql);
    $ok = 0; $fail = 0; $warnings = [];
    foreach ($stmts as $s) {
        if ($s === '') continue;
        try {
            $pdo->exec($s);
            $ok++;
        } catch (PDOException $e) {
            $fail++;
            if (count($warnings) < 10) {
                $warnings[] = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 140);
            }
        }
    }
    return ['statements' => $ok, 'failed' => $fail, 'warnings' => $warnings];
}

// ── STEP 2: 연결 테스트 → DB 생성 → 초기데이터 적재 → .env 작성 ───────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost    = trim($_POST['db_host'] ?? '');
    $dbPort    = trim($_POST['db_port'] ?? '3306');
    $dbName    = trim($_POST['db_name'] ?? 'mago');
    $dbUser    = trim($_POST['db_user'] ?? '');
    $dbPass    = $_POST['db_pass'] ?? '';
    $siteTitle = trim($_POST['site_title'] ?? 'SpeedMIS v7');
    $appUrl    = trim($_POST['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));

    if (!$dbHost) $errors[] = 'DB 호스트를 입력하세요.';
    if (!$dbUser) $errors[] = 'DB 사용자를 입력하세요.';
    if (!$dbName) $errors[] = 'DB 이름을 입력하세요.';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) $errors[] = 'DB 이름은 영문/숫자/밑줄만 가능합니다.';
    if (!extension_loaded('pdo_mysql')) {
        $errors[] = 'PHP pdo_mysql 확장이 설치되어 있지 않습니다. (php-mysql 패키지 필요)';
    }

    // 1) 서버 접속 + DB 생성 (공유 호스팅은 CREATE DATABASE 권한이 없을 수 있어 graceful fallback)
    if (empty($errors)) {
        try {
            $dsnServer = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsnServer, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
            $exists = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $exists->execute([$dbName]);
            if ($exists->fetchColumn() === false) {
                // CREATE 시도 — 권한 없으면 다음 step 에서 사용자에게 안내
                try {
                    $pdo->exec("CREATE DATABASE `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $log[] = "데이터베이스 생성: {$dbName}";
                } catch (PDOException $e2) {
                    $log[] = "⚠ DB 자동 생성 권한이 없어 보입니다 (공유호스팅 등 흔한 케이스).";
                    $log[] = "  → 호스팅 관리자 페이지에서 '{$dbName}' DB 를 미리 만들고 다시 시도하세요.";
                }
            } else {
                $log[] = "기존 데이터베이스 사용: {$dbName}";
            }
        } catch (PDOException $e) {
            $errors[] = 'DB 서버 연결 실패: ' . $e->getMessage();
        }
    }

    // 2) DB 선택 후 기설치 여부 확인 (force 모드는 검사 스킵)
    if (empty($errors)) {
        try {
            $dsnDb = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsnDb, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
            if (!$isForce) {
                $stm = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
                $stm->execute([$dbName, 'mis_menus']);
                if ($stm->fetchColumn() !== false) {
                    $errors[] = "'{$dbName}' 에 이미 mis_menus 테이블이 존재합니다. 빈 DB를 쓰거나 DB 이름을 바꾸세요.";
                }
            } else {
                // force 모드: mago 번들이 DROP TABLE IF EXISTS 를 포함하고 있어 기존 테이블 위에 안전하게 재적재됨
                $log[] = "force 모드: 기존 테이블 검사 스킵. 번들 내 DROP TABLE IF EXISTS 로 재초기화됨.";
            }
        } catch (PDOException $e) {
            $errors[] = 'DB 선택 실패: ' . $e->getMessage();
        }
    }

    // 3) 초기 데이터 적재
    if (empty($errors)) {
        $bundle = load_bundle_sql(__DIR__, DB_BUNDLE_URL_DEFAULT, $log);
        if ($bundle === null || $bundle === '') {
            $errors[] = '초기 데이터(mago)를 불러오지 못했습니다. 인터넷 연결 또는 db/mago.sql 파일을 확인하세요.';
        } else {
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 0'); } catch (\Throwable) {}
            try { $pdo->exec('SET UNIQUE_CHECKS = 0'); } catch (\Throwable) {}

            $res = exec_mysql_statements($pdo, $bundle);

            try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable) {}
            try { $pdo->exec('SET UNIQUE_CHECKS = 1'); } catch (\Throwable) {}

            if ($res['statements'] < 20) {
                $errors[] = '초기 데이터 적재가 거의 실패했습니다 (' . $res['statements'] . ' 문장). DB 권한/연결을 확인하세요.';
                foreach ($res['warnings'] as $wmsg) $errors[] = '· ' . $wmsg;
            } else {
                $log[] = "초기 데이터 적재 완료 ({$res['statements']} 문장"
                       . ($res['failed'] ? ", 환경별 객체 {$res['failed']}건 건너뜀" : "") . ")";
                foreach ($res['warnings'] as $wmsg) $log[] = "  · 건너뜀: " . $wmsg;
            }
        }
    }

    // 4) .env 작성 (+ SITE_ID 자동생성) — force 모드면 .env 손대지 않고 통과
    if (empty($errors) && $isForce) {
        $log[] = ".env 보존 (force 모드 — APP_PWD_KEY / MASTER_PASSWORD / SITE_ID 등 모두 유지).";
        $siteDir = dirname($envPath);
        foreach (['uploadFiles', 'uploadFiles/_temp', 'logs', 'logs/cache'] as $d) {
            $dir = $siteDir . '/' . $d;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
        }
        $step = 3;
    }
    if (empty($errors) && !$isForce) {
        $pwdKey = bin2hex(random_bytes(32));

        $host    = parse_url($appUrl, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $derived = SiteId::fromHost($host);
        if ($derived !== null) {
            $siteId = $derived;
            $siteAuto = 'done';
        } else {
            $siteId = SiteId::provisional($host);
            $siteAuto = 'pending';
        }

        $titleEsc = str_replace('"', '\"', $siteTitle);
        $env = <<<ENV
# SpeedMIS v7 (MariaDB 배포판) — install.php 가 자동 생성
DB_DRIVER=mysql
DB_HOST={$dbHost}
DB_PORT={$dbPort}
DB_NAME={$dbName}
DB_USER={$dbUser}
DB_PASS={$dbPass}
DB_CHARSET=utf8mb4
DB_EMULATE_PREPARES=0

APP_ENV=production
APP_DEBUG=false
APP_URL={$appUrl}
APP_PWD_KEY={$pwdKey}

SITE_ID={$siteId}
SITE_ID_AUTO={$siteAuto}
SITE_TITLE="{$titleEsc}"
REAL_PID_HOME=speedmis000314
REAL_PID_HOME2=

MASTER_PASSWORD=4321
AUTO_LOGOUT_MINUTE=30
LOGIN_FAIL_LEVEL=1

DEFAULT_PAGE_SIZE=25
ROOT_REDIRECT_TO_APP=N

AUDIT_CREATOR_COLS=wdater,writer,created_by,create_by,regist_id
AUDIT_CREATED_COLS=wdate,created_at,create_at,write_date,regist_dt
AUDIT_UPDATER_COLS=lastupdater,updater,updated_by,modify_by
AUDIT_UPDATED_COLS=lastupdate,updated_at,modify_date,update_dt

TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_NAME=
SHOP_DATA_ROOT=

CHAT_RETENTION_DAYS=30
CHAT_REALTIME_POLLING=Y

DB_BUNDLE_URL=
INSTALL_RECOVERY_HASH=
ENV;
        if (file_put_contents($envPath, $env) === false) {
            $errors[] = '.env 작성 실패 — 디렉토리 쓰기 권한을 확인하세요.';
        } else {
            $log[] = ".env 생성 완료 (SITE_ID={$siteId}, MASTER_PASSWORD=4321)";
            $siteDir = dirname($envPath);
            foreach (['uploadFiles', 'uploadFiles/_temp', 'logs', 'logs/cache'] as $d) {
                $dir = $siteDir . '/' . $d;
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
            }
            $step = 3;
        }
    }

    if (!empty($errors)) $step = 1;
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SpeedMIS v7 (MariaDB) Install</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Pretendard', -apple-system, sans-serif; background: #f4f5f7; color: #1a1d27; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); width: 500px; max-width: 95vw; padding: 40px; }
  h1 { font-size: 22px; margin-bottom: 6px; }
  .sub { color: #8c93b0; font-size: 14px; margin-bottom: 24px; }
  .tag { display:inline-block; font-size:11px; font-weight:700; color:#fff; background:#c2410c; border-radius:4px; padding:2px 8px; margin-bottom:14px; letter-spacing:.5px; }
  .step-bar { display: flex; gap: 8px; margin-bottom: 24px; }
  .step-dot { flex: 1; height: 4px; border-radius: 2px; background: #dde0e8; }
  .step-dot.active { background: #4f6ef7; }
  .step-dot.done { background: #22c55e; }
  label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: #4a5068; }
  input[type=text], input[type=password], input[type=number] {
    width: 100%; height: 38px; border: 1px solid #dde0e8; border-radius: 6px;
    padding: 0 12px; font-size: 14px; outline: none; transition: border 0.15s;
  }
  input:focus { border-color: #4f6ef7; }
  .row { margin-bottom: 16px; }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
  .btn { width: 100%; height: 42px; border: 0; border-radius: 6px; font-size: 15px; font-weight: 600; background: #4f6ef7; color: #fff; cursor: pointer; transition: background 0.15s; }
  .btn:hover { background: #3b5de7; }
  .err { background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
  .ok { background: #f0fdf4; border: 1px solid #86efac; color: #16a34a; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
  .log { background: #f8f9fb; border: 1px solid #dde0e8; border-radius: 6px; padding: 12px; font-size: 13px; margin-bottom: 16px; line-height: 1.8; }
  .hint { font-size: 12px; color: #8c93b0; margin-top: 4px; }
  .done-icon { font-size: 48px; text-align: center; margin-bottom: 16px; }
  a { color: #4f6ef7; text-decoration: none; }
  a:hover { text-decoration: underline; }

  /* 설치 버튼 spinner */
  .btn .btn-spinner { display:none; width:14px; height:14px; border:2px solid rgba(255,255,255,0.45); border-top-color:#FFF; border-radius:50%; margin-right:8px; vertical-align:-3px; animation: install-spin 0.7s linear infinite; }
  .btn.is-loading .btn-spinner { display:inline-block; }
  .btn.is-loading { background:#3b5de7; cursor:wait; }
  .btn.is-loading .btn-label::after { content:" 중..."; }
  @keyframes install-spin { to { transform: rotate(360deg); } }

  /* 전체 화면 오버레이 */
  #install-overlay { display:none; position:fixed; inset:0; background:rgba(15,17,23,0.55); z-index:9999; align-items:center; justify-content:center; padding:20px; }
  .install-overlay__card { background:#fff; border-radius:14px; padding:36px 42px; text-align:center; max-width:440px; width:100%; box-shadow:0 18px 60px rgba(0,0,0,0.25); }
  .install-overlay__spinner { width:48px; height:48px; border:4px solid #E5E8EB; border-top-color:#4F6EF7; border-radius:50%; margin:0 auto 18px; animation: install-spin 0.9s linear infinite; }
  .install-overlay__card h2 { font-size:18px; font-weight:700; margin-bottom:10px; color:#191F28; }
  .install-overlay__card p  { font-size:14px; line-height:1.7; color:#4E5968; }

</style>
</head>
<body>
<div class="card">

  <div class="step-bar">
    <div class="step-dot <?= $step >= 2 ? 'done' : ($step === 1 ? 'active' : '') ?>"></div>
    <div class="step-dot <?= $step >= 3 ? 'done' : ($step === 2 ? 'active' : '') ?>"></div>
  </div>

<?php if ($step === 1): // ── DB 접속정보 ── ?>
  <span class="tag"<?= $isForce ? ' style="background:#dc2626"' : '' ?>><?= $isForce ? 'FORCE — DB 재적재' : 'MariaDB EDITION' ?></span>
  <h1><?= $isForce ? 'DB 재적재 (force)' : 'SpeedMIS v7 설치' ?></h1>
  <?php if ($isForce): ?>
    <p class="sub" style="color:#b91c1c;font-weight:500"><strong>⚠ 위험:</strong> 기존 DB 의 모든 데이터가 사라지고 초기 상태(mago 번들)로 되돌아갑니다. <code>.env</code> 는 보존됩니다.</p>
  <?php else: ?>
    <p class="sub">MariaDB / MySQL 접속 정보를 입력하면, 초기 데이터를 자동으로 받아 설치합니다.</p>
  <?php endif; ?>

  <?php foreach ($errors as $e): ?><div class="err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  <?php if (!empty($log)): ?><div class="log"><?php foreach ($log as $l): ?><?= htmlspecialchars($l) ?><br><?php endforeach; ?></div><?php endif; ?>

  <?php
    // force 모드면 기존 .env 값으로 form 채우기. 일반 설치는 빈/기본값.
    $f_dbHost = $_POST['db_host']    ?? ($isForce ? ($envData['DB_HOST']    ?? '127.0.0.1') : '127.0.0.1');
    $f_dbPort = $_POST['db_port']    ?? ($isForce ? ($envData['DB_PORT']    ?? '3306')      : '3306');
    $f_dbName = $_POST['db_name']    ?? ($isForce ? ($envData['DB_NAME']    ?? 'mago')      : 'mago');
    $f_dbUser = $_POST['db_user']    ?? ($isForce ? ($envData['DB_USER']    ?? 'root')      : 'root');
    $f_dbPass = $_POST['db_pass']    ?? ($isForce ? ($envData['DB_PASS']    ?? '')          : '');
    $f_title  = $_POST['site_title'] ?? ($isForce ? ($envData['SITE_TITLE'] ?? 'SpeedMIS v7') : 'SpeedMIS v7');
    $f_url    = $_POST['app_url']    ?? ($isForce ? ($envData['APP_URL']    ?? '')          : ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
  ?>
  <form method="post" <?= $isForce ? 'action="?force=1"' : '' ?>>
    <input type="hidden" name="step" value="2">
    <div class="row">
      <label>DB 호스트 (MariaDB / MySQL)</label>
      <input type="text" name="db_host" value="<?= htmlspecialchars($f_dbHost) ?>" placeholder="127.0.0.1" required<?= $isForce ? ' readonly style="background:#f8f9fb;cursor:not-allowed"' : '' ?>>
    </div>
    <div class="row2">
      <div>
        <label>DB 포트</label>
        <input type="number" name="db_port" value="<?= htmlspecialchars($f_dbPort) ?>" placeholder="3306"<?= $isForce ? ' readonly style="background:#f8f9fb;cursor:not-allowed"' : '' ?>>
      </div>
      <div>
        <label>DB 이름</label>
        <input type="text" name="db_name" value="<?= htmlspecialchars($f_dbName) ?>" placeholder="mago" required<?= $isForce ? ' readonly style="background:#f8f9fb;cursor:not-allowed"' : '' ?>>
      </div>
    </div>
    <div class="row2">
      <div>
        <label>DB 사용자</label>
        <input type="text" name="db_user" value="<?= htmlspecialchars($f_dbUser) ?>" required<?= $isForce ? ' readonly style="background:#f8f9fb;cursor:not-allowed"' : '' ?>>
      </div>
      <div>
        <label>DB 비밀번호</label>
        <input type="password" name="db_pass" value="<?= htmlspecialchars($f_dbPass) ?>"<?= $isForce ? ' placeholder="(.env 의 기존값 사용)"' : '' ?>>
      </div>
    </div>
    <?php if (!$isForce): ?>
    <div class="row">
      <label>사이트 제목</label>
      <input type="text" name="site_title" value="<?= htmlspecialchars($f_title) ?>">
    </div>
    <div class="row">
      <label>사이트 URL</label>
      <input type="text" name="app_url" value="<?= htmlspecialchars($f_url) ?>" placeholder="http://example.com">
      <div class="hint">이 주소에서 <b>SITE_ID</b> 가 자동 생성됩니다 (소문자/숫자 3~8자). IP면 임시값 → 나중에 도메인 접속 시 자동 갱신.</div>
    </div>
    <div class="hint" style="margin-bottom:20px">DB가 없으면 자동 생성하고, 초기 데이터(mago)를 받아 설치합니다.</div>
    <?php else: ?>
    <input type="hidden" name="site_title" value="<?= htmlspecialchars($f_title) ?>">
    <input type="hidden" name="app_url" value="<?= htmlspecialchars($f_url) ?>">
    <div class="hint" style="margin-bottom:20px;color:#b91c1c">계속하면 DB 의 모든 테이블이 DROP 되고 초기 mago 번들로 재적재됩니다.</div>
    <?php endif; ?>
    <button type="submit" class="btn<?= $isForce ? ' btn-danger' : '' ?>" id="install-submit-btn"<?= $isForce ? ' style="background:#dc2626"' : '' ?>>
      <span class="btn-spinner" aria-hidden="true"></span>
      <span class="btn-label"><?= $isForce ? 'DB 재적재 시작' : '연결 &amp; 자동 설치' ?></span>
    </button>
  </form>

  <!-- 설치 진행 중 오버레이 (submit 시 표시) -->
  <div id="install-overlay" aria-hidden="true">
    <div class="install-overlay__card">
      <div class="install-overlay__spinner"></div>
      <h2>설치 진행 중...</h2>
      <p>
        DB 자동 생성 + 초기데이터(약 1MB) 다운로드 + 116 테이블 적재.<br>
        평균 <b>20~60초</b> 소요됩니다. 창을 닫지 마세요.
      </p>
    </div>
  </div>

  <script>
    (function () {
      var form = document.querySelector('form[method="post"]');
      if (!form) return;
      form.addEventListener('submit', function () {
        var btn = document.getElementById('install-submit-btn');
        if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
        var ov = document.getElementById('install-overlay');
        if (ov) ov.style.display = 'flex';
      });
    })();
  </script>

<?php elseif ($step === 3): // ── 완료 ── ?>
  <div class="done-icon">&#10004;</div>
  <h1 style="text-align:center"><?= $isForce ? 'DB 재적재 완료!' : '설치 완료!' ?></h1>
  <p class="sub" style="text-align:center">
    <?php if ($isForce): ?>
      SpeedMIS v7 (MariaDB) DB 가 초기 상태로 재적재되었습니다. <strong>.env 는 그대로 보존</strong>되었습니다.
    <?php else: ?>
      SpeedMIS v7 (MariaDB) 이 성공적으로 설치되었습니다.
    <?php endif; ?>
  </p>
  <?php if (!empty($log)): ?><div class="log"><?php foreach ($log as $l): ?><?= htmlspecialchars($l) ?><br><?php endforeach; ?></div><?php endif; ?>
  <div class="ok">
    <?php if ($isForce): ?>
      기존 <strong>.env</strong> 의 APP_PWD_KEY · MASTER_PASSWORD · SITE_ID 가 그대로 유지되었으므로 기존 로그인 정보로 접속 가능합니다.<br>
      단, DB 의 사용자 데이터(직원·거래처·주문 등)는 모두 사라졌습니다.
    <?php else: ?>
      로그인은 <strong>gadmin</strong> / 비번 <strong>4321</strong> 로 로그인하세요.<br>
      운영 전환 시 <strong>.env 의 MASTER_PASSWORD 를 반드시 변경/비활성</strong> 하세요.<br>
      보안을 위해 <strong>install.php 삭제</strong>를 권장합니다.
    <?php endif; ?>
  </div>
  <a href="/" style="display:block;text-align:center;margin-top:20px;font-size:15px;font-weight:600"><?= $isForce ? '메인으로 이동' : '로그인 페이지로 이동' ?> &rarr;</a>

<?php endif; ?>

</div>
</body>
</html>
