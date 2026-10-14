<?php
// Shared name cleanup for player names. See add_player.php for why.
function pbnow_tidy_name($raw) {
    $words = preg_split('/(\s+)/', trim((string)$raw), -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($words as $i => $w) {
        if ($w === '' || ctype_space($w)) continue;
        $letters = preg_replace('/[^A-Za-z]/', '', $w);
        if ($letters !== '' && ($letters === strtolower($letters) || $letters === strtoupper($letters))) {
            $words[$i] = ucwords(strtolower($w), "-'");
        } else {
            $words[$i] = ucfirst($w);
        }
    }
    return implode('', $words);
}
