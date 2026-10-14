<?php
// php playpbnow-api/tests/name_tidy_test.php
require_once __DIR__ . '/../name_tidy.php';
$cases = [
    'chip McAllister' => 'Chip McAllister',
    'Dianna MacDavid' => 'Dianna MacDavid',
    'jane smith' => 'Jane Smith',
    'JANE SMITH' => 'Jane Smith',
    "mary-kate o'brien" => "Mary-Kate O'Brien",
    'Greg S' => 'Greg S',
    'Kim Mc' => 'Kim Mc',
    'Chip M' => 'Chip M',
    '  bob   jones ' => 'Bob   Jones',
    'dianna m' => 'Dianna M',
    '' => '',
];
$fail = 0;
foreach ($cases as $in => $want) {
    $got = pbnow_tidy_name($in);
    if ($got !== $want) { $fail++; echo "FAIL: '$in' -> '$got' (want '$want')\n"; }
}
echo count($cases) . " checks, $fail failed\n";
exit($fail ? 1 : 0);
