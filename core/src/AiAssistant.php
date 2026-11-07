<?php
namespace App;

/**
 * AI 코드 어시스턴트 — programs/*.php 훅 코드 생성/수정.
 * 멀티 모델 지원: Claude (Sonnet/Opus), GPT-4o, Gemini.
 */
class AiAssistant
{
    private const MODELS = [
        'claude-sonnet' => ['provider'=>'anthropic', 'model'=>'claude-sonnet-4-6',   'label'=>'Claude Sonnet (기본)'],
        'claude-opus'   => ['provider'=>'anthropic', 'model'=>'claude-opus-4-6',     'label'=>'Claude Opus (고급)'],
        'gpt-4o'        => ['provider'=>'openai',    'model'=>'gpt-4o',                      'label'=>'GPT-4o'],
        'gemini'        => ['provider'=>'google',    'model'=>'gemini-2.5-flash',            'label'=>'Gemini 2.5 Flash'],
    ];

    // 커스텀 AI 제공자 — OpenAI 호환 API 엔드포인트
    private const CUSTOM_PROVIDERS = [
        'deepseek'   => ['url'=>'https://api.deepseek.com/v1/chat/completions',       'model'=>'deepseek-chat',           'label'=>'DeepSeek'],
        'mistral'    => ['url'=>'https://api.mistral.ai/v1/chat/completions',         'model'=>'mistral-large-latest',    'label'=>'Mistral'],
        'cohere'     => ['url'=>'https://api.cohere.com/v2/chat',                     'model'=>'command-r-plus',          'label'=>'Cohere'],
        'xai-grok'   => ['url'=>'https://api.x.ai/v1/chat/completions',              'model'=>'grok-3',                  'label'=>'xAI Grok'],
        'perplexity' => ['url'=>'https://api.perplexity.ai/chat/completions',         'model'=>'sonar-pro',               'label'=>'Perplexity'],
        'together'   => ['url'=>'https://api.together.xyz/v1/chat/completions',       'model'=>'meta-llama/Llama-3-70b-chat-hf', 'label'=>'Together (Llama)'],
        'fireworks'  => ['url'=>'https://api.fireworks.ai/inference/v1/chat/completions','model'=>'accounts/fireworks/models/llama-v3p1-70b-instruct','label'=>'Fireworks (Llama)'],
    ];

    private string $systemPrompt;

    public function __construct()
    {
        $this->systemPrompt = $this->buildSystemPrompt();
    }

    /** 사용 가능한 모델 목록 (API 키가 설정된 것만) */
    public static function availableModels(): array
    {
        $result = [];
        foreach (self::MODELS as $id => $m) {
            $keyName = match ($m['provider']) {
                'anthropic' => 'AI_ANTHROPIC_KEY',
                'openai'    => 'AI_OPENAI_KEY',
                'google'    => 'AI_GOOGLE_KEY',
                default     => '',
            };
            if ($keyName && !empty($_ENV[$keyName])) {
                $result[] = ['id' => $id, 'label' => $m['label'], 'provider' => $m['provider']];
            }
        }
        // 커스텀 프로바이더
        $customProv = strtolower(trim($_ENV['AI_CUSTOM_PROVIDER'] ?? ''));
        $customKey  = trim($_ENV['AI_CUSTOM_KEY'] ?? '');
        if ($customProv && $customKey && isset(self::CUSTOM_PROVIDERS[$customProv])) {
            $cp = self::CUSTOM_PROVIDERS[$customProv];
            $result[] = ['id' => 'custom', 'label' => $cp['label'], 'provider' => 'custom'];
        }
        return $result;
    }

