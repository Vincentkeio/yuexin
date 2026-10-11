<?php

class ImportExportService
{
    public static array $headers = [
        '序号','书名','副标题','原书名','作者','作者国籍','国别原文','译者','出版社','出版日期','出版年份','ISBN',
        '书籍类型','标签','载体','阅读形式','阅读平台','是否全本','是否纸质书','是否电子书','是否有声书',
        '阅读状态','阅读进度','开始阅读日期','完成阅读日期','读完日期','我的评分','自我评分','我的等级','短评','笔记',
        '豆瓣ID','豆瓣URL','豆瓣书名','豆瓣作者','豆瓣出版社','豆瓣出版日期','豆瓣评分','豆瓣评价人数','豆瓣读过人数','豆瓣阅读人数','豆瓣想读人数',
        '历史名称','历史开始日期','历史结束日期','计划开始阅读日期','计划完成阅读日期','计划开始读','计划读完',
        '实际开始阅读日期','实际完成阅读日期','实际开始读','实际读完','历史状态','历史进度','是否延续','本期自我评分','本期评分','本期等级','本期备注'
    ];

    public static function templateRows(): array
    {
        return [
            self::$headers,
            [
                '1','三体','','','刘慈欣','中国','中','','重庆出版社','2008-01','2008','9787536692930',
                '科幻','科幻,小说','纸质书','paper','纸质书','是','1','0','0',
                'finished','100','2026-05-01','2026-05-10','2026-05-10','9.5','9.5','masterpiece','示例短评','',
                '2567698','https://book.douban.com/subject/2567698/','','','','','','','','','',
                '历史导入记录','2026-01-01','2026-12-31','2026-05-01','2026-05-10','2026-05-01','2026-05-10',
                '2026-05-01','2026-05-10','2026-05-01','2026-05-10','completed','100','0','9.5','9.5','masterpiece','示例行，可删除'
            ]
        ];
    }

