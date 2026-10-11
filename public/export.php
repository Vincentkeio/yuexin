<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/ImportExportService.php';

$u = require_login();
$uid = $u['id'];
$type = $_GET['type'] ?? 'template';
$headers = ImportExportService::$headers;

function csv_out_local(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    fputcsv($fp, $headers);
    foreach ($rows as $row) fputcsv($fp, $row);
    fclose($fp);
    exit;
}

if ($type === 'template') {
    $rows = ImportExportService::templateRows();
    $headers = array_shift($rows);
    csv_out_local('yuexin_standard_template_v3.csv', $headers, $rows);
}

$pdo = db();
$out = [];

$st = $pdo->prepare('
    SELECT
        t.id task_id,t.raw_book_title,t.raw_author,t.history_name,t.task_original_index,t.task_media_text,t.task_format,t.task_platform,
        t.task_author_country,t.task_publisher,t.task_publish_year,t.task_translator,t.task_is_full,
        t.planned_start_date,t.planned_finish_date,t.actual_start_date,t.actual_finish_date,
        t.status task_status,t.progress task_progress,t.continue_next,t.rating_score task_rating_score,t.rating_level task_rating_level,t.comment,
        p.name plan_name,p.start_date history_start,p.end_date history_end,
        b.*
    FROM reading_tasks t
    JOIN reading_plans p ON p.id=t.plan_id
    LEFT JOIN books b ON b.id=t.book_id
    WHERE t.user_id=?
    ORDER BY COALESCE(t.actual_finish_date,t.planned_finish_date,p.end_date) DESC,t.id DESC
');
$st->execute([$uid]);

foreach ($st as $r) {
    $out[] = [
        $r['original_index'] ?? $r['task_original_index'] ?? '',
        $r['title'] ?? $r['raw_book_title'] ?? '',
        $r['subtitle'] ?? '',
        $r['original_title'] ?? '',
        $r['author'] ?? $r['raw_author'] ?? '',
        $r['author_country'] ?? $r['task_author_country'] ?? '',
        $r['author_country_raw'] ?? '',
        $r['translator'] ?? $r['task_translator'] ?? '',
        $r['publisher'] ?? $r['task_publisher'] ?? '',
        $r['publish_date'] ?? '',
        $r['publish_year'] ?? $r['task_publish_year'] ?? '',
        $r['isbn'] ?? '',
        $r['book_type'] ?? '',
        $r['tags'] ?? '',
        $r['media_text'] ?? $r['task_media_text'] ?? '',
        $r['format'] ?? $r['task_format'] ?? '',
        $r['platform'] ?? $r['task_platform'] ?? '',
        $r['is_full'] ?? $r['task_is_full'] ?? '',
        $r['is_paper'] ?? '',
        $r['is_ebook'] ?? '',
        $r['is_audiobook'] ?? '',
        $r['status'] ?? '',
        $r['progress'] ?? '',
        $r['first_start_date'] ?? '',
        $r['final_finish_date'] ?? '',
        $r['final_finish_date'] ?? '',
        $r['rating_score'] ?? '',
        $r['self_rating_note'] ?? $r['rating_score'] ?? '',
        $r['rating_level'] ?? '',
        $r['short_review'] ?? '',
        $r['notes'] ?? '',
        $r['douban_subject_id'] ?? '',
        $r['douban_url'] ?? '',
        $r['douban_title'] ?? '',
        $r['douban_author'] ?? '',
        $r['douban_publisher'] ?? '',
        $r['douban_publish_date'] ?? '',
        $r['douban_rating'] ?? '',
        $r['douban_rating_count'] ?? '',
        $r['douban_read_count'] ?? '',
        $r['douban_read_count'] ?? '',
        $r['douban_wish_count'] ?? '',
        $r['history_name'] ?? $r['plan_name'] ?? '',
        $r['history_start'] ?? '',
        $r['history_end'] ?? '',
        $r['planned_start_date'] ?? '',
        $r['planned_finish_date'] ?? '',
        $r['planned_start_date'] ?? '',
        $r['planned_finish_date'] ?? '',
        $r['actual_start_date'] ?? '',
        $r['actual_finish_date'] ?? '',
        $r['actual_start_date'] ?? '',
        $r['actual_finish_date'] ?? '',
        $r['task_status'] ?? '',
        $r['task_progress'] ?? '',
        !empty($r['continue_next']) ? '1' : '0',
        $r['task_rating_score'] ?? '',
        $r['task_rating_score'] ?? '',
        $r['task_rating_level'] ?? '',
        $r['comment'] ?? '',
    ];
}

csv_out_local('yuexin_reading_history_v3.csv', $headers, $out);