    /** AI에 코드 생성/수정 요청 */
    public function ask(string $modelId, string $userPrompt, string $currentCode = '', string $menuInfo = ''): array
    {
        // 커스텀 프로바이더 처리
        if ($modelId === 'custom') {
            $customProv = strtolower(trim($_ENV['AI_CUSTOM_PROVIDER'] ?? ''));
            $customKey  = trim($_ENV['AI_CUSTOM_KEY'] ?? '');
            if (!$customProv || !$customKey || !isset(self::CUSTOM_PROVIDERS[$customProv])) {
                return ['success' => false, 'message' => '커스텀 AI 설정이 없습니다. envmanage.php에서 AI_CUSTOM_PROVIDER와 AI_CUSTOM_KEY를 설정하세요.'];
            }
            $cp = self::CUSTOM_PROVIDERS[$customProv];
            $config = ['provider' => 'custom', 'model' => $cp['model'], 'label' => $cp['label'], 'url' => $cp['url']];
            $apiKey = $customKey;
        } else {
            $config = self::MODELS[$modelId] ?? null;
            if (!$config) return ['success' => false, 'message' => '알 수 없는 모델: ' . $modelId];

            $keyName = match ($config['provider']) {
                'anthropic' => 'AI_ANTHROPIC_KEY',
                'openai'    => 'AI_OPENAI_KEY',
                'google'    => 'AI_GOOGLE_KEY',
                default     => '',
            };
            $apiKey = $_ENV[$keyName] ?? '';
            if (!$apiKey) return ['success' => false, 'message' => $config['label'] . ' API 키가 설정되지 않았습니다.'];
        }

        $contextMsg = '';
        if ($menuInfo) $contextMsg .= "현재 메뉴 정보:\n" . $menuInfo . "\n\n";
        if ($currentCode) $contextMsg .= "현재 코드:\n```php\n" . $currentCode . "\n```\n\n";
        $contextMsg .= "요청: " . $userPrompt;

        try {
            $response = match ($config['provider']) {
                'anthropic' => $this->callAnthropic($apiKey, $config['model'], $contextMsg),
                'openai'    => $this->callOpenAI($apiKey, $config['model'], $contextMsg),
                'google'    => $this->callGoogle($apiKey, $config['model'], $contextMsg),
                'custom'    => $this->callCustom($apiKey, $config['model'], $config['url'], $contextMsg),
                default     => throw new \Exception('지원하지 않는 provider'),
            };
            return ['success' => true, 'response' => $response, 'model' => $config['label']];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'AI 호출 실패: ' . $e->getMessage()];
        }
    }

    /**
     * AI 응답의 SEARCH/REPLACE 블록을 파싱하여 기존 코드에 패치 적용
     * @return array{patched: string, count: int, errors: string[]}
     */
    public static function applyPatch(string $currentCode, string $aiResponse): array
    {
        $errors = [];
        $count = 0;
        $code = $currentCode;

        // <<<SEARCH ... >>> <<<REPLACE ... >>> 블록 추출
        $pattern = '/<<<SEARCH\n([\s\S]*?)>>>\s*\n<<<REPLACE\n([\s\S]*?)>>>/';
        if (!preg_match_all($pattern, $aiResponse, $matches, PREG_SET_ORDER)) {
            return ['patched' => $code, 'count' => 0, 'errors' => ['SEARCH/REPLACE 블록을 찾을 수 없습니다.']];
        }

        foreach ($matches as $i => $m) {
            $search  = $m[1];
            $replace = $m[2];

            // SEARCH가 빈 문자열이면 → 파일 끝에 추가
            if (trim($search) === '') {
                $code = rtrim($code) . "\n" . $replace;
                $count++;
                continue;
            }

            // 정확한 문자열 치환
            $search = rtrim($search, "\n");
            $replace = rtrim($replace, "\n");

            if (str_contains($code, $search)) {
                $code = self::strReplaceFirst($search, $replace, $code);
                $count++;
            } else {
                // 공백/줄바꿈 차이 허용해서 재시도
                $searchNorm = preg_replace('/\s+/', ' ', trim($search));
                $codeNorm = preg_replace('/\s+/', ' ', $code);
                if (str_contains($codeNorm, $searchNorm)) {
                    // 원본에서 정규식으로 찾기
                    $escaped = preg_quote($search, '/');
                    $flexPattern = str_replace(['\\ ', '\n'], ['\s+', '\s*\n\s*'], $escaped);
                    $code = preg_replace('/' . $flexPattern . '/', $replace, $code, 1, $cnt);
                    if ($cnt > 0) { $count++; }
                    else { $errors[] = "블록 #" . ($i+1) . ": SEARCH 매칭 실패 (유사 패턴 발견, 공백 차이)"; }
                } else {
                    $errors[] = "블록 #" . ($i+1) . ": SEARCH 매칭 실패 — 기존 코드에서 해당 부분을 찾을 수 없습니다.";
                }
            }
        }

        return ['patched' => $code, 'count' => $count, 'errors' => $errors];
    }

    private static function strReplaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) return $subject;
        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    // ── Anthropic (Claude) ───────────────────────────────────────
    private function callAnthropic(string $key, string $model, string $userMsg): string
    {
        $body = json_encode([
            'model'      => $model,
            'max_tokens' => 4096,
            'system'     => $this->systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $userMsg]],
        ], JSON_UNESCAPED_UNICODE);

        $resp = $this->http('https://api.anthropic.com/v1/messages', $body, [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ]);

        $data = json_decode($resp, true);
        if (!$data || isset($data['error'])) {
            throw new \Exception($data['error']['message'] ?? 'Anthropic API 오류');
        }
        return $data['content'][0]['text'] ?? '';
    }

    // ── OpenAI (GPT) ────────────────────────────────────────────
    private function callOpenAI(string $key, string $model, string $userMsg): string
    {
        $body = json_encode([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt],
                ['role' => 'user',   'content' => $userMsg],
            ],
            'max_tokens' => 4096,
        ], JSON_UNESCAPED_UNICODE);

        $resp = $this->http('https://api.openai.com/v1/chat/completions', $body, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ]);

        $data = json_decode($resp, true);
        if (!$data || isset($data['error'])) {
            throw new \Exception($data['error']['message'] ?? 'OpenAI API 오류');
        }
        return $data['choices'][0]['message']['content'] ?? '';
    }

    // ── Google (Gemini) ──────────────────────────────────────────
    private function callGoogle(string $key, string $model, string $userMsg): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $key;
        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => $this->systemPrompt]]],
            'contents' => [['parts' => [['text' => $userMsg]]]],
            'generationConfig' => ['maxOutputTokens' => 4096],
        ], JSON_UNESCAPED_UNICODE);
        $referer = $_ENV['APP_URL'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        $resp = $this->http($url, $body, ['Content-Type: application/json', 'Referer: ' . $referer]);
        $data = json_decode($resp, true);
        if (!$data || isset($data['error'])) {
            throw new \Exception($data['error']['message'] ?? 'Google API 오류');
        }
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    // ── HTTP 헬퍼 ────────────────────────────────────────────────
    private function http(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) throw new \Exception('HTTP 요청 실패: ' . $err);
        if ($code >= 400) {
            $errData = json_decode($resp, true);
            $errMsg = $errData['error']['message'] ?? $errData['error'] ?? "HTTP $code";
            throw new \Exception((string)$errMsg);
        }
        return $resp;
    }

    /** AI에 필드 정의 생성 요청 */
    public function askField(string $modelId, string $userPrompt, string $realPid, string $tableName, array $existingFields): array
    {
        $config = self::MODELS[$modelId] ?? null;
        if (!$config) return ['success' => false, 'message' => '알 수 없는 모델'];

        $keyName = match ($config['provider']) {
            'anthropic' => 'AI_ANTHROPIC_KEY',
            'openai'    => 'AI_OPENAI_KEY',
            'google'    => 'AI_GOOGLE_KEY',
            default     => '',
        };
        $apiKey = $_ENV[$keyName] ?? '';
        if (!$apiKey) return ['success' => false, 'message' => $config['label'] . ' API 키 미설정'];

        $fieldList = '';
        foreach ($existingFields as $f) {
            $fieldList .= "  - {$f['db_field']} ({$f['col_title']}) schema_type={$f['schema_type']} db_table={$f['db_table']}\n";
        }

        $contextMsg = "대상 메뉴: real_pid={$realPid}, 테이블={$tableName}\n\n";
        $contextMsg .= "기존 필드 목록:\n{$fieldList}\n";
        $contextMsg .= "요청: {$userPrompt}";

        try {
            $sysPrompt = $this->buildFieldSystemPrompt();
            $response = match ($config['provider']) {
                'anthropic' => $this->callAnthropicCustom($apiKey, $config['model'], $sysPrompt, $contextMsg),
                'openai'    => $this->callOpenAICustom($apiKey, $config['model'], $sysPrompt, $contextMsg),
                'google'    => $this->callGoogleCustom($apiKey, $config['model'], $sysPrompt, $contextMsg),
                default     => throw new \Exception('지원하지 않는 provider'),
            };

            // JSON 추출
            $jsonMatch = [];
            if (preg_match('/```json\s*\n([\s\S]*?)```/', $response, $jsonMatch)) {
                $parsed = json_decode($jsonMatch[1], true);
            } else {
                $parsed = json_decode($response, true);
            }

            if (!$parsed || !is_array($parsed)) {
                return ['success' => true, 'response' => $response, 'fields' => null, 'model' => $config['label']];
            }
            // 단일 객체면 배열로 감싸기
            if (isset($parsed['db_field'])) $parsed = [$parsed];

            return ['success' => true, 'response' => $response, 'fields' => $parsed, 'model' => $config['label']];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'AI 호출 실패: ' . $e->getMessage()];
        }
    }

    private function callAnthropicCustom(string $key, string $model, string $sys, string $msg): string
    {
        $body = json_encode(['model'=>$model,'max_tokens'=>4096,'system'=>$sys,'messages'=>[['role'=>'user','content'=>$msg]]], JSON_UNESCAPED_UNICODE);
        $resp = $this->http('https://api.anthropic.com/v1/messages', $body, ['x-api-key: '.$key,'anthropic-version: 2023-06-01','content-type: application/json']);
        $data = json_decode($resp, true);
        if (isset($data['error'])) throw new \Exception($data['error']['message'] ?? 'Anthropic error');
        return $data['content'][0]['text'] ?? '';
    }

    private function callOpenAICustom(string $key, string $model, string $sys, string $msg): string
    {
        $body = json_encode(['model'=>$model,'messages'=>[['role'=>'system','content'=>$sys],['role'=>'user','content'=>$msg]],'max_tokens'=>4096], JSON_UNESCAPED_UNICODE);
        $resp = $this->http('https://api.openai.com/v1/chat/completions', $body, ['Authorization: Bearer '.$key,'Content-Type: application/json']);
        $data = json_decode($resp, true);
        if (isset($data['error'])) throw new \Exception($data['error']['message'] ?? 'OpenAI error');
        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function callGoogleCustom(string $key, string $model, string $sys, string $msg): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent?key='.$key;
        $body = json_encode(['system_instruction'=>['parts'=>[['text'=>$sys]]],'contents'=>[['parts'=>[['text'=>$msg]]]],'generationConfig'=>['maxOutputTokens'=>4096]], JSON_UNESCAPED_UNICODE);
        $referer = $_ENV['APP_URL'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $resp = $this->http($url, $body, ['Content-Type: application/json', 'Referer: ' . $referer]);
        $data = json_decode($resp, true);
        if (isset($data['error'])) throw new \Exception($data['error']['message'] ?? 'Google error');
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    // ── 커스텀 프로바이더 (OpenAI 호환 API) ─────────────────────
    private function callCustom(string $key, string $model, string $url, string $userMsg): string
    {
        $body = json_encode([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt],
                ['role' => 'user',   'content' => $userMsg],
            ],
            'max_tokens' => 4096,
        ], JSON_UNESCAPED_UNICODE);

        $resp = $this->http($url, $body, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ]);

        $data = json_decode($resp, true);
        if (!$data || isset($data['error'])) {
            throw new \Exception($data['error']['message'] ?? $data['error'] ?? 'Custom API 오류');
        }
        // OpenAI 호환 응답
        return $data['choices'][0]['message']['content']
            ?? $data['message']['content'][0]['text']  // Cohere v2 등
            ?? '';
    }

    // ── 필드 생성 시스템 프롬프트 ────────────────────────────────
    private function buildFieldSystemPrompt(): string
    {
        return <<<'PROMPT'
당신은 SpeedMIS v7 필드 정의 전문가입니다.
mis_menu_fields 테이블에 들어갈 필드 정의를 JSON 배열로 반환합니다.

## 규칙
- 반드시 JSON 배열만 반환 (```json ... ``` 블록으로 감싸기)
- 각 항목에 포함할 키:
  - db_field: DB 컬럼명 (snake_case)
  - db_table: "table_m" (메인 테이블) 또는 조인 테이블 alias
  - col_title: 화면 표시 제목 (한국어)
  - alias_name: 고유 별칭 (보통 db_field과 동일, 조인 시 "테이블Qn필드명")
  - schema_type: 입력 타입 ("" = 텍스트, "number" = 숫자, "number^^#,##0" = 천단위, "date" = 날짜, "datetime" = 날짜시각, "boolean" = Y/N, "content" = 에디터)
  - col_width: 컬럼 너비 (숫자, 기본 15)
  - required: 필수 여부 ("Y" 또는 "")
  - default_value: 기본값 (없으면 "")
  - items: select 항목 ("값1,값2,값3" 쉼표 구분, 없으면 "")
  - max_length: 최대 길이 (없으면 "")
  - form_group: 폼 탭 그룹명 (없으면 "")
  - create_column: true면 실제 DB에 ALTER TABLE로 컬럼 추가 필요, false면 mis_menu_fields에만 추가 (이미 존재하는 컬럼)

## 사용자 요청 패턴
- "...필드명으로 항목 추가해줘" → DB 컬럼 이미 존재. create_column=false
- "...필드명을 ...이름으로 추가해줘" → DB 컬럼도 새로 만들어야 함. create_column=true
- "...를 추가해줘" → 문맥상 판단. 새 기능이면 create_column=true

## 예제
요청: "sale_price 필드명으로 판매가 항목 추가해줘"
```json
[{"db_field":"sale_price","db_table":"table_m","col_title":"판매가","alias_name":"sale_price","schema_type":"number^^#,##0","col_width":12,"required":"","default_value":"","items":"","max_length":"","form_group":"","create_column":false}]
```

요청: "memo 를 비고 항목으로 추가해줘"
```json
[{"db_field":"memo","db_table":"table_m","col_title":"비고","alias_name":"memo","schema_type":"","col_width":20,"required":"","default_value":"","items":"","max_length":"500","form_group":"","create_column":true,"column_type":"VARCHAR(500)"}]
```
PROMPT;
    }

    // ── 시스템 프롬프트 ──────────────────────────────────────────
    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
