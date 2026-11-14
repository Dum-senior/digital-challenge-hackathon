<?php
// Offline pure-PHP tests. Local test container may not have ext-mbstring; production OpenHelpDesk requires it.
// The polyfills here are ONLY for this narrow smoke test and are NOT installed into the app.
if (!function_exists('mb_strlen')) { function mb_strlen(string $x): int { return preg_match_all('/./us', $x); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $x): string { return strtolower($x); } }
if (!function_exists('mb_stripos')) { function mb_stripos(string $h, string $n): int|false { return stripos($h, $n); } }
if (!function_exists('mb_substr')) { function mb_substr(string $s, int $n, ?int $max=null): string { return $max === null ? substr($s,$n) : substr($s,$n,$max); } }
function env(string $key, string $default=''): string { return $default; }
function roleIsStaff(?string $role): bool { return in_array($role, ['agent','admin','dispatcher'], true); }
function roleIsAdmin(?string $role): bool { return $role === 'admin'; }
require dirname(__DIR__, 2) . '/src/IntakeDispatcher.php';
function check(bool $ok, string $name): void { if (!$ok) { echo "FAIL $name\n"; exit(1); } echo "PASS $name\n"; }
function bad(callable $fn): bool { try { $fn(); } catch (InvalidArgumentException $e) {return true;} return false; }
check(IntakeDispatcher::validateInput(['text'=>'Принтер дымит','source'=>'phone'])['source'] === 'phone', 'phone is normalized like text');
check(bad(fn()=>IntakeDispatcher::validateInput(['text'=>'hi','source'=>'text'])), 'too short denied');
check(bad(fn()=>IntakeDispatcher::validateInput(['text'=>'Printer smoke','source'=>'sip'])), 'source allowlist');
check(bad(fn()=>IntakeDispatcher::validateInput(['text'=>'Printer smoke','source'=>'text','assignee_id'=>11])), 'unknown fields denied');
check(!IntakeDispatcher::canConfirm(['id'=>3,'role'=>'user']), 'portal cannot confirm');
check(!IntakeDispatcher::canConfirm(['id'=>4,'role'=>'agent']), 'agent cannot confirm by default');
check(IntakeDispatcher::canConfirm(['id'=>1,'role'=>'admin']), 'admin can confirm');
$ref = new ReflectionMethod(IntakeDispatcher::class, 'safeFields');
$badTime = $ref->invoke(null, 'На НТП давно нет данных', ['incident_time'=>'09:00']);
check($badTime['incident_time'] === null, 'fabricated clock time rejected');
$time = $ref->invoke(null, 'На НТП с девяти утра нет данных', ['incident_time'=>'09:00']);
check($time['incident_time'] === '09:00', 'grounded spoken time accepted');
$badIp = $ref->invoke(null, 'Принтер не печатает', ['ip'=>'8.8.8.8']);
check($badIp['ip_addresses'] === [], 'model injected IP rejected');
