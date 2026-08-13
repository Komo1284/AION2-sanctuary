<?php
// 서버에서 `php force/test_api.php` 로 실행하는 스모크 테스트.
// 만드는 데이터는 전부 zzTest_ 접두사를 쓰고 스스로 지운다.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

$T_pass = 0;
$T_fail = 0;

function t_section($name) {
    echo "\n\033[1m== $name ==\033[0m\n";
}

function t_ok($cond, $label) {
    global $T_pass, $T_fail;
    if ($cond) { $T_pass++; echo "  \033[32m✓\033[0m $label\n"; }
    else       { $T_fail++; echo "  \033[31m✗\033[0m $label\n"; }
}

function t_eq($actual, $expected, $label) {
    $same = ($actual === $expected);
    t_ok($same, $same ? $label
        : $label . ' — 기대 ' . var_export($expected, true) . ', 실제 ' . var_export($actual, true));
}

function t_summary() {
    global $T_pass, $T_fail;
    echo "\n" . ($T_fail === 0 ? "\033[32m전체 통과" : "\033[31m실패 있음")
       . "\033[0m — 통과 $T_pass / 실패 $T_fail\n";
    return $T_fail === 0 ? 0 : 1;
}

$pdo = fc_pdo();

t_section('스키마');
fc_init_schema($pdo);
fc_init_schema($pdo); // 멱등성: 두 번 돌려도 예외가 없어야 한다
t_ok(true, 'fc_init_schema를 두 번 실행해도 예외가 없다');

$tables = ['fc_players', 'fc_characters', 'fc_raids', 'fc_forces', 'fc_slots', 'fc_meta'];
foreach ($tables as $tbl) {
    $found = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl))->fetch();
    t_ok($found !== false, "$tbl 테이블이 존재한다");
}

$rev = $pdo->query("SELECT v FROM fc_meta WHERE k = 'revision'")->fetchColumn();
t_ok($rev !== false, 'fc_meta에 revision 행이 초기화되어 있다');

exit(t_summary());
