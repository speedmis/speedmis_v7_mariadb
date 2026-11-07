<?php

namespace App\Config;

class Database
{
    private static ?\PDO $instance = null;

    private function __construct() {}

    public static function getInstance(): \PDO
    {
        if (self::$instance === null) {
            $driver  = strtolower($_ENV['DB_DRIVER'] ?? 'mysql');
            $host    = $_ENV['DB_HOST']    ?? '127.0.0.1';
            $port    = $_ENV['DB_PORT']    ?? ($driver === 'pgsql' ? '5432' : '3306');
            $name    = $_ENV['DB_NAME']    ?? 'speedmis_v7';
            $user    = $_ENV['DB_USER']    ?? '';
            $pass    = $_ENV['DB_PASS']    ?? '';
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

            if ($driver === 'pgsql') {
                $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
            } elseif ($driver === 'sqlsrv') {
                $dsn = "sqlsrv:Server={$host},{$port};Database={$name};TrustServerCertificate=true;Encrypt=no";
            } else {
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
            }

            $emulate = ($_ENV['DB_EMULATE_PREPARES'] ?? '0') === '1';
            $opts = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => $emulate,
            ];
            if ($driver === 'pgsql') {
                self::$instance = new PgCompatPDO($dsn, $user, $pass, $opts);
            } elseif ($driver === 'sqlsrv') {
                // sqlsrv 는 ATTR_EMULATE_PREPARES 미지원 — opts 에서 제거
                $sqlOpts = $opts;
                unset($sqlOpts[\PDO::ATTR_EMULATE_PREPARES]);
                // 숫자 컬럼을 string 대신 int/float native PHP 타입으로 반환 (kimgo 와 동일 형식)
                if (defined('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')) {
                    $sqlOpts[\PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE] = true;
                }
                // 한글(NVARCHAR) 파라미터/결과가 ?로 손상되지 않도록 UTF-8 인코딩 강제
                if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
                    $sqlOpts[\PDO::SQLSRV_ATTR_ENCODING] = \PDO::SQLSRV_ENCODING_UTF8;
                }
                self::$instance = new MssqlCompatPDO($dsn, $user, $pass, $sqlOpts);
            } else {
                self::$instance = new \PDO($dsn, $user, $pass, $opts);
            }

