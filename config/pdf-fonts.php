<?php

/*
 * Optional CJK (Chinese, Japanese, Korean) font packs for PDF invoices and quotes.
 *
 * Nothing here is bundled: `php artisan billing:fonts:install` (or the button under Settings > System) downloads
 * the packs below into storage/app/dompdf/fonts, verifies the SHA-256 and registers them with dompdf.
 * Each file is the static Regular weight of Google's Noto Sans (SIL Open Font License 1.1) as a TrueType-outline
 * TTF (dompdf cannot use CFF .otf), taken from the pinned @expo-google-fonts npm releases via jsdelivr.
 * To use a mirror or a newer release change `url` and `sha256` (and `size`, a sanity limit in bytes).
 *
 * Coverage: sc = Simplified Chinese (+ kana, Traditional characters), tc = Traditional Chinese forms,
 * jp = Japanese (kana, kanji), kr = Korean (hangul, hanja).
 */
$cdn = 'https://cdn.jsdelivr.net/npm/@expo-google-fonts/noto-sans-%s@0.4.4/400Regular/NotoSans%s_400Regular.ttf';

return [
    // Working folder for dompdf (temp files, font cache). Fonts live in its fonts/ subfolder.
    'work_dir' => storage_path('app/dompdf'),

    // Packs `billing:fonts:install` downloads when none are named. tc is optional (Traditional glyph forms).
    'default' => ['sc', 'jp', 'kr'],

    'packs' => [
        'sc' => ['family' => 'Noto Sans SC', 'label' => 'Simplified Chinese', 'file' => 'NotoSansSC-Regular.ttf',
            'url' => sprintf($cdn, 'sc', 'SC'), 'sha256' => 'd45f67f0a7c0ca3f256950777ce6a61cc7ce5f9696d02900cbbaac25f8aa7d16', 'size' => 10559284],
        'tc' => ['family' => 'Noto Sans TC', 'label' => 'Traditional Chinese', 'file' => 'NotoSansTC-Regular.ttf',
            'url' => sprintf($cdn, 'tc', 'TC'), 'sha256' => 'add35d53f70727657b4509531677c4e3712b67df3d54661efdee5d3a0c16d954', 'size' => 7109460],
        'jp' => ['family' => 'Noto Sans JP', 'label' => 'Japanese', 'file' => 'NotoSansJP-Regular.ttf',
            'url' => sprintf($cdn, 'jp', 'JP'), 'sha256' => '6255ea4692e97ef27330304fb96fd650f9111f868cd585fcf2802d42823930e5', 'size' => 5731428],
        'kr' => ['family' => 'Noto Sans KR', 'label' => 'Korean', 'file' => 'NotoSansKR-Regular.ttf',
            'url' => sprintf($cdn, 'kr', 'KR'), 'sha256' => 'b4a747e78805bc25b610dcfc3003cb5fb2e2ec034763774b24216ed15889eef4', 'size' => 6191364],
    ],
];
