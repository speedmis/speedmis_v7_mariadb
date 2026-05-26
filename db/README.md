# db/ — 초기 데이터 번들 (MariaDB)

`install.php` 가 최초 구동 시 여기서 초기 데이터(`mago`)를 읽어 설치합니다.

## 파일

| 파일 | 설명 |
|------|------|
| `mago.sql.gz` | MariaDB 초기 데이터 (스키마 + 데이터). gzip 압축. **설치 시 우선 사용** |
| `mago.sql` | 위의 비압축 버전 (둘 중 있는 것을 사용) |

설치 마법사 동작 순서:
1. 로컬 `db/mago.sql.gz` → `db/mago.sql` 순으로 찾아 사용
2. 둘 다 없으면 `.env` 의 `DB_BUNDLE_URL`(Public 레포 raw)에서 자동 다운로드

## 마스킹 정책 (중요)

이 번들은 **운영 데이터를 거의 그대로** 담되, 아래만 마스킹합니다:
- `mis_users.passwd_decrypt` → 비움 (로그인은 `.env` 의 `MASTER_PASSWORD=4321` 만능비번으로)
- 고객/사용자의 개인정보 컬럼(전화·휴대폰·이메일·주소·주민/사업자번호 등) → 더미값으로 치환

> Public 레포이므로 **실제 개인정보·실접속정보는 절대 커밋하지 않습니다.**

## 덤프 생성 절차 (관리자용)

서버에서 `mysqldump` 로 추출 후 PHP 마스킹 스크립트 → gzip:

```bash
# 예시 (서버에서 실행)
mysqldump --single-transaction --routines --triggers --events \
  --default-character-set=utf8mb4 --no-tablespaces \
  --skip-add-locks --skip-lock-tables \
  -h 127.0.0.1 -u root -p mago > /tmp/mago.raw.sql

# 마스킹 패스 적용 (passwd_decrypt 비우기, 개인정보 컬럼 치환)
# → 최종적으로 gzip 압축하여 이 폴더의 mago.sql.gz 로 커밋
gzip -9 < /tmp/mago.masked.sql > /path/to/repo/db/mago.sql.gz
```

생성 규칙:
- `CREATE DATABASE` / `USE` 구문 제외 (설치 마법사가 DB 를 만들고 선택함)
- DEFINER 절 제거 (`/*!50017 DEFINER=...*/` 등) — 사용자 환경마다 다른 DB 사용자 권한 영향 회피
- 문장 종결자는 `;`, stored procedure/function/trigger 본문은 `DELIMITER //` 블록 사용 (설치 마법사 splitter 가 인식)
- 외래키는 적재 중 `SET FOREIGN_KEY_CHECKS=0` 으로 일시 무효화 (마법사가 자동 처리)