            if ($driver === 'pgsql') {
                self::$instance->exec("SET client_encoding TO 'UTF8'");
                self::$instance->exec("SET search_path TO public");
            } elseif ($driver === 'sqlsrv') {
                // MSSQL 은 default UTF-8 (Latin1_General_100_CI_AS_SC_UTF8) 또는 NVARCHAR. 별도 설정 없음.
            } else {
                self::$instance->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        }
        return self::$instance;
    }

    public static function isPg(): bool
    {
        return strtolower($_ENV['DB_DRIVER'] ?? 'mysql') === 'pgsql';
    }

    public static function isMssql(): bool
    {
        return strtolower($_ENV['DB_DRIVER'] ?? 'mysql') === 'sqlsrv';
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * AI/필드관리자가 제시하는 MySQL 스타일 컬럼타입 문자열(예: "VARCHAR(500)", "TEXT", "INT",
     * "DATETIME")을 현재(또는 지정한) DB 드라이버에 맞는 DDL 타입으로 변환.
     * 호출부(AI 필드생성 등)가 대상 드라이버와 무관하게 항상 이 함수를 거쳐야, MySQL 이외
     * 드라이버에서 요청 타입을 무시하고 임의로 좁은 타입(NVARCHAR(255) 등)을 쓰는 실수를 막을 수 있음
     * — 2026-08-04, v7ms 웹에디터 필드가 NVARCHAR(4000)로 생성되어 이미지 첨부 저장 시
     * "String or binary data would be truncated" 발생한 사고 계기로 신설.
     */
    public static function mapColumnType(string $mysqlType, ?string $driver = null): string
    {
        $driver = $driver ?? strtolower($_ENV['DB_DRIVER'] ?? 'mysql');
        $t = trim($mysqlType);
        $upper = strtoupper($t);

        if ($driver === 'sqlsrv') {
            if (preg_match('/^(TEXT|LONGTEXT|MEDIUMTEXT|TINYTEXT|JSON)/i', $upper)) return 'NVARCHAR(MAX)';
            if (preg_match('/^VARCHAR\s*\((\d+)\)/i', $upper, $m)) {
                return ((int)$m[1] > 4000) ? 'NVARCHAR(MAX)' : "NVARCHAR({$m[1]})";
            }
            if (preg_match('/^CHAR\s*\((\d+)\)/i', $upper, $m)) return "NCHAR({$m[1]})";
            if ($upper === 'DATETIME') return 'DATETIME2';
            if (preg_match('/^TINYINT\s*\(1\)/i', $upper)) return 'BIT';
            if (preg_match('/^TINYINT/i', $upper)) return 'SMALLINT';
            if ($upper === 'DOUBLE' || $upper === 'FLOAT') return 'FLOAT';
            return $t; // INT/BIGINT/DECIMAL(m,n)/DATE 등은 그대로 통용됨
        }

        if ($driver === 'pgsql') {
            if (preg_match('/^(TEXT|LONGTEXT|MEDIUMTEXT|TINYTEXT|JSON)/i', $upper)) return 'TEXT';
            if (preg_match('/^VARCHAR\s*\((\d+)\)/i', $upper, $m)) return "VARCHAR({$m[1]})";
            if ($upper === 'DATETIME') return 'TIMESTAMP';
            if (preg_match('/^TINYINT\s*\(1\)/i', $upper)) return 'BOOLEAN';
            if (preg_match('/^TINYINT/i', $upper)) return 'SMALLINT';
            if ($upper === 'DOUBLE') return 'DOUBLE PRECISION';
            return $t;
        }

        // mysql/mariadb 등: 요청 타입 그대로
        return $t;
    }

    // ── 외부 DB (dbalias) 연결 ─────────────────────────────────────
    private static array $aliasInstances = [];

    /**
     * .env의 DBALIAS_n 설정으로 PDO 생성. 캐시하여 동일 요청 내 재사용.
     * @return \PDO|null  설정이 없거나 연결 실패 시 null
     */
    public static function getByAlias(string $alias): ?\PDO
    {
        $alias = trim($alias);
        if ($alias === '' || $alias === 'default') return null;
        if (isset(self::$aliasInstances[$alias])) return self::$aliasInstances[$alias];

        // .env에서 DBALIAS_n 검색
        for ($i = 1; $i <= 5; $i++) {
            if (trim($_ENV["DBALIAS_{$i}_NAME"] ?? '') === $alias) {
                $driver = strtolower(trim($_ENV["DBALIAS_{$i}_DRIVER"] ?? 'mysql'));
                $host   = trim($_ENV["DBALIAS_{$i}_HOST"]   ?? '');
                $port   = trim($_ENV["DBALIAS_{$i}_PORT"]   ?? '');
                $dbname = trim($_ENV["DBALIAS_{$i}_DBNAME"] ?? '');
                $user   = trim($_ENV["DBALIAS_{$i}_USER"]   ?? '');
                $pass   = trim($_ENV["DBALIAS_{$i}_PASS"]   ?? '');

                if ($driver === 'sqlsrv') {
                    $dsn = "sqlsrv:Server={$host}" . ($port ? ",{$port}" : '') . ";Database={$dbname};TrustServerCertificate=true;Encrypt=no";
                } elseif ($driver === 'pgsql') {
                    $dsn = "pgsql:host={$host};port=" . ($port ?: '5432') . ";dbname={$dbname}";
                } else {
                    $dsn = "mysql:host={$host};port=" . ($port ?: '3306') . ";dbname={$dbname};charset=utf8";
                }

                $opts = [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ];
                if ($driver === 'sqlsrv') {
                    if (defined('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE'))
                        $opts[\PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE] = true;
                    if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8'))
                        $opts[\PDO::SQLSRV_ATTR_ENCODING] = \PDO::SQLSRV_ENCODING_UTF8;
                    $pdo = new MssqlCompatPDO($dsn, $user, $pass, $opts);
                } elseif ($driver === 'pgsql') {
                    $pdo = new PgCompatPDO($dsn, $user, $pass, $opts);
                    $pdo->exec("SET client_encoding TO 'UTF8'");
                } else {
                    $opts[\PDO::ATTR_EMULATE_PREPARES] = false;
                    $pdo = new \PDO($dsn, $user, $pass, $opts);
                    try { $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); }
                    catch (\Throwable) { $pdo->exec("SET NAMES utf8"); }
                }

                self::$aliasInstances[$alias] = $pdo;
                return $pdo;
            }
        }
        return null;
    }
}