    public static function readUploaded(string $tmp, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new RuntimeException('仅支持 CSV 标准格式文件，请下载标准 CSV 模板后填写');
        }
        return self::readCsv($tmp);
    }

    private static function readCsv(string $tmp): array
    {
        $rows = [];
        $fp = fopen($tmp, 'r');
        if (!$fp) {
            throw new RuntimeException('无法读取 CSV 文件');
        }

        while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            if (isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
            }
            $rows[] = $row;
        }

        fclose($fp);
        return $rows;
    }

    public static function normalizeRows(array $rows): array
    {
        if (!$rows) return [];
        $header = array_map(fn($v) => trim((string)$v), $rows[0]);
        $out = [];

        for ($i = 1; $i < count($rows); $i++) {
            if (!array_filter($rows[$i], fn($v) => trim((string)$v) !== '')) continue;
            $item = [];
            foreach ($header as $k => $h) {
                if ($h === '') continue;
                $item[$h] = trim((string)($rows[$i][$k] ?? ''));
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function pick(array $r, array $keys, string $default = ''): string
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $r) && trim((string)$r[$k]) !== '') {
                return trim((string)$r[$k]);
            }
        }
        return $default;
    }

    public static function mapRow(array $r): array
    {
        $fmtMap = [
            '纸质书'=>'paper','纸书'=>'paper','实体书'=>'paper','paper'=>'paper',
            '电子书'=>'ebook','电子'=>'ebook','微信读书'=>'ebook','Kindle'=>'ebook','kindle'=>'ebook','PDF'=>'ebook','pdf'=>'ebook','扫描版PDF'=>'ebook','ebook'=>'ebook',
            '有声书'=>'audiobook','听书'=>'audiobook','audiobook'=>'audiobook',
            'unknown'=>'unknown',''=>'unknown',
        ];

        $statusMap = [
            '未读'=>'unread','在读'=>'reading','已读'=>'finished','完成'=>'finished','未完成'=>'unfinished','弃读'=>'abandoned',
            'unread'=>'unread','reading'=>'reading','finished'=>'finished','unfinished'=>'unfinished','abandoned'=>'abandoned',
        ];

        $taskStatusMap = [
            '未读'=>'unread','在读'=>'reading','完成'=>'completed','已读'=>'completed','未完成'=>'unfinished','弃读'=>'abandoned',
            'completed'=>'completed','unread'=>'unread','reading'=>'reading','unfinished'=>'unfinished','abandoned'=>'abandoned',
        ];

        $levelMap = [
            '神作'=>'masterpiece','好书'=>'good','一般'=>'normal','烂书'=>'bad','弃读'=>'abandoned',
            'masterpiece'=>'masterpiece','good'=>'good','normal'=>'normal','bad'=>'bad','abandoned'=>'abandoned',
        ];

        $media = self::pick($r, ['载体']);
        $formatRaw = self::pick($r, ['阅读形式'], $media);
        $format = $fmtMap[$formatRaw] ?? 'unknown';
        if ($format === 'unknown' && $media !== '') {
            $format = $fmtMap[$media] ?? 'unknown';
        }

        $platform = self::pick($r, ['阅读平台']);
        if ($platform === '' && $media !== '') $platform = $media;

        $doubanId = self::pick($r, ['豆瓣ID']);
        $doubanUrl = self::pick($r, ['豆瓣URL','豆瓣链接']);
        if ($doubanId === '' && $doubanUrl !== '' && preg_match('~book\.douban\.com/subject/(\d+)~i', $doubanUrl, $m)) {
            $doubanId = $m[1];
        }

        $ratingScore = self::numberOrNull(self::pick($r, ['我的评分','自我评分']));
        $taskRating = self::numberOrNull(self::pick($r, ['本期评分','本期自我评分']));

        $finishDate = self::dateOrNull(self::pick($r, ['完成阅读日期','读完日期']));
        $actualFinish = self::dateOrNull(self::pick($r, ['实际完成阅读日期','实际读完']));
        $actualStart = self::dateOrNull(self::pick($r, ['实际开始阅读日期','实际开始读']));
        $plannedStart = self::dateOrNull(self::pick($r, ['计划开始阅读日期','计划开始读']));
        $plannedFinish = self::dateOrNull(self::pick($r, ['计划完成阅读日期','计划读完']));

        $notes = self::pick($r, ['笔记']);
        $extra = [];
        foreach (['序号','国别原文','译者','是否全本','载体'] as $k) {
            $v = self::pick($r, [$k]);
            if ($v !== '') $extra[] = $k . '：' . $v;
        }
        if ($extra) $notes = trim($notes . ($notes ? '；' : '') . implode('；', $extra));

        return [
            'original_index'=>self::pick($r, ['序号','原始序号']),
            'title'=>self::pick($r, ['书名']),
            'subtitle'=>self::pick($r, ['副标题']),
            'original_title'=>self::pick($r, ['原书名']),
            'author'=>self::pick($r, ['作者']),
            'author_country'=>self::pick($r, ['作者国籍']),
            'author_country_raw'=>self::pick($r, ['国别原文']),
            'translator'=>self::pick($r, ['译者']),
            'publisher'=>self::pick($r, ['出版社']),
            'publish_date'=>self::pick($r, ['出版日期','出版年份']),
            'publish_year'=>self::pick($r, ['出版年份']),
            'isbn'=>self::pick($r, ['ISBN']),
            'book_type'=>self::pick($r, ['书籍类型']),
            'tags'=>self::pick($r, ['标签']),
            'media_text'=>$media,
            'format'=>$format,
            'platform'=>$platform,
            'is_full'=>self::pick($r, ['是否全本']),
            'is_paper'=>self::boolOrNull(self::pick($r, ['是否纸质书'])),
            'is_ebook'=>self::boolOrNull(self::pick($r, ['是否电子书'])),
            'is_audiobook'=>self::boolOrNull(self::pick($r, ['是否有声书'])),

            'status'=>$statusMap[self::pick($r, ['阅读状态'])] ?? 'unread',
            'progress'=>min(100, max(0, (int)self::pick($r, ['阅读进度'], '0'))),
            'first_start_date'=>self::dateOrNull(self::pick($r, ['开始阅读日期'])),
            'final_finish_date'=>$finishDate,
            'rating_score'=>$ratingScore,
            'self_rating_note'=>self::pick($r, ['自我评分']),
            'rating_level'=>$levelMap[self::pick($r, ['我的等级'])] ?? null,
            'short_review'=>self::pick($r, ['短评']),
            'notes'=>$notes,

            'douban_subject_id'=>$doubanId ?: null,
            'douban_url'=>$doubanUrl ?: ($doubanId ? 'https://book.douban.com/subject/' . $doubanId . '/' : null),
            'douban_title'=>self::pick($r, ['豆瓣书名']),
            'douban_author'=>self::pick($r, ['豆瓣作者']),
            'douban_publisher'=>self::pick($r, ['豆瓣出版社']),
            'douban_publish_date'=>self::pick($r, ['豆瓣出版日期']),
            'douban_rating'=>self::numberOrNull(self::pick($r, ['豆瓣评分'])),
            'douban_rating_count'=>self::intOrNull(self::pick($r, ['豆瓣评价人数'])),
            'douban_read_count'=>self::intOrNull(self::pick($r, ['豆瓣阅读人数','豆瓣读过人数'])),
            'douban_wish_count'=>self::intOrNull(self::pick($r, ['豆瓣想读人数'])),
            'douban_summary'=>null,

            '_history_name'=>self::pick($r, ['历史名称'], '历史导入记录'),
            '_history_start'=>self::dateOrNull(self::pick($r, ['历史开始日期'])),
            '_history_end'=>self::dateOrNull(self::pick($r, ['历史结束日期'])),
            '_planned_start'=>$plannedStart,
            '_planned_finish'=>$plannedFinish,
            '_actual_start'=>$actualStart,
            '_actual_finish'=>$actualFinish,
            '_task_status'=>$taskStatusMap[self::pick($r, ['历史状态'])] ?? null,
            '_task_progress'=>min(100, max(0, (int)self::pick($r, ['历史进度','阅读进度'], '0'))),
            '_continue_next'=>in_array(strtolower(self::pick($r, ['是否延续'])), ['1','是','yes','true'], true) ? 1 : 0,
            '_task_rating_score'=>$taskRating,
            '_task_rating_level'=>$levelMap[self::pick($r, ['本期等级'])] ?? null,
            '_task_comment'=>self::pick($r, ['本期备注']),
        ];
    }

    private static function boolOrNull(string $v): ?int
    {
        $v = trim($v);
        if ($v === '') return null;
        return in_array(strtolower($v), ['1','是','yes','true'], true) ? 1 : 0;
    }

    private static function numberOrNull(string $v): ?float
    {
        $v = trim(str_replace(',', '', $v));
        if ($v === '') return null;
        return is_numeric($v) ? (float)$v : null;
    }

    private static function intOrNull(string $v): ?int
    {
        $v = trim(str_replace(',', '', $v));
        if ($v === '') return null;
        return is_numeric($v) ? (int)$v : null;
    }

    private static function dateOrNull(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') return null;

        if (is_numeric($v) && (float)$v > 25000) {
            $timestamp = ((float)$v - 25569) * 86400;
            return gmdate('Y-m-d', (int)$timestamp);
        }

        $v = str_replace(['/', '.', '年', '月'], ['-', '-', '-', '-'], $v);
        $v = str_replace('日', '', $v);
        $v = preg_replace('/-+/', '-', $v);
        $v = trim($v, '-');

        $t = strtotime($v);
        return $t ? date('Y-m-d', $t) : null;
    }
}
