<?php
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function current_path(): string { return basename($_SERVER['SCRIPT_NAME'] ?? ''); }
function badge_status(string $s): string {
    return [
        'unread'=>'未读','reading'=>'在读','finished'=>'已读','unfinished'=>'未完成','abandoned'=>'弃读',
        'completed'=>'完成','not_started'=>'未开始','active'=>'进行中','failed'=>'未完成','cancelled'=>'已取消'
    ][$s] ?? $s;
}
function plan_type_name(string $s): string { return ['weekly'=>'周度','monthly'=>'月度','quarterly'=>'季度','yearly'=>'年度'][$s] ?? $s; }
function rating_level_name(?string $s): string { return ['masterpiece'=>'神作','good'=>'好书','normal'=>'一般','bad'=>'烂书','abandoned'=>'弃读'][$s] ?? ''; }
function format_name(?string $s): string { return ['paper'=>'纸质书','ebook'=>'电子书','audiobook'=>'有声书','unknown'=>'未选择'][$s] ?? '未选择'; }
function pct($n): string { return rtrim(rtrim(number_format((float)$n, 1), '0'), '.') . '%'; }
function csv_out(string $filename, array $headers, array $rows): never {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    fputcsv($fp, $headers);
    foreach ($rows as $row) fputcsv($fp, $row);
    fclose($fp);
    exit;
}
