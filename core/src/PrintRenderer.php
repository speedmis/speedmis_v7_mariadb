<?php
namespace App;

/**
 * 인쇄양식 템플릿 렌더러
 *
 * 문법:
 *   {{alias_name}}                        → 레코드 필드값
 *   {{#each childAlias}}...{{/each}}      → child 프로그램 루프
 *   루프 안에서 {{alias_name}}            → child 레코드 필드값
 *   {{@index}}                            → 루프 인덱스 (1부터)
 *   {{@total}}                            → child 전체 건수
 */
class PrintRenderer
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function render(string $template, array $data, array $fields, $parentIdx, string $mode = 'view'): string // RENDER_START_LOG
    {
        // Increase PCRE backtrack limit for large templates
        ini_set('pcre.backtrack_limit', '10000000');
        // child 필드 맵: alias_name → real_pid
        $childMap = [];
        foreach ($fields as $f) {
            if (($f['grid_ctl_name'] ?? '') === 'child' && !empty($f['default_value'])) {
                $childMap[$f['alias_name']] = trim($f['default_value']);
            }
        }

        $html = $template; // Initialize html from template

        // ═══ v6 호환: {탭이름:시작}...{탭이름:끝} 반복 + {탭이름.항목명} 치환 ═══
        // child 필드 맵: col_title → real_pid (v6는 한글 탭이름 사용)
        $childTitleMap = [];
        foreach ($fields as $f) {
            if (($f['grid_ctl_name'] ?? '') === 'child' && !empty($f['default_value'])) {
                $childTitleMap[trim($f['col_title'] ?? '')] = trim($f['default_value']);
            }
        }

        // {탭이름:시작} ... {탭이름:끝} 패턴 찾기
        // 시작/끝 태그가 포함된 <tr>...</tr> 블록을 반복
        foreach ($childTitleMap as $tabTitle => $childRealPid) {
            $startTag = '{' . $tabTitle . ':시작}';
            $endTag = '{' . $tabTitle . ':끝}';

            @file_put_contents('/tmp/print_debug.log',
                "  startTag bytes: " . bin2hex($startTag) . "\n" .
                "  endTag bytes: " . bin2hex($endTag) . "\n" .
                "  found start: " . var_export(strpos($html, $startTag), true) . "\n" .
                "  found end: " . var_export(strpos($html, $endTag), true) . "\n",
                FILE_APPEND);
            if (strpos($html, $startTag) === false || strpos($html, $endTag) === false) continue;

            // Find the <tr> containing 시작 tag and </tr> containing 끝 tag
            $startPos = strpos($html, $startTag);
            $endPos = strpos($html, $endTag);
            if ($startPos === false || $endPos === false) continue;

            // Find the enclosing <tr> before startTag
            $trStart = strrpos(substr($html, 0, $startPos), '<tr');
            if ($trStart === false) $trStart = $startPos;

            // Find the </tr> after endTag
            $trEnd = strpos($html, '</tr>', $endPos);
            if ($trEnd === false) $trEnd = $endPos + strlen($endTag);
            else $trEnd += 5; // include </tr>

            // rowspan 동반 행 포함: 템플릿 행에 rowspan이 있으면 뒤따르는 빈 <tr>...</tr>도 포함
            // 단, 동반 행에 {추가:} 또는 {삭제:} 가 있으면 반복 영역에 포함하지 않음
            $templateChunk = substr($html, $trStart, $trEnd - $trStart);
            if (preg_match('/rowspan\s*=\s*"?(\d+)/i', $templateChunk, $rsm) && (int)$rsm[1] > 1) {
                $extraRows = (int)$rsm[1] - 1;
                $scanPos = $trEnd;
                for ($ri = 0; $ri < $extraRows; $ri++) {
                    $nextTrEnd = strpos($html, '</tr>', $scanPos);
                    if ($nextTrEnd !== false) {
                        $extraChunk = substr($html, $scanPos, $nextTrEnd + 5 - $scanPos);
                        if (str_contains($extraChunk, '{추가:') || str_contains($extraChunk, '{삭제:')) break;
                        // <td>가 있는 행은 독립 행이므로 companion이 아님
                        if (str_contains($extraChunk, '<td')) break;
                        $scanPos = $nextTrEnd + 5;
                    }
                }
                $trEnd = $scanPos;
            }

            // Extract the template row
            $rowTemplate = substr($html, $trStart, $trEnd - $trStart);

            // Remove start/end tags from template
            $rowTemplate = str_replace($startTag, '', $rowTemplate);
            $rowTemplate = str_replace($endTag, '', $rowTemplate);

            // 학습평가표: {학습발달내용} 앞에 {내용} div 삽입 (학습계획내용 표시용)
            if ($tabTitle === '학습평가표' && str_contains($rowTemplate, '{학습평가표.학습발달내용}')) {
                $rowTemplate = str_replace(
                    '{학습평가표.학습발달내용}',
                    '<div title="학습계획내용" class="addViewContents">{학습평가표.내용}</div><div title="학습발달내용" class="intoDown">{학습평가표.학습발달내용}</div>',
                    $rowTemplate
                );
            }

            // Fetch child data
            $childRows = $this->fetchChildData($childRealPid, $parentIdx);

            // Build col_title → alias_name map for child program
            $childFieldMap = $this->getChildFieldMap($childRealPid);

            // modify 모드: child 필드 정보 (입력 컨트롤 생성용)
            $childFieldInfo = [];
            if ($mode === 'modify') {
                $cFields = $this->getChildFields($childRealPid);
                foreach ($cFields as $cf) {
                    $ct = trim($cf['col_title'] ?? '');
                    if ($ct !== '') $childFieldInfo[$ct] = $cf;
                    foreach (explode(',', $ct) as $part) {
                        $part = trim($part);
                        if ($part !== '' && $part !== $ct) $childFieldInfo[$part] = $cf;
                    }
                }
            }

            // Generate rows
            $output = '';
            if (!empty($childRows)) {
                foreach ($childRows as $i => $row) {
                    $rowHtml = $rowTemplate;
                    $rowIdx = $row['idx'] ?? ($i + 1);
                    // Replace {탭이름.항목명} with data or input control
                    $rowHtml = preg_replace_callback('/\{' . preg_quote($tabTitle, '/') . '\.([^}]+)\}/', function ($m) use ($row, $childFieldMap, $childFieldInfo, $mode, $childRealPid, $rowIdx) {
                        $colTitle = $m[1];
                        $mapEntry = $childFieldMap[$colTitle] ?? null;
                        $alias = $mapEntry['alias'] ?? $colTitle;
                        $schemaType = $mapEntry['schema_type'] ?? '';
                        $value = $row[$alias] ?? $row[$colTitle] ?? '';

                        if ($mode !== 'modify') {
                            $display = str_contains($schemaType, '^^') ? $this->formatBySchema($value, $schemaType) : (string)$value;
                            return nl2br(htmlspecialchars($display, ENT_QUOTES, 'UTF-8'));
                        }

                        $cf = $childFieldInfo[$colTitle] ?? null;
                        if (!$cf) return nl2br(htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));

                        $ctlName = trim($cf['grid_ctl_name'] ?? '');
                        // grid_ctl_name이 비어있으면 표시전용 (입력컨트롤 생성 안 함)
                        if ($ctlName === '') return nl2br(htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
                        $maxLen = $cf['max_length'] ?? '';
                        $items = $cf['items'] ?? '';
                        $inputName = 'child_' . $childRealPid . '_' . $rowIdx . '_' . $alias;
                        $isRequired = trim((string)($cf['required'] ?? '')) === 'Y';
                        $dataAttr = ' data-child-real-pid="' . $childRealPid . '" data-child-idx="' . $rowIdx . '" data-alias="' . $alias . '" class="viewPrint-child-input"' . ($isRequired ? ' data-required="1"' : '');

                        switch ($ctlName) {
                            case 'textarea':
                                $rows = max(2, min(10, (int)($maxLen ?: 100) / 30));
                                return '<textarea name="' . $inputName . '"' . $dataAttr . ' style="width:100%;box-sizing:border-box;font-size:13px;padding:2px;margin:0;display:block;" rows="' . $rows . '">'
                                     . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</textarea>';

                            case 'dropdownitem':
                                $options = '<option value="">선택</option>';
                                if ($items !== '') {
                                    if (str_starts_with(trim($items), '[')) {
                                        $jsonItems = json_decode($items, true);
                                        if ($jsonItems) {
                                            foreach ($jsonItems as $ji) {
                                                $v = $ji['value'] ?? $ji;
                                                $t = $ji['text'] ?? $v;
                                                $sel = ((string)$v === (string)$value) ? ' selected' : '';
                                                $options .= '<option value="' . htmlspecialchars((string)$v) . '"' . $sel . '>' . htmlspecialchars((string)$t) . '</option>';
                                            }
                                        }
                                    } else {
                                        foreach (explode(',', $items) as $item) {
                                            $item = trim($item);
                                            $sel = ($item === (string)$value) ? ' selected' : '';
                                            $options .= '<option value="' . htmlspecialchars($item) . '"' . $sel . '>' . htmlspecialchars($item) . '</option>';
                                        }
                                    }
                                }
                                return '<select name="' . $inputName . '"' . $dataAttr . ' style="font-size:13px;padding:2px;">' . $options . '</select>';

                            case 'check':
                                $checked = ($value === 'Y' || $value === '1') ? ' checked' : '';
                                return '<input type="checkbox" name="' . $inputName . '"' . $dataAttr . ' value="Y"' . $checked . '>';

                            default:
                                $ml = $maxLen ? ' maxlength="' . $maxLen . '"' : '';
                                $roAttr = '';
                                // 필수과목(pilsuyeobu=Y)이면 과목 필드는 읽기전용
                                $isPilsu = ($row['pilsuyeobu'] ?? '') === 'Y';
                                if ($isPilsu && $alias === 'gwamok') {
                                    $roAttr = ' readonly title="필수과목은 수정할 수 없습니다." style="width:100%;box-sizing:border-box;font-size:13px;padding:2px;background:#f0f0f0;color:#888;"';
                                    return '<input type="text" name="' . $inputName . '"' . $dataAttr . ' value="'
                                         . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"' . $roAttr . $ml . '>';
                                }
                                return '<input type="text" name="' . $inputName . '"' . $dataAttr . ' value="'
                                     . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
                                     . '" style="width:100%;height:100%;box-sizing:border-box;font-size:13px;padding:2px;margin:0;display:block;"' . $ml . '>';
                        }
                    }, $rowHtml);
                    // modify 모드: 삭제 체크박스 삽입 ({추가:} 또는 {삭제:} 패턴이 있는 템플릿만)
                    if ($mode === 'modify' && (str_contains($template, '{추가:') || str_contains($template, '{삭제:'))) {
                        $isPilsu = ($row['pilsuyeobu'] ?? '') === 'Y';
                        $disabledAttr = $isPilsu ? ' disabled title="필수과목은 삭제할 수 없습니다."' : '';
                        $chkHtml = '<input type="checkbox" class="viewPrint-deleteCheck" style="position:absolute;left:2px;top:2px;margin:0;z-index:1;width:16px;height:16px;accent-color:#e53e3e;cursor:pointer;" title="삭제 선택"' . $disabledAttr . '>';
                        // 첫 번째 <td>에 position:relative 추가 (기존 style 병합) + 체크박스 absolute 배치
                        $rowHtml = preg_replace_callback('/<td([^>]*)>/', function($m) use ($chkHtml) {
                            $attrs = $m[1];
                            if (preg_match('/style\s*=\s*"([^"]*)"/i', $attrs, $sm)) {
                                $attrs = preg_replace('/style\s*=\s*"([^"]*)"/i', 'style="$1;position:relative;"', $attrs);
                            } elseif (preg_match("/style\s*=\s*'([^']*)'/i", $attrs, $sm)) {
                                $attrs = preg_replace("/style\s*=\s*'([^']*)'/i", "style='$1;position:relative;'", $attrs);
                            } else {
                                $attrs .= ' style="position:relative;"';
                            }
                            return '<td' . $attrs . '>' . $chkHtml;
                        }, $rowHtml, 1);
                    }
                    $output .= $rowHtml;
                }
            }

            // Replace the original row template with generated rows
            $html = substr($html, 0, $trStart) . $output . substr($html, $trEnd);
        }

        // {추가:...} 태그 제거 (v6 입력폼 전용 버튼)
        if ($mode === 'modify') {
            // 추가 버튼
            $html = preg_replace_callback('/\{추가:([^}]+)\}/', function ($m) {
                $label = $m[1];
                return '<button type="button" class="btn btn-sm btn-primary viewPrint-addRow" style="margin:2px;font-size:11px;" onclick="viewPrint_addChildRow(this)">'
                     . htmlspecialchars($label) . '</button>';
            }, $html);
            // 삭제 버튼
            $html = preg_replace_callback('/\{삭제:([^}]+)\}/', function ($m) {
                $label = $m[1];
                return '<button type="button" class="btn btn-sm btn-danger viewPrint-deleteRow" style="margin:2px;font-size:11px;background:#e55;color:#fff;border:1px solid #c33;border-radius:4px;padding:2px 8px;cursor:pointer;" onclick="viewPrint_deleteChildRows(this)">'
                     . htmlspecialchars($label) . '</button>';
            }, $html);
        } else {
            $html = preg_replace('/\{추가:[^}]+\}/', '', $html);
            $html = preg_replace('/\{삭제:[^}]+\}/', '', $html);
        }

        // {{#each childAlias}}...{{/each}} 처리 + 합계 계산
        $childSums = []; // alias → [ field => sum ]
        $childCounts = []; // alias → count
        $html_each = preg_replace_callback(
            '/\{\{#each\s+(\w+)\}\}(.*?)\{\{\/each\}\}/s',
            function ($m) use ($childMap, $parentIdx, &$childSums, &$childCounts) {
                $alias = $m[1];
                $rowTpl = $m[2];
                $realPid = $childMap[$alias] ?? null;
                if (!$realPid) return "<!-- child '{$alias}' not found -->";

                $childRows = $this->fetchChildData($realPid, $parentIdx);
                $total = count($childRows);
                $childCounts[$alias] = $total;
                $sums = [];
                $out = '';
                foreach ($childRows as $i => $row) {
                    $s = $rowTpl;
                    $s = str_replace('{{@index}}', (string)($i + 1), $s);
                    $s = str_replace('{{@total}}', (string)$total, $s);
                    $s = preg_replace_callback('/\{\{(\w+)\}\}/', function ($fm) use ($row, &$sums) {
                        $val = $row[$fm[1]] ?? '';
                        if (is_numeric($val)) {
                            $sums[$fm[1]] = ($sums[$fm[1]] ?? 0) + (float)$val;
                            return number_format((float)$val);
                        }
                        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
                    }, $s);
                    $out .= $s;
                }
                $childSums[$alias] = $sums;
                return $out;
            },
            $html
        );
        if ($html_each !== null) $html = $html_each;

        // {{@sum_필드명}} → 해당 child 루프의 합계값 치환, {{@total}} → 건수
        // child가 1개면 alias 생략해도 매칭
        $firstChildAlias = array_key_first($childSums) ?? '';
        $html = preg_replace_callback('/\{\{@(sum_(\w+)|total)\}\}/', function ($m) use ($childSums, $childCounts, $firstChildAlias) {
            if ($m[1] === 'total') {
                return (string)($childCounts[$firstChildAlias] ?? 0);
            }
            $field = $m[2];
            $sums = $childSums[$firstChildAlias] ?? [];
            $val = $sums[$field] ?? 0;
            return number_format((float)$val);
        }, $html);

        // v6 호환: {col_title} 패턴 치환 (한글 항목명)
        // col_title → alias_name + field info 매핑 생성
        $titleToAlias = [];
        $titleToField = []; // col_title → full field info
        foreach ($fields as $f) {
            $title = trim($f['col_title'] ?? '');
            $alias = $f['alias_name'] ?? '';
            if ($title !== '' && $alias !== '') {
                $titleToAlias[$title] = $alias;
                $titleToField[$title] = $f;
                $parts = explode(',', $title);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part !== '' && $part !== $title) {
                        $titleToAlias[$part] = $alias;
                        $titleToField[$part] = $f;
                    }
                }
            }
        }

        $currentMode = $mode;
        $html = preg_replace_callback('/\{([^{}]+)\}/', function ($m) use ($data, $titleToAlias, $titleToField, $currentMode) {
            $key = $m[1];
            $alias = $titleToAlias[$key] ?? null;
            if (!$alias && isset($data[$key])) $alias = $key;
            if (!$alias) return $m[0];

            $value = $data[$alias] ?? '';
            $field = $titleToField[$key] ?? null;
            $ctlName = trim($field['grid_ctl_name'] ?? '');

            // VIEW 모드: 값만 표시 (textarea 줄바꿈 보존)
            // 단, check 타입은 값이 비어있으면(미체크) 텍스트가 없어 자리 자체가 사라져
            // "필드가 아예 없다"로 오인되므로, DataForm.jsx 의 readOnly 체크박스와 동일하게
            // 값 유무와 무관하게 항상 비활성 체크박스로 표시.
            if ($currentMode !== 'modify' || !$field) {
                if ($field && $ctlName === 'check') {
                    $checked = ($value === 'Y' || $value === '1' || $value === 'true') ? ' checked' : '';
                    return '<input type="checkbox" disabled' . $checked . '>';
                }
                $fieldSchemaType = (string)($field['schema_type'] ?? '');
                $display = str_contains($fieldSchemaType, '^^') ? $this->formatBySchema($value, $fieldSchemaType) : (string)$value;
                return nl2br(htmlspecialchars($display, ENT_QUOTES, 'UTF-8'));
            }

            // MODIFY 모드: grid_ctl_name에 따라 입력 객체 생성
            // grid_ctl_name이 비어있으면 표시전용
            if ($ctlName === '') return nl2br(htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
            $maxLen = $field['max_length'] ?? '';
            $items = $field['items'] ?? '';
            $inputId = 'ud_' . $alias;
            $reqAttr = (trim((string)($field['required'] ?? '')) === 'Y') ? ' data-required="1"' : '';

            switch ($ctlName) {
                case 'textarea':
                    $rows = max(2, min(10, (int)($maxLen ?: 100) / 30));
                    return '<textarea id="' . $inputId . '" name="' . $alias . '"' . $reqAttr . ' style="width:100%;height:100%;box-sizing:border-box;font-size:13px;padding:2px;margin:0;display:block;" rows="' . $rows . '">'
                         . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</textarea>';

                case 'check':
                    $checked = ($value === 'Y' || $value === '1' || $value === 'true') ? ' checked' : '';
                    return '<input type="checkbox" id="' . $inputId . '" name="' . $alias . '"' . $reqAttr . ' value="Y"' . $checked . '>';

                case 'dropdownitem':
                    $options = '<option value="">선택</option>';
                    $itemList = [];
                    if ($items !== '') {
                        // JSON array or comma-separated
                        if (str_starts_with(trim($items), '[')) {
                            $jsonItems = json_decode($items, true);
                            if ($jsonItems) {
                                foreach ($jsonItems as $ji) {
                                    $v = $ji['value'] ?? $ji;
                                    $t = $ji['text'] ?? $v;
                                    $sel = ((string)$v === (string)$value) ? ' selected' : '';
                                    $options .= '<option value="' . htmlspecialchars((string)$v) . '"' . $sel . '>' . htmlspecialchars((string)$t) . '</option>';
                                }
                            }
                        } else {
                            foreach (explode(',', $items) as $item) {
                                $item = trim($item);
                                $sel = ($item === (string)$value) ? ' selected' : '';
                                $options .= '<option value="' . htmlspecialchars($item) . '"' . $sel . '>' . htmlspecialchars($item) . '</option>';
                            }
                        }
                    }
                    return '<select id="' . $inputId . '" name="' . $alias . '"' . $reqAttr . ' style="font-size:13px;padding:2px;">' . $options . '</select>';

                case 'text':
                default:
                    if ($ctlName === '' && $maxLen === '') {
                        // No control = read-only display
                        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                    }
                    $ml = $maxLen ? ' maxlength="' . $maxLen . '"' : '';
                    return '<input type="text" id="' . $inputId . '" name="' . $alias . '"' . $reqAttr . ' value="'
                         . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
                         . '" style="width:100%;box-sizing:border-box;font-size:13px;padding:2px;"' . $ml . '>';
            }
        }, $html);

        // 메인 레코드 {{field}} 치환
        $html = preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($data) {
            $key = $m[1];
            if ($key === '@index' || $key === '@total') return $m[0];
            return htmlspecialchars($data[$key] ?? '', ENT_QUOTES, 'UTF-8');
        }, $html);

        // v6 인쇄양식 CSS 보정: a 태그 색상 상속
        $printCss = '<style>'
            . '.viewPrintDiv a, div[id*="SpeedMIS"] a, .k-table a { color: inherit !important; text-decoration: none !important; }'
            . ' .viewPrint-child-input, .print-content input[id^="ud_"], .print-content textarea[id^="ud_"], .print-content select[id^="ud_"] { background: transparent !important; border: 1px dashed #4F6EF7 !important; border-radius: 0; } .print-content textarea.viewPrint-child-input, .print-content textarea[id^="ud_"] { border: none !important; }'
            . ' td:has(.viewPrint-child-input), td:has(input[id^="ud_"]), td:has(textarea[id^="ud_"]), td:has(select[id^="ud_"]) { background: #F3F5F9 !important; }'
            . ' td:has(.viewPrint-child-input[data-required="1"]), td:has(input[data-required="1"]), td:has(textarea[data-required="1"]), td:has(select[data-required="1"]) { background: #BCD4F5 !important; }'
            . ' .print-content input[type="checkbox"], .print-content input[type="radio"] { accent-color: var(--color-primary, #4F6EF7); }'
            . ' .print-content input[readonly] { background: #f0f0f0 !important; border-color: #ddd !important; color: #888; }'
            . ' .print-content td[rowspan] { position: relative; } .print-content td[rowspan] textarea { position: absolute; top: 0; left: 0; width: 100% !important; height: 100% !important; }'
            . ' .addViewContents { background: #f0f0f0; padding: 5px; min-height: 40px; white-space: pre-wrap; margin-bottom: 3px; padding-bottom: 3px; border-bottom: 1px dashed #888; font-size: 12px; color: #555; line-height: 1.4; }'
            . ' .intoDown { padding: 0; }'
            . ' .intoDown textarea { position: relative !important; width: 100% !important; height: auto !important; min-height: 80px; }'
            . ' [data-theme="dark"] .print-content { filter: invert(1) hue-rotate(180deg); }'
            . ' [data-theme="dark"] .print-content img { filter: invert(1) hue-rotate(180deg); }'
            . ' [data-theme="dark"] .print-content input[type="checkbox"], [data-theme="dark"] .print-content input[type="radio"] { filter: invert(1) hue-rotate(180deg); }'
            . ' [data-theme="dark"] .print-content input[type="checkbox"]:disabled { opacity: 0.3; }'
            . ' [data-theme="dark"] .print-content input[type="text"], [data-theme="dark"] .print-content textarea, [data-theme="dark"] .print-content select { color-scheme: light; color: inherit !important; background-color: transparent !important; }'
            . '</style>';
        return $printCss . $html;
    }

    /**
     * child 프로그램 데이터 조회 — DataHandler의 list API를 내부 호출
     */
    private function fetchChildData(string $realPid, $parentIdx): array
    {
        try {
            $isMssql = \App\Config\Database::isMssql();
            $q = $isMssql ? fn($s) => "[{$s}]" : fn($s) => "`{$s}`";
            $ifnull = $isMssql ? 'ISNULL' : 'IFNULL';

            // Get child menu info
            $stmt = $this->pdo->prepare('SELECT table_name FROM mis_menus WHERE real_pid = ?');
            $stmt->execute([$realPid]);
            $tableName = $stmt->fetchColumn();
            if (!$tableName) return [];

            // 자동 입력: child 데이터가 없으면 필수과목 자동 생성 (edu_N_ghsa_new_detail)
            if ($tableName === 'edu_N_ghsa_new_detail') {
                $chkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$q($tableName)} WHERE midx = ? AND useflag = '1'");
                $chkStmt->execute([$parentIdx]);
                if ((int)$chkStmt->fetchColumn() === 0) {
                    $this->autoInsertPilsu($parentIdx);
                }
            }

            // Get child fields
            $fieldStmt = $this->pdo->prepare("SELECT sort_order, alias_name, db_field, db_table FROM mis_menu_fields WHERE real_pid = ? AND useflag = '1' AND db_field IS NOT NULL AND db_field != '' ORDER BY sort_order");
            $fieldStmt->execute([$realPid]);
            $allFields = $fieldStmt->fetchAll(\PDO::FETCH_ASSOC);

            // table_m 필드만 SELECT 대상
            $selectFields = array_filter($allFields, fn($f) => ($f['db_table'] ?? '') === 'table_m');

            if (empty($selectFields)) {
                $dataStmt = $this->pdo->prepare("SELECT * FROM {$q($tableName)} WHERE midx = ? ORDER BY idx");
                $dataStmt->execute([$parentIdx]);
                return $dataStmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            // Build SELECT
            $selectParts = [];
            foreach ($selectFields as $f) {
                $dbField = $f['db_field'];
                $alias = $f['alias_name'];
                if (preg_match('/[\s(\'"]/', $dbField)) {
                    // 수식 필드
                    $selectParts[] = "({$dbField}) AS {$q($alias)}";
                } else {
                    $selectParts[] = "table_m.{$q($dbField)} AS {$q($alias)}";
                }
            }
            $selectSql = implode(', ', $selectParts);

            // WHERE: parent_idx → '_-_' 복합 키 분리 → sort_order 2번,3번,... 필드에 매칭
            $parentParts = str_contains((string)$parentIdx, '_-_') ? explode('_-_', (string)$parentIdx) : [(string)$parentIdx];
            $whereParts = [];
            $bindings = [];

            if (count($parentParts) === 1 && count($allFields) >= 2) {
                // 단일 키: 2번 필드로 매칭
                $fk = $allFields[1] ?? null;
                if ($fk) {
                    $fkDb = $fk['db_field'];
                    $fkTbl = $fk['db_table'] ?? '';
                    if ($fkTbl === '' || preg_match('/[\s(\'"]/', $fkDb)) {
                        $whereParts[] = "({$fkDb}) = ?";
                    } else {
                        $whereParts[] = "table_m.{$q($fkDb)} = ?";
                    }
                    $bindings[] = trim($parentParts[0]);
                }
            } else {
                // 복합 키: 2번,3번,... 필드에 순서대로 매칭
                for ($pi = 0; $pi < count($parentParts); $pi++) {
                    $fk = $allFields[$pi + 1] ?? null; // +1: 첫 번째는 PK
                    if (!$fk) break;
                    $fkDb = $fk['db_field'];
                    $fkTbl = $fk['db_table'] ?? '';
                    if ($fkTbl === '' || preg_match('/[\s(\'"]/', $fkDb)) {
                        $whereParts[] = "({$fkDb}) = ?";
                    } else {
                        $whereParts[] = "table_m.{$q($fkDb)} = ?";
                    }
                    $bindings[] = trim($parentParts[$pi]);
                }
            }

            if (empty($whereParts)) {
                // fallback: midx
                $whereParts[] = "table_m.midx = ?";
                $bindings[] = $parentIdx;
            }

            $whereSql = implode(' AND ', $whereParts);
            $sql = "SELECT {$selectSql} FROM {$q($tableName)} table_m WHERE {$whereSql} ORDER BY table_m.idx";
            $dataStmt = $this->pdo->prepare($sql);
            $dataStmt->execute($bindings);
            return $dataStmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            @file_put_contents('/tmp/print_debug.log', "FETCH_CHILD_ERROR: " . $e->getMessage() . " | SQL: " . ($sql ?? '') . "\n", FILE_APPEND);
            return [];
        }
    }

    private ?DataHandler $handlerCache = null;

    private function getDataHandler(): DataHandler
    {
        if ($this->handlerCache) return $this->handlerCache;
        $qb = new \App\QueryBuilder();
        $cache = new \App\MisCache();
        // 간이 logger
        $logger = new class implements \Psr\Log\LoggerInterface {
            public function emergency($m, array $c = []): void {}
            public function alert($m, array $c = []): void {}
            public function critical($m, array $c = []): void {}
            public function error($m, array $c = []): void {}
            public function warning($m, array $c = []): void {}
            public function notice($m, array $c = []): void {}
            public function info($m, array $c = []): void {}
            public function debug($m, array $c = []): void {}
            public function log($level, $m, array $c = []): void {}
        };
        $fm = new \App\FileManager($this->pdo);
        $this->handlerCache = new DataHandler($this->pdo, $qb, $cache, $logger, $fm);
        return $this->handlerCache;
    }

    /**
     * child 프로그램의 col_title → alias_name 매핑
     */
    private function getChildFieldMap(string $realPid): array
    {
        $stmt = $this->pdo->prepare("SELECT alias_name, col_title, schema_type FROM mis_menu_fields WHERE real_pid = ? AND useflag = '1'");
        $stmt->execute([$realPid]);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $f) {
            $title = trim($f['col_title'] ?? '');
            if ($title !== '') $map[$title] = ['alias' => $f['alias_name'], 'schema_type' => $f['schema_type'] ?? ''];
        }
        return $map;
    }

    /**
     * 웹소스상세 schema_type 포맷 (v6 호환, DataGrid.jsx formatBySchema 와 동일 규칙): "<type>^^<format>"
     *   number^^#,##0        → 888,888,888
     *   number^^###.00       → 22.50
     *   date^^yyyy-MM-dd     → 2026-12-30
     */
    private function formatBySchema($val, string $schemaType): string
    {
        if ($val === null || $val === '') return '';
        $sepPos = strpos($schemaType, '^^');
        if ($sepPos === false) return (string)$val;
        $type = strtolower(trim(substr($schemaType, 0, $sepPos)));
        $fmt = substr($schemaType, $sepPos + 2);

        if ($type === 'number') {
            if (!is_numeric($val)) return (string)$val;
            $num = (float)$val;
            return preg_replace_callback('/[#,0]+(?:\.0+)?/', function ($m) use ($num) {
                [$intTok, $decTok] = array_pad(explode('.', $m[0]), 2, '');
                $dec = strlen($decTok);
                $hasComma = str_contains($intTok, ',');
                $isZeroPad = (bool)preg_match('/^0+$/', $intTok);
                if ($isZeroPad && $dec === 0) {
                    return str_pad((string)(int)$num, strlen($intTok), '0', STR_PAD_LEFT);
                }
                return number_format($num, $dec, '.', $hasComma ? ',' : '');
            }, $fmt, 1);
        }

        if ($type === 'date' || $type === 'datetime') {
            $s = (string)$val;
            if ($s === '' || str_starts_with($s, '0000-00-00')) return '';
            $ts = strtotime($s);
            if ($ts === false) return $s;
            $map = ['yyyy' => date('Y', $ts), 'MM' => date('m', $ts), 'dd' => date('d', $ts),
                    'HH' => date('H', $ts), 'mm' => date('i', $ts), 'ss' => date('s', $ts)];
            return preg_replace_callback('/yyyy|MM|dd|HH|mm|ss/', fn($m) => (string)$map[$m[0]], $fmt);
        }

        return (string)$val;
    }

    /**
     * child 프로그램의 전체 필드 정보 (입력 객체 생성용)
     */
    private function getChildFields(string $realPid): array
    {
        $stmt = $this->pdo->prepare("SELECT alias_name, col_title, grid_ctl_name, max_length, items, required FROM mis_menu_fields WHERE real_pid = ? AND useflag = '1' ORDER BY sort_order");
        $stmt->execute([$realPid]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * 필수과목 자동 입력 — edu_N_ghsa_new의 gh3.gh4.gh5 조합에 맞는 필수과목을 edu_N_ghsa_pil에서 가져와 detail에 INSERT
     */
    private function autoInsertPilsu($parentIdx): void
    {
        try {
            $stmt = $this->pdo->prepare("SELECT gh3, gh4, gh5 FROM edu_N_ghsa_new WHERE idx = ?");
            $stmt->execute([$parentIdx]);
            $parent = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$parent) return;

            $item = ($parent['gh3'] ?? '') . '.' . ($parent['gh4'] ?? '') . '.' . ($parent['gh5'] ?? '');
            $colMap = [
                '미취학.국문.자율관리' => '미취학자율관리',
                '초등.국문.자율관리'   => '초등자율관리',
                '중등.국문.자율관리'   => '중등자율관리',
                '미취학.국문.GHSA'     => '미취학GHSA',
                '초등.국문.GHSA'       => '초등GHSA',
                '중등.국문.GHSA'       => '중등GHSA',
                '고등.국문.GHSA'       => '고등GHSA',
                '초등.영문.GHSA'       => '초등워커스빌',
                '중등.영문.GHSA'       => '중등워커스빌',
                '고등.영문.GHSA'       => '고등워커스빌',
            ];

            $col = $colMap[$item] ?? null;
            if (!$col) return;

            $wdater = $GLOBALS['misSessionUserId'] ?? 'system';
            $sql = "INSERT INTO edu_N_ghsa_new_detail (midx, [필수여부], [과목], [표준과정], wdater, useflag, wdate)
                    SELECT ?, 'Y', [$col], 1, ?, '1', GETDATE()
                    FROM edu_N_ghsa_pil WHERE ISNULL([$col], '') <> '' ORDER BY idx";
            $ins = $this->pdo->prepare($sql);
            $ins->execute([$parentIdx, $wdater]);
        } catch (\Throwable $e) {
            // 실패해도 조용히 — 데이터 조회는 계속 진행
        }
    }
}