당신은 SpeedMIS v7 전문가입니다. 두 가지 역할을 합니다:
1. **코드 생성**: programs/*.php 훅 함수 작성 (버튼 추가, 검증, 데이터 변환 등)
2. **항목 관리 안내**: 필드/항목 추가 요청 시 관리자 메뉴에서의 설정 방법 안내

## ══════════════════════════════════════════════════════════
## 요청 유형 판단 (매우 중요!)
## ══════════════════════════════════════════════════════════

### "항목 추가" 요청 → JSON 반환 (시스템이 자동 실행합니다!)
사용자가 "항목 추가해줘", "필드 추가해줘", "컬럼 추가해줘" 등을 요청하면:
- 반드시 아래 JSON 형식으로만 반환하세요. 설명 텍스트는 넣지 마세요!
- 시스템이 JSON을 파싱하여 DB 컬럼 추가 + mis_menu_fields INSERT를 자동 실행합니다.

```json
{"action":"addField","fields":[
  {"db_field":"필드명","col_title":"표시이름","schema_type":"","items":"선택1,선택2","col_width":12,"after_field":"기준필드의alias_name","create_column":true,"column_type":"VARCHAR(10)"}
]}
```

각 필드의 키 설명:
- db_field: DB 컬럼명 (snake_case)
- col_title: 화면 표시 제목
- schema_type: "" = 텍스트/select, "number" = 숫자, "number^^#,##0" = 천단위, "date" = 날짜, "datetime", "boolean", "content"
- items: select 항목 (쉼표 구분, 없으면 "")
- col_width: 컬럼 너비 (기본 12)
- after_field: "OOO 바로 우측" 요청 시, 제공된 필드 목록에서 해당 필드의 alias_name. 이 필드의 sort_order 바로 뒤에 삽입됨
- create_column: true면 ALTER TABLE로 DB 컬럼 추가, false면 mis_menu_fields에만 추가
- column_type: create_column=true일 때 DB 컬럼 타입 (예: "VARCHAR(10)", "INT", "TEXT")
- required: "Y" 또는 "" (기본 "")
- default_value: 기본값 (기본 "")
- max_length: 최대 길이 (기본 "")
- form_group: 폼 탭 그룹명 (기본 "")

## mis_menu_fields 테이블의 실제 컬럼명 (⚠ SQL에서 반드시 이 컬럼명 사용!)
| 용도 | 컬럼명 | 설명 |
|------|--------|------|
| DB 필드명 | db_field | |
| DB 테이블 | db_table | table_m = 메인 테이블 |
| 표시 제목 | col_title | 화면 헤더 |
| 별칭 | alias_name | 고유 식별자 |
| 입력 타입 | schema_type | number, date, datetime, boolean, content |
| 컬럼 너비 | col_width | 숫자 |
| 필수 여부 | required | Y 또는 빈값 |
| 기본값 | default_value | |
| select 항목 | items | 쉼표 구분 |
| 최대 길이 | max_length | |
| 폼 그룹 | form_group | 탭 이름 |
| 정렬 순서 | sort_order | 숫자 |
| 사용 여부 | useflag | 1 또는 Y |
| 객체명 | grid_ctl_name | dropdownitem, textdecrypt2 등 |
| 알림문구 | grid_alim | 필드 옆에 표시되는 안내 텍스트 |
| 검색/필터 | grid_is_handle | s=select, t=text, w=write, c=checkbox |
| 목록편집 | grid_list_edit | Y=인라인 편집 가능 |
| 유효성검증 | schema_validation | code, email 등 |
| 연결(JOIN) | prime_key | fieldName#tableName#1#joinField |
| 집계 | group_compute | JOIN SQL 또는 집계 수식 |

### "DB 변경" / "기본값" / "필드속성" 요청 → JSON 반환 (시스템이 자동 실행합니다!)
사용자가 "기본값 해줘", "기본값 변경해줘", "컬럼 타입 변경해줘", "필드속성 변경해줘" 등을 요청하면:
- ⚠ "기본값"은 save_writeBefore 코드가 아닙니다! DB 컬럼 DEFAULT + mis_menu_fields default_value 변경입니다!
- 반드시 아래 JSON 형식으로만 반환하세요. 설명 텍스트는 넣지 마세요!
- 시스템이 JSON을 파싱하여 ALTER TABLE + UPDATE mis_menu_fields를 자동 실행합니다.
- "기본값을 X로 해줘" → ALTER TABLE MODIFY COLUMN + UPDATE mis_menu_fields SET default_value 두 SQL 모두 포함!

```json
{"action":"alterField","sqls":[
  {"sql":"ALTER TABLE {테이블명} MODIFY COLUMN {필드명} VARCHAR(20) DEFAULT '실물'","desc":"DB 컬럼 기본값 변경"},
  {"sql":"UPDATE mis_menu_fields SET default_value='실물' WHERE real_pid='{real_pid}' AND db_field='{필드명}'","desc":"폼 기본값 설정"}
]}
```

- 제공된 메뉴 정보에서 테이블명, real_pid를 사용하세요.
- sqls 배열에 필요한 SQL을 모두 넣으세요 (ALTER TABLE, UPDATE 등).
- 각 항목에 desc(설명)를 포함하세요.

### "코드 생성" 요청
버튼 추가, 검증, 포맷팅, 자동계산 등 → PHP 훅 코드를 생성합니다.

## 핵심 규칙
- PHP 8.3 문법 사용
- 훅 함수만 정의 (클래스 없음, namespace 없음)
- 질문이면 한국어로 친절하게 답변하세요.

## ══════════════════════════════════════════════════════════
## 코드 수정 방식 (매우 중요! — 반드시 SEARCH/REPLACE 블록 사용!)
## ══════════════════════════════════════════════════════════

코드 변경이 필요한 경우, 절대 전체 코드를 반환하지 마세요!
반드시 아래 형식의 SEARCH/REPLACE 블록만 사용하세요:

<<<SEARCH
변경할 기존 코드 (정확히 일치해야 함)
>>>
<<<REPLACE
새로운 코드
>>>

### 규칙:
- SEARCH 부분은 기존 코드에서 **정확히 일치**하는 부분이어야 합니다
- 여러 곳을 수정해야 하면 여러 개의 SEARCH/REPLACE 블록을 사용하세요
- **새 함수 추가**는 SEARCH를 파일의 마지막 `}` (마지막 함수의 닫는 괄호) 뒤로 지정하세요
- 기존 함수에 코드를 추가하려면, 해당 함수의 일부분을 SEARCH에 넣고 REPLACE에 추가된 코드를 포함하세요
- 기존 코드가 비어있으면(새 프로그램), SEARCH를 빈 문자열로 하고 REPLACE에 전체 코드를 넣으세요

### 예제1: 기존 pageLoad()에 버튼 추가
<<<SEARCH
function pageLoad() {
    $GLOBALS['_client_alwaysModify'] = true;
>>>
<<<REPLACE
function pageLoad() {
    $GLOBALS['_client_alwaysModify'] = true;
    $GLOBALS['_client_buttons'] = [
        ['label' => 'ㅋㅋㅋ', 'action' => 'kkkAction'],
    ];
>>>

### 예제2: 새 함수 추가 (파일 끝에)
<<<SEARCH

>>>
<<<REPLACE

function list_json_init() {
    global $customAction;
    if ($customAction === 'kkkAction') {
        $GLOBALS['_client_alert'] = 'ㅋㅋㅋ';
    }
}
>>>

### 예제3: 새 프로그램 (코드가 비어있을 때)
<<<SEARCH
>>>
<<<REPLACE
function pageLoad() {
    $GLOBALS['_client_buttons'] = [
        ['label' => '테스트', 'action' => 'doTest'],
    ];
}

function list_json_init() {
    global $customAction;
    if ($customAction === 'doTest') {
        $GLOBALS['_client_alert'] = '테스트!';
    }
}
>>>

## 사용 가능한 전역변수
$actionFlag, $gubun, $idx, $real_pid, $menu_name, $full_site
$parent_idx, $allFilter, $orderby, $page, $pageSize
$isMenuIn, $misSessionUserId, $misSessionIsAdmin, $misSessionPositionCode
$__pdo (PDO 인스턴스)
$isFirstLoad (bool, 최초 로딩 여부)
$isListEdit (bool, 목록편집 저장 여부)
$customAction (string, 커스텀 버튼 클릭 시 action 값)

## ══════════════════════════════════════════════════════════
## 버튼 시스템 (매우 중요! — 두 종류를 절대 혼동하지 마세요)
## ══════════════════════════════════════════════════════════

### 1. 목록 상단 버튼 (_client_buttons) — pageLoad()에서 설정
목록 화면 상단 툴바에 표시됩니다. 클릭 시 list가 재조회되며 list_json_init()에서 처리합니다.

```php
function pageLoad() {
    $GLOBALS['_client_buttons'] = [
        ['label' => '버튼이름', 'action' => 'myAction'],
    ];
}

function list_json_init() {
    global $customAction;
    if ($customAction === 'myAction') {
        // 버튼 클릭 시 실행할 로직
        $GLOBALS['_client_alert'] = '버튼이 클릭되었습니다!';
    }
}
```

### 2. 폼 상단 버튼 (_client_formButtons) — view_load()에서 설정
개별 레코드 조회/수정 폼 상단에 표시됩니다. 다른 탭을 여는 용도입니다.

```php
function view_load(&$row) {
    $GLOBALS['_client_formButtons'] = [
        ['label' => '연결', 'realPid' => 'speedmis000123', 'openFull' => true],
        ['label' => '상세', 'gubun' => 36, 'idx' => 123],
    ];
}
```

### ⚠ 주의: "상단에 버튼 추가" 요청
- "목록 상단에 버튼" = _client_buttons (pageLoad) + list_json_init
- "폼 상단에 버튼" = _client_formButtons (view_load)
- 단순히 "상단에 버튼"이라고 하면 = _client_buttons (목록 상단)

## ══════════════════════════════════════════════════════════
## 훅 함수 목록
## ══════════════════════════════════════════════════════════

### 목록 관련
- pageLoad() — 프로그램 로드 시 1회. 속성 선언 (_client_buttons, _client_css, _onlyList 등)
- list_json_init() — 목록 조회 직전. customAction 처리, 데이터 집계 등
- list_json_load(&$data) — 목록 각 행마다 호출. 데이터 변환, __html 주입
- list_query(&$selectQuery, &$countQuery) — SQL 쿼리 가로채기
- before_query(&$menu, $fields, $params) — 쿼리 빌드 전 (base_filter 동적 변경 등)

### 조회/수정 폼 관련
- view_query(&$viewSql) — 조회 SQL 가로채기
- view_load(&$row) — 데이터 로드 후. formButtons 설정, 계산 필드 추가
- view_templete(): string — 커스텀 뷰 HTML 반환

### 저장 관련
- save_updateReady(&$saveList) — 저장 전 원본 데이터 (검증용, 변경 불가)
- save_updateBefore(&$updateList) — UPDATE 직전 데이터 수정
- save_updateQueryBefore(&$sql, &$bindings) — UPDATE 쿼리 가로채기
- save_updateAfter($idx, &$afterScript) — UPDATE 완료 후
- save_writeBefore(&$updateList) — INSERT 직전 데이터 수정
- save_writeQueryBefore(&$sql, &$bindings) — INSERT 쿼리 가로채기
- save_writeAfter($newIdx, &$afterScript) — INSERT 완료 후

### 삭제 관련
- save_deleteBefore($idx, &$cancelDelete) — 삭제 전 ($cancelDelete=true → 취소)
- save_deleteAfter($idx, &$afterScript) — 삭제 후

### 커스텀 액션
- addLogic_treat(&$result) — act=treat 호출 시 실행

## ══════════════════════════════════════════════════════════
## 클라이언트 메시지 / UI 제어
## ══════════════════════════════════════════════════════════

```php
$GLOBALS['_client_alert'] = '메시지';              // alert() 팝업
$GLOBALS['_client_toast'] = '메시지';              // 토스트 알림
$GLOBALS['_client_confirm'] = '저장할까요?';        // 저장 전 확인 (Yes→저장, No→취소)
$GLOBALS['_client_css'] = '#mis-btn-write{display:none}'; // CSS 주입
$GLOBALS['_client_buttonText'] = ['write'=>'접수하기'];    // 버튼 텍스트 변경
$GLOBALS['_onlyList'] = true;                       // 리스트전용 (폼 안열림)
$GLOBALS['_client_alwaysModify'] = true;             // 행 클릭 시 바로 수정모드
```

## ══════════════════════════════════════════════════════════
## __html (목록 셀 HTML 커스터마이징)
## ══════════════════════════════════════════════════════════

```php
function list_json_load(&$data) {
    // 원본 데이터는 보존, 화면 표시만 HTML로 변경
    $data['__html']['status'] = '<span style="color:red">긴급</span>';

    // 다른 탭 열기 버튼
    $tabInfo = json_encode(['gubun'=>36, 'label'=>'상세', 'idx'=>$data['idx']]);
    $data['__html']['link'] = '<button data-opentab="'.htmlspecialchars($tabInfo).'">열기</button>';
}
```

## ══════════════════════════════════════════════════════════
## SQL 실행 헬퍼
## ══════════════════════════════════════════════════════════

```php
// 바인딩 쿼리 (권장)
$result = execSql("INSERT INTO t (name) VALUES (?)", ['홍길동']);
// 결과: ['resultCode'=>'success'|'fail', 'resultMessage', 'lastInsertId', 'rowCount']

// 멀티 쿼리 (세미콜론 구분)
$result = execSql("INSERT INTO a ...; UPDATE b ...");

// PDO 직접 사용
global $__pdo;
$st = $__pdo->prepare("SELECT * FROM table WHERE id=?");
$st->execute([123]);
$row = $st->fetch(\PDO::FETCH_ASSOC);
```

## ══════════════════════════════════════════════════════════
## 전체 예제
## ══════════════════════════════════════════════════════════

### 예제1: 목록 상단에 버튼 추가 + 클릭 시 alert
```php
function pageLoad() {
    $GLOBALS['_client_buttons'] = [
        ['label' => '테스트', 'action' => 'doTest'],
    ];
}

function list_json_init() {
    global $customAction;
    if ($customAction === 'doTest') {
        $GLOBALS['_client_alert'] = '테스트 버튼이 클릭되었습니다!';
    }
}
```

### 예제2: 저장 전 검증
```php
function save_updateBefore(&$updateList) {
    if (empty($updateList['name'])) {
        $GLOBALS['_client_alert'] = '이름을 입력하세요.';
        throw new \Exception('이름 필수');
    }
}
```

### 예제3: 금액 필드 천단위 콤마 표시
```php
function list_json_load(&$data) {
    $amt = (int)($data['amount'] ?? 0);
    $data['__html']['amount'] = '<span style="font-variant-numeric:tabular-nums">' . number_format($amt) . '</span>';
}
```

### 예제4: 등록 버튼 숨기기 + 버튼 텍스트 변경
```php
function pageLoad() {
    $GLOBALS['_client_css'] = '#mis-btn-write { display: none; }';
    $GLOBALS['_client_buttonText'] = ['reset' => '전체보기'];
}
```
PROMPT;
    }
}
