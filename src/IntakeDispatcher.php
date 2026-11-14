<?php

declare(strict_types=1);

/**
 * 02_ДЕН: human-reviewed AI intake for OpenHelpDesk.
 * No new user store, ticket store, SLA clock or speech recognizer.
 * NOTE: Caller MUST authenticate with the existing _apiAuth() before invoking.
 */
final class IntakeDispatcher
{
    public const SOURCES = ['text', 'voice', 'phone'];
    public const CATEGORIES = ['peripherals', 'telemetry', 'automation', 'network', 'security', 'access', 'database', 'unknown'];
    public const EDITABLE = ['subject', 'description', 'type_id', 'location_id', 'priority_id', 'group_id', 'assigned_to', 'tag_ids', 'fields'];

    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function validateInput(array $data): array
    {
        $allowed = ['text', 'source', 'request_id'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown input fields');
        }
        $text = $data['text'] ?? null;
        $source = $data['source'] ?? 'text';
        $req = $data['request_id'] ?? null;
        if (!is_string($text) || mb_strlen(trim($text)) < 3 || mb_strlen($text) > 4000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) {
            throw new InvalidArgumentException('text must be 3-4000 characters');
        }
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Invalid source');
        }
        if ($req !== null && (!is_string($req) || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $req))) {
            throw new InvalidArgumentException('Invalid request_id');
        }
        return ['text' => trim($text), 'source' => $source, 'request_id' => $req];
    }

    /** The model sees just the text, not the private user/group catalog. */
    private static function modelAnalyze(string $text, string $source): array
    {
        $url = (string) env('INTAKE_AI_URL', 'http://127.0.0.1:8091/api/hackathon/v1/intake/analyze');
        $secret = (string) env('INTAKE_SERVICE_TOKEN', '');
        $standalone = env('INTAKE_API_MODE', '') === 'dispatcher';
        $parts = parse_url($url);
        $allowedHosts = array_filter(array_map('trim', explode(',', (string) env('INTAKE_AI_ALLOWED_HOSTS', '127.0.0.1,localhost,intake-ai'))));
        if (!$parts || ($parts['scheme'] ?? '') !== 'http' || !in_array($parts['host'] ?? '', $allowedHosts, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || $secret === '' || str_starts_with($secret, 'REPLACE_')) {
            throw new RuntimeException('Private AI service is not configured');
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$secret}\r\nConnection: close\r\n",
            'content' => self::json($standalone ? ['text' => $text] : ['text' => $text, 'source' => $source]),
            'timeout' => min(20, max(1, (int) env('INTAKE_AI_TIMEOUT_SECONDS', '8'))),
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || strlen($body) > 65536 || !isset($http_response_header[0])
            || !preg_match('~^HTTP/\S+ 200(?:\s|$)~', $http_response_header[0])) {
            throw new RuntimeException('Private AI service unavailable');
        }
        $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if ($standalone && is_array($result)) {
            $result += [
                'source' => $source, 'status' => 'awaiting_review', 'priority' => 'medium',
                'missing_fields' => [], 'analysis_mode' => 'private_llm',
                'fields' => ['object' => $result['object'] ?? null, 'room' => $result['room'] ?? null,
                    'incident_time' => $result['started_at'] ?? null],
            ];
        }
        if (!is_array($result) || !in_array($result['category'] ?? null, self::CATEGORIES, true)
            || !in_array($result['priority'] ?? null, ['low', 'medium', 'high', 'critical'], true)
            || !in_array($result['status'] ?? null, ['awaiting_review', 'needs_clarification', 'failed'], true)
            || ($result['source'] ?? null) !== $source
            || !is_array($result['fields'] ?? null) || !is_array($result['missing_fields'] ?? null)) {
            throw new UnexpectedValueException('Invalid AI response');
        }
        return $result;
    }

    /** All IDs are chosen by server SQL from the LIVE database, never from LLM output. */
    public static function analyze(PDO $db, string $text, string $source): array
    {
        $failure = null;
        try {
            $model = self::modelAnalyze($text, $source);
            if (($model['status'] ?? '') === 'failed') {
                $failure = 'Локальная LLM завершилась ошибкой: требуется документированная ручная проверка';
            }
        } catch (Throwable $e) {
            $failure = 'Модель недоступна или вернула некорректный ответ: необходима ручная проверка';
            $model = ['category' => 'unknown', 'priority' => 'medium', 'priority_reason' => 'Данных модели нет',
                'fields' => [], 'missing_fields' => ['system', 'location', 'affected_scope'],
                'safety_warnings' => [], 'analysis_mode' => 'unavailable'];
        }
        $lower = mb_strtolower($text, 'UTF-8');
        $danger = (bool) preg_match('/дым|задым|горит|пожар|искрит|запах гар[иья]|вымогател|шифровальщик|выкуп за файл|ransomware/u', $lower);
        $mass = (bool) preg_match('/нескольк(?:их|о)\s+(?:скважин|отделов|пользователей)|во\s+вс[её]м\s+корпус|у\s+всех|массов|нескольких\s+скважин/u', $lower);
        $category = $model['category'];
        $signals = [
            'automation' => '/\b(?:opc|орс)\b|асу тп/iu',
            'telemetry' => '/сч[её]тчик|телеметр|показани/iu',
            'peripherals' => '/принтер|печата|печать/iu',
            'network' => '/пропала сеть|нет связи|wi-fi|wifi|\bvpn\b/iu',
            'access' => '/уч[её]тная запись|не могу войти|access denied|\bmfa\b/iu',
        ];
        foreach ($signals as $candidate => $pattern) {
            if (preg_match($pattern, $text)) {
                $category = $candidate;
                break;
            }
        }
        if (preg_match('/^(?:у нас )?(?:ничего не работает|вс[её] упало)[.!\s]*$/iu', trim($text))) {
            $category = 'unknown';
        }
        $priority = $danger ? 'critical' : ($category === 'unknown' ? null : 'medium');
        $reason = $danger ? 'Явный признак опасности; требуется немедленная проверка'
            : ($mass ? 'Затронуто несколько пользователей или объектов'
                : ($priority === null ? 'Недостаточно сведений для оценки' : 'Работа нарушена, явных признаков аварии нет'));
        if (!$danger && !$mass && $priority !== null && preg_match('/есть запасн|работа продолжается|не мешает работе|планов/u', $lower)) {
            $priority = 'low';
            $reason = 'Работа продолжается или доступен обходной вариант';
        }
        if (preg_match('/(?<!\w)(?:с|после)\s+((?:[01]?\d|2[0-3]):[0-5]\d)(?!\d)/iu', $text, $start)) {
            $model['fields']['incident_time'] = str_pad($start[1], 5, '0', STR_PAD_LEFT);
        }
        $fields = self::safeFields($text, $model['fields']);
        $missing = array_values(array_unique(array_map('strval', array_slice($model['missing_fields'], 0, 12))));
        $questions = self::questions($category, $lower, $fields, $missing);
        $location = self::matchLocation($db, $text);
        if ($location['id'] === null) {
            $questions[] = empty($location['candidates']) ? 'Уточните название НТП, корпуса или офиса.'
                : 'Уточните адрес: найдено несколько подходящих мест.';
            $questions = array_values(array_unique($questions));
        }
        $typeId = self::matchType($db, $category);
        $priorityId = $priority === null ? null : self::matchPriority($db, $priority);
        $assignment = self::suggest($db, $category, $priority === 'critical');
        preg_match_all('/сч[её]тчик(?:а|у|е|ом)?\s*(?:№\s*|номер\s*)?(\d[\p{L}0-9-]{0,30})(?![\p{L}0-9-])/iu', $text, $meters);
        $numbers = array_values(array_unique($meters[1]));
        $meter = count($numbers) === 1 ? $numbers[0] : null;
        $tag = self::categoryTag($category);
        $tagStmt = $db->prepare('SELECT id FROM ticket_tags WHERE name = ?');
        $tagStmt->execute([$tag]);
        $tagId = $tagStmt->fetchColumn();
        $facts = array_values(array_filter($model['facts'] ?? [], static fn($fact) =>
            is_string($fact) && trim($fact) !== '' && mb_strlen($fact) <= 180 && mb_stripos($text, $fact) !== false
            && !in_array($fact, [$meter, $fields['incident_time'], $fields['object']], true)));
        $subject = mb_substr(preg_replace('/\s+/u', ' ', trim($text)), 0, 140);
        return [
            'subject' => $subject, 'description' => $text, 'raw_text' => $text, 'category' => $category,
            'type_id' => $typeId, 'tags' => $tag === null ? [] : [$tag], 'tag_ids' => $tagId ? [(int) $tagId] : [], 'location_name' => $location['name'],
            'location_id' => $location['id'], 'object' => $location['name'] ?? $fields['object'], 'location' => $location, 'meter_number' => $meter,
            'started_at' => $fields['incident_time'], 'useful_notes' => implode("\n", array_slice($facts, 0, 4)), 'floor' => $fields['floor'],
            'room' => $fields['room'], 'equipment' => $fields['equipment'],
            'serial_number' => $fields['serial_number'], 'ip_addresses' => $fields['ip_addresses'],
            'error_codes' => $fields['error_codes'], 'symptoms' => $fields['symptoms'],
            'incident_time' => $fields['incident_time'], 'affected_scope' => $fields['affected_scope'],
            'priority' => $priority, 'priority_id' => $priorityId,
            'severity_reason' => $reason,
            'is_potential_emergency' => $danger,
            'recommended_group_id' => $assignment['group_id'],
            'recommended_assignee_id' => ($failure && !$danger) ? null : $assignment['assignee_id'],
            'assignment_reason' => ($failure && !$danger) ? 'Модель недоступна; назначение не предлагается' : $assignment['reason'],
            'missing_fields' => $missing, 'questions' => $questions, 'requires_human_review' => true,
            'analysis_mode' => $failure ? 'failed' : ($model['analysis_mode'] ?? 'rules_only'),
            'model_error' => $failure,
            'safety_warnings' => $danger ? ['Потенциальная авария/ИБ: не перезапускать и не вскрывать опасное оборудование; действовать по утверждённому регламенту'] : [],
        ];
    }

    private static function safeFields(string $raw, array $model): array
    {
        $source = mb_strtolower($raw, 'UTF-8');
        $safe = [];
        foreach (['object', 'floor', 'room', 'equipment', 'serial_number', 'incident_time', 'symptoms', 'scope'] as $field) {
            $val = $model[$field] ?? null;
            $val = is_string($val) && mb_strlen($val) <= 250 && mb_stripos($source, mb_strtolower($val, 'UTF-8')) !== false ? $val : null;
            // Normalized incident_time is not a literal substring; validate against rule-based extraction.
            if ($field === 'incident_time' && is_string($model[$field] ?? null)
                && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $model[$field])) {
                $candidates = [];
                if (preg_match_all('/(?<!\d)(?:[01]?\d|2[0-3]):[0-5]\d(?!\d)/u', $raw, $tm)) {
                    foreach ($tm[0] as $time) $candidates[] = str_pad($time, 5, '0', STR_PAD_LEFT);
                }
                if (preg_match('/\bс\s+(\d{1,2})\s+утра/u', mb_strtolower($raw), $numeric) && (int) $numeric[1] <= 11) {
                    $candidates[] = sprintf('%02d:00', (int) $numeric[1]);
                }
                $words = ['девяти' => 9, 'восьми' => 8, 'семи' => 7, 'шести' => 6,
                    'десяти' => 10, 'одиннадцати' => 11, 'пяти' => 5];
                foreach ($words as $word => $hour) if (str_contains(mb_strtolower($raw), 'с ' . $word . ' утра')) {
                    $candidates[] = sprintf('%02d:00', $hour);
                }
                $val = in_array($model[$field], $candidates, true) ? $model[$field] : null;
            }
            $safe[$field] = $val;
        }
        $safe['symptoms'] = $safe['symptoms'] ?? mb_substr($raw, 0, 1200);
        $safe['affected_scope'] = $safe['scope'];
        $ip = $model['ip'] ?? null;
        $safe['ip_addresses'] = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && str_contains($raw, $ip) ? [$ip] : [];
        $code = $model['error_code'] ?? null;
        $safe['error_codes'] = is_string($code) && mb_stripos($source, mb_strtolower($code, 'UTF-8')) !== false ? [$code] : [];
        return $safe;
    }

    public static function questions(string $category, string $lowerText, array $fields, array &$missing): array
    {
        $questions = [];
        if ($category === 'unknown' || str_contains($lowerText, 'ничего не работает')) {
            $missing = array_values(array_unique(array_merge($missing, ['system', 'location', 'affected_scope'])));
            $questions = ['Какая конкретно система или оборудование не работает?', 'Укажите объект, корпус или кабинет.', 'Проблема у одного пользователя или у нескольких?'];
        } elseif ($category === 'telemetry' && preg_match('/сч[её]тчик/u', $lowerText) && !preg_match('/(?:номер|№)\s*\d+/u', $lowerText)) {
            $missing[] = 'meter_id';
            $questions[] = 'Какой номер счётчика?';
        }
        if (in_array($category, ['telemetry', 'automation'], true) && $fields['affected_scope'] === null && !str_contains($lowerText, 'одного')) {
            $missing[] = 'affected_scope';
            $questions[] = 'Проблема затрагивает один объект или несколько?';
        }
        $missing = array_values(array_unique($missing));
        return array_slice($questions, 0, 6);
    }

    private static function matchLocation(PDO $db, string $text): array
    {
        $normalize = static function (string $value): string {
            $value = preg_replace('/\b(корпус|офис)(?:е|а)\b/iu', '$1', mb_strtolower($value));
            return ' ' . trim(preg_replace('/[^\p{L}0-9]+|(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L})/u', ' ', $value)) . ' ';
        };
        $normalized = $normalize($text);
        $matches = [];
        foreach ($db->query('SELECT id,name,address,place_type FROM locations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if ($item['place_type'] !== null && mb_strlen($item['name']) >= 3 && str_contains($normalized, $normalize($item['name']))) {
                $item['id'] = (int) $item['id'];
                $matches[] = $item;
            }
        }
        return count($matches) === 1 ? $matches[0]
            : ['id' => null, 'name' => null, 'address' => null, 'candidates' => $matches];
    }

    private static function categoryTag(string $category): ?string
    {
        return ['peripherals'=>'Печать', 'telemetry'=>'Телеметрия', 'automation'=>'OPC', 'network'=>'Сеть',
            'security'=>'ИБ', 'access'=>'Доступ', 'database'=>'База данных'][$category] ?? null;
    }

    private static function matchType(PDO $db, string $category): ?int
    {
        $terms = [
            'peripherals' => ['принтер', 'печать', 'printer'], 'telemetry' => ['телеметрия', 'счётчик', 'счетчик'],
            'automation' => ['асу тп', 'opc', 'автоматизация'], 'network' => ['сеть', 'связь', 'network'],
            'security' => ['информационная безопасность', 'иб', 'security'], 'access' => ['доступ', 'access'],
            'database' => ['база данных', 'database', 'бд'],
        ];
        $canonical = self::categoryTag($category);
        $matches = [];
        foreach ($db->query('SELECT id, name FROM ticket_types WHERE is_confidential = 0 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['name'] === $canonical) return (int) $row['id'];
            foreach ($terms[$category] ?? [] as $term) {
                if (mb_strtolower($row['name']) === $term) { $matches[] = (int) $row['id']; break; }
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function matchPriority(PDO $db, string $severity): ?int
    {
        $name = ['low'=>'Низкий', 'medium'=>'Средний', 'critical'=>'Авария'][$severity] ?? null;
        if ($name === null) return null;
        $stmt = $db->prepare('SELECT MIN(id) FROM ticket_priorities WHERE name = ?');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn() ?: null;
    }

    /** Current live skills + non-closed ticket counts; never a second personnel catalog. */
    public static function suggest(PDO $db, string $category, bool $emergency = false): array
    {
        if ($emergency) {
            $active = ticketStatusSqlIn(ticketClosedBucketSlugs(), 't.status', true);
            $choice = $db->query("SELECT u.id, g.id AS group_id, COUNT(DISTINCT t.id) AS workload
                FROM users u
                JOIN group_user_map m ON m.user_id = u.id
                JOIN `groups` g ON g.id = m.group_id AND g.is_confidential = 0
                LEFT JOIN agent_oof_status o ON o.user_id = u.id
                LEFT JOIN tickets t ON t.assigned_to = u.id AND $active
                WHERE u.is_available = 1 AND u.role NOT IN ('admin','dispatcher') AND COALESCE(o.is_oof,0) = 0 AND " . staffRoleSqlIn('u.role') . "
                GROUP BY u.id, g.id ORDER BY workload, u.id, g.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return $choice
                ? ['group_id' => (int) $choice['group_id'], 'assignee_id' => (int) $choice['id'],
                    'reason' => 'Авария: доступный инженер с минимальной нагрузкой. Открытых заявок: ' . $choice['workload']]
                : ['group_id' => null, 'assignee_id' => null, 'reason' => 'Нет доступных инженеров. Эскалируйте диспетчеру'];
        }
        $keywords = [
            'peripherals' => ['принтер', 'печать', 'printer'], 'telemetry' => ['телеметр', 'счётчик', 'счетчик'],
            'automation' => ['opc', 'асу', 'автоматиз'], 'network' => ['сеть', 'связь', 'network'],
            'security' => ['иб', 'security', 'безопасн'], 'access' => ['доступ', 'access', 'учётн'],
            'database' => ['баз данных', 'sql', 'database'],
        ][$category] ?? [];
        if ($keywords === []) return ['group_id' => null, 'assignee_id' => null, 'reason' => 'Требуется уточнение категории'];
        $groups = $db->query('SELECT id, name, description FROM `groups` WHERE is_confidential = 0 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $score = [];
        foreach ($groups as $g) {
            $id = (int) $g['id'];
            $title = mb_strtolower($g['name'] . ' ' . ($g['description'] ?? ''));
            $score[$id] = 0;
            foreach ($keywords as $word) {
                if (mb_stripos($title, $word) !== false) { $score[$id] += 5; break; }
            }
        }
        // A group can be generically named "IT" but still contain printer/network
        // specialists. Compare LIVE group membership + registered skill names too.
        $skillStmt = $db->query('SELECT m.group_id, m.user_id, s.name AS skill_name
           FROM group_user_map m
           JOIN users u ON u.id = m.user_id
           JOIN user_skill_map usm ON usm.user_id = u.id
           JOIN agent_skills s ON s.id = usm.skill_id
           JOIN `groups` g ON g.id = m.group_id AND g.is_confidential = 0
           WHERE ' . staffRoleSqlIn('u.role'));
        $skilled = [];
        foreach ($skillStmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $group = (int) $entry['group_id'];
            $name = mb_strtolower($entry['skill_name']);
            foreach ($keywords as $word) {
                if (mb_stripos($name, $word) !== false) {
                    $skilled[$group][(int) $entry['user_id']] = true;
                    break;
                }
            }
        }
        foreach ($score as $id => &$value) $value += count($skilled[$id] ?? []);
        unset($value);
        $best = $score === [] ? 0 : max($score);
        $matches = $best > 0 ? array_keys(array_filter($score, static fn(int $v): bool => $v === $best)) : [];
        if (count($matches) !== 1) {
            return ['group_id' => null, 'assignee_id' => null, 'reason' => 'Нет однозначного соответствия группе или подтверждённым навыкам OpenHelpDesk'];
        }
        $groupId = (int) $matches[0];
        $sql = "SELECT u.id, u.is_available, COALESCE(o.is_oof, 0) AS is_oof,
                      s.name AS skill_name, usm.proficiency
                FROM group_user_map gum
                JOIN users u ON u.id = gum.user_id
                LEFT JOIN agent_oof_status o ON o.user_id = u.id
                LEFT JOIN user_skill_map usm ON usm.user_id = u.id
                LEFT JOIN agent_skills s ON s.id = usm.skill_id
                WHERE gum.group_id = ? AND " . staffRoleSqlIn('u.role') . ' ORDER BY u.id';
        $stmt = $db->prepare($sql);
        $stmt->execute([$groupId]);
        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $id = (int) $entry['id'];
            $candidates[$id] ??= ['id' => $id, 'available' => ((int) $entry['is_available'] === 1 && (int) $entry['is_oof'] === 0), 'skills' => []];
            if ($entry['skill_name'] !== null) $candidates[$id]['skills'][] = [mb_strtolower($entry['skill_name']), (int) $entry['proficiency']];
        }
        $levels = [];
        foreach ($candidates as $id => $candidate) {
            if (!$candidate['available']) continue;
            $level = 0;
            foreach ($candidate['skills'] as [$skill, $proficiency]) {
                foreach ($keywords as $needle) {
                    if (mb_stripos($skill, $needle) !== false) $level = max($level, $proficiency);
                }
            }
            $levels[$id] = $level;
        }
        if (!$levels) return ['group_id' => $groupId, 'assignee_id' => null,
            'reason' => 'В подразделении нет доступных инженеров'];
        $ids = array_keys($levels);
        $holders = implode(',', array_fill(0, count($ids), '?'));
        $active = ticketStatusSqlIn(ticketClosedBucketSlugs(), 't.status', true);
        $stmt = $db->prepare("SELECT t.assigned_to, COUNT(*) AS count_open FROM tickets t WHERE t.assigned_to IN ({$holders}) AND {$active} GROUP BY t.assigned_to");
        $stmt->execute($ids);
        $counts = array_fill_keys($ids, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[(int) $row['assigned_to']] = (int) $row['count_open'];
        usort($ids, static fn(int $a, int $b): int =>
            ($levels[$b] <=> $levels[$a]) ?: ($counts[$a] <=> $counts[$b]) ?: ($a <=> $b));
        $choice = $ids[0];
        $reason = [0=>'Подбор по нагрузке в подразделении', 1=>'Базовый навык', 2=>'Уверенный навык', 3=>'Эксперт по направлению'][$levels[$choice]] ?? 'Подтверждённый навык';
        return ['group_id' => $groupId, 'assignee_id' => $choice,
            'reason' => "$reason. Открытых заявок: {$counts[$choice]}"];
    }

    public static function validateApproved(PDO $db, array $approved, int $ownerId, bool $canAssign): array
    {
        if (array_diff(array_keys($approved), self::EDITABLE) !== []) throw new InvalidArgumentException('Unknown approved field');
        foreach (['subject', 'description'] as $field) {
            if (!is_string($approved[$field] ?? null) || !mb_strlen(trim($approved[$field]))
                || mb_strlen($approved[$field]) > ($field === 'subject' ? 255 : 6000)) {
                throw new InvalidArgumentException('Invalid ' . $field);
            }
        }
        $out = ['subject' => trim($approved['subject']), 'description' => trim($approved['description'])];
        foreach (['type_id' => 'ticket_types', 'priority_id' => 'ticket_priorities', 'location_id' => 'locations', 'group_id' => 'groups'] as $field => $table) {
            $v = $approved[$field] ?? null;
            if ($v === null) { $out[$field] = null; continue; }
            if (!is_int($v) || $v <= 0) throw new InvalidArgumentException('Invalid ' . $field);
            $stm = $db->prepare("SELECT id FROM `{$table}` WHERE id = ?");
            $stm->execute([$v]);
            if (!$stm->fetchColumn()) throw new InvalidArgumentException('Unknown ' . $field);
            $out[$field] = $v;
        }
        if ($out['type_id'] !== null) {
            $s = $db->prepare('SELECT is_confidential FROM ticket_types WHERE id = ?');
            $s->execute([$out['type_id']]);
            if ((int) $s->fetchColumn() !== 0) throw new InvalidArgumentException('Confidential type cannot be created via AI intake');
        }
        if (!userCanCreateTicketType($db, $out['type_id'], $ownerId)) throw new InvalidArgumentException('Ticket type is restricted to user');
        if (!priorityAllowedForType($db, $out['type_id'], $out['priority_id'])) throw new InvalidArgumentException('Priority is not allowed for type');
        if (!$canAssign && ($out['group_id'] !== null || ($approved['assigned_to'] ?? null) !== null)) {
            throw new InvalidArgumentException('Assignment requires dispatcher role');
        }
        if ($out['group_id'] !== null) {
            $check = $db->prepare('SELECT is_confidential FROM `groups` WHERE id = ?');
            $check->execute([$out['group_id']]);
            if ((int) $check->fetchColumn() !== 0) throw new InvalidArgumentException('Confidential group cannot be selected via AI intake');
        }
        // A portal requester is limited to their own registered location; staff owners
        // may select any existing site. This is stricter than the mobile API default.
        $us = $db->prepare('SELECT role, location_id FROM users WHERE id = ?');
        $us->execute([$ownerId]);
        $owner = $us->fetch(PDO::FETCH_ASSOC);
        if (!$owner) throw new InvalidArgumentException('Draft owner no longer exists');
        if (!roleIsStaff($owner['role']) && $out['location_id'] !== null
            && $out['location_id'] !== ($owner['location_id'] === null ? null : (int) $owner['location_id'])) {
            throw new InvalidArgumentException('Requester is not permitted to use this location');
        }
        $out['assigned_to'] = $approved['assigned_to'] ?? null;
        if ($out['assigned_to'] !== null) {
            if (!is_int($out['assigned_to']) || $out['assigned_to'] <= 0 || $out['group_id'] === null) throw new InvalidArgumentException('Invalid assignee');
            $sql = 'SELECT u.id FROM users u JOIN group_user_map m ON m.user_id = u.id AND m.group_id = ? WHERE u.id = ? AND ' . staffRoleSqlIn('u.role');
            $stm = $db->prepare($sql);
            $stm->execute([$out['group_id'], $out['assigned_to']]);
            if (!$stm->fetchColumn()) throw new InvalidArgumentException('Assignee must be current staff in selected group');
        }
                $out['fields'] = $approved['fields'] ?? [];
        if (!is_array($out['fields']) || ($out['fields'] !== [] && array_is_list($out['fields']))) {
            throw new InvalidArgumentException('Invalid structured fields');
        }
        $fieldNames = ['object', 'floor', 'room', 'equipment', 'serial_number', 'incident_time',
            'affected_scope', 'symptoms', 'ip_addresses', 'error_codes', 'meter_number', 'started_at', 'useful_notes'];
        if (array_diff(array_keys($out['fields']), $fieldNames) !== []) {
            throw new InvalidArgumentException('Unknown structured field');
        }
        foreach ($out['fields'] as $name => $value) {
            if (in_array($name, ['ip_addresses', 'error_codes'], true)) {
                if (!is_array($value) || count($value) > 10) throw new InvalidArgumentException('Invalid structured list');
                foreach ($value as $item) {
                    if (!is_string($item) || mb_strlen($item) > 80) throw new InvalidArgumentException('Invalid structured list item');
                    if ($name === 'ip_addresses' && filter_var($item, FILTER_VALIDATE_IP) === false) throw new InvalidArgumentException('Invalid IP address');
                }
            } elseif ($value !== null && (!is_string($value) || mb_strlen($value) > 1200)) {
                throw new InvalidArgumentException('Invalid ' . $name);
            }
        }
        if (isset($out['fields']['incident_time']) && $out['fields']['incident_time'] !== null
            && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $out['fields']['incident_time'])) {
            throw new InvalidArgumentException('Invalid incident time');
        }
        if (isset($out['fields']['started_at']) && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $out['fields']['started_at'])) {
            throw new InvalidArgumentException('Invalid start time');
        }
        $out['tag_ids'] = $approved['tag_ids'] ?? [];
        if (!is_array($out['tag_ids']) || count($out['tag_ids']) > 12) throw new InvalidArgumentException('Invalid tags');
        foreach ($out['tag_ids'] as $tid) {
            if (!is_int($tid) || $tid <= 0) throw new InvalidArgumentException('Invalid tag ID');
            $s = $db->prepare('SELECT id FROM ticket_tags WHERE id = ?'); $s->execute([$tid]);
            if (!$s->fetchColumn()) throw new InvalidArgumentException('Unknown tag');
        }
        $out['tag_ids'] = array_values(array_unique($out['tag_ids']));
        return $out;
    }

    public static function canConfirm(array $user): bool
    {
        if (!roleIsStaff($user['role'] ?? null)) return false;
        if (roleIsAdmin($user['role'] ?? null)) return true;
        $allowed = array_filter(array_map('trim', explode(',', (string) env('INTAKE_CONFIRM_ROLES', 'admin'))));
        return in_array((string) ($user['role'] ?? ''), $allowed, true);
    }

    public static function publicResponse(array $row, array $user): array
    {
        $analysis = json_decode((string) $row['analysis_json'], true) ?: [];
        $staff = self::canConfirm($user);
        // Never disclose internal routing IDs/notes through the portal's extracted object.
        if (!$staff) {
            unset($analysis['recommended_group_id'], $analysis['recommended_assignee_id'], $analysis['assignment_reason']);
        }
        return [
            'draft_id' => (int) $row['id'], 'revision' => (int) $row['revision'], 'status' => $row['status'],
            'source' => $row['source'], 'raw_text' => $row['raw_text'],
            'extracted' => $analysis, 'recommendation' => [
                'priority_id' => $analysis['priority_id'] ?? null,
                'group_id' => $staff ? ($analysis['recommended_group_id'] ?? null) : null,
                'assignee_id' => $staff ? ($analysis['recommended_assignee_id'] ?? null) : null,
                'reason' => $staff ? ($analysis['assignment_reason'] ?? '') : 'Назначение проверит диспетчер',
            ],
            'missing_fields' => $analysis['missing_fields'] ?? [], 'questions' => $analysis['questions'] ?? [],
            'approved' => $staff ? (json_decode((string) ($row['edited_json'] ?? '{}'), true) ?: []) : null,
            'requires_human_review' => $row['status'] !== 'created',
            'ticket_id' => $row['status'] === 'created' ? (int) $row['confirmed_ticket_id'] : null,
        ];
    }
}
