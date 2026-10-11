<?php

class DoubanService
{
    public static function extractSubjectId(string $url): ?string
    {
        if (preg_match('~book\.douban\.com/subject/(\d+)~i', $url, $m)) {
            return $m[1];
        }
        if (preg_match('~subject/(\d+)~i', $url, $m)) {
            return $m[1];
        }
        if (preg_match('~^\s*(\d{5,20})\s*$~', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function urlFromId(string $id): string
    {
        return 'https://book.douban.com/subject/' . rawurlencode($id) . '/';
    }

    private static function localApiBase(): string
    {
        $base = getenv('DOUBAN_LOCAL_API') ?: 'http://127.0.0.1:8085';

        try {
            $config = require __DIR__ . '/../config.php';
            if (!empty($config['douban']['local_api'])) {
                $base = (string)$config['douban']['local_api'];
            }
        } catch (Throwable $e) {}

        return rtrim($base, '/');
    }

    private static function httpJson(string $url): array
    {
        $last = null;

        for ($try = 1; $try <= 4; $try++) {
            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_USERAGENT => 'Mozilla/5.0 YuexinBookMeta/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json,*/*;q=0.8',
                    'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                    'Connection: close',
                ],
            ]);

            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if (!is_string($body) || $body === '') {
                $last = $err ?: '本地豆瓣 API 无响应';
            } elseif ($code >= 500) {
                $last = '本地豆瓣 API HTTP 状态码：' . $code . '，返回：' . mb_substr(strip_tags($body), 0, 180);
            } elseif ($code >= 400) {
                throw new RuntimeException('本地豆瓣 API HTTP 状态码：' . $code . '，返回：' . mb_substr(strip_tags($body), 0, 180));
            } else {
                $json = json_decode($body, true);

                if (!is_array($json)) {
                    throw new RuntimeException('本地豆瓣 API 返回不是 JSON：' . mb_substr($body, 0, 180));
                }

                return $json;
            }

            // 500/空响应通常是第三方 API 或上游临时异常，稍等后重试
            usleep($try * 150000);
        }

        throw new RuntimeException($last ?: '本地豆瓣 API 请求失败');
    }

    private static function stripHtml(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }

        $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        $s = preg_replace('~</p>\s*<p[^>]*>~i', "\n", $s);
        $s = preg_replace('~<br\s*/?>~i', "\n", $s);
        $s = trim(strip_tags($s));
        $s = preg_replace("/\n{3,}/", "\n\n", $s);

        return $s === '' ? null : $s;
    }

    private static function normalizeCover(?string $url): ?string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        /*
         * simple-boot-douban-api 返回的封面经常是：
         * http://127.0.0.1:8085/view/cover?cover=https://img...
         * 不能直接存这个给浏览器，因为用户浏览器访问自己的 127.0.0.1 会失败。
         * 所以这里提取原始 img 链接。
         */
        $parts = parse_url($url);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['cover']) && is_string($q['cover'])) {
                return $q['cover'];
            }
        }

        return $url;
    }

    private static function arrText($v): ?string
    {
        if (is_array($v)) {
            $v = array_values(array_filter(array_map('strval', $v), fn($x) => trim($x) !== ''));
            return $v ? implode(' / ', $v) : null;
        }

        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    private static function mapLocalBook(array $b): ?array
    {
        $id = (string)($b['id'] ?? $b['subject_id'] ?? $b['douban_subject_id'] ?? '');

        if ($id === '' && !empty($b['url'])) {
            $id = self::extractSubjectId((string)$b['url']) ?: '';
        }

        if ($id === '' || !preg_match('/^\d{5,20}$/', $id)) {
            return null;
        }

        $rating = null;
        if (isset($b['rating']['average'])) {
            $rating = $b['rating']['average'];
        } elseif (isset($b['rating_average'])) {
            $rating = $b['rating_average'];
        } elseif (isset($b['average'])) {
            $rating = $b['average'];
        } elseif (isset($b['douban_rating'])) {
            $rating = $b['douban_rating'];
        }

        $author = self::arrText($b['author'] ?? $b['authors'] ?? null);
        $translator = self::arrText($b['translator'] ?? null);

        $title = trim((string)($b['title'] ?? $b['douban_title'] ?? ''));

        return [
            'douban_subject_id' => $id,
            'douban_url' => $b['url'] ?? self::urlFromId($id),
            'douban_title' => $title ?: null,
            'douban_author' => $author,
            'douban_publisher' => self::arrText($b['publisher'] ?? null),
            'douban_publish_date' => self::arrText($b['pubdate'] ?? $b['publish_date'] ?? null),
            'douban_cover_url' => self::normalizeCover($b['image'] ?? $b['cover'] ?? $b['cover_url'] ?? null),
            'douban_rating' => ($rating !== '' ? $rating : null),
            'douban_rating_count' => $b['rating_count'] ?? $b['ratings_count'] ?? null,
            'douban_read_count' => $b['read_count'] ?? null,
            'douban_wish_count' => $b['wish_count'] ?? null,
            'douban_summary' => self::stripHtml($b['summary'] ?? null),

            // 给匹配算法用
            'title' => $title ?: null,
            'author' => $author,
            'translator' => $translator,
            'publisher' => self::arrText($b['publisher'] ?? null),
            'publish_date' => self::arrText($b['pubdate'] ?? $b['publish_date'] ?? null),
            'isbn' => self::arrText($b['isbn13'] ?? $b['isbn10'] ?? $b['isbn'] ?? null),
            'pages' => self::arrText($b['pages'] ?? null),
            'binding' => self::arrText($b['binding'] ?? null),
            'price' => self::arrText($b['price'] ?? null),
        ];
    }

    private static function extractBooksFromApi(array $json): array
    {
        $raw = [];

        if (isset($json['books']) && is_array($json['books'])) {
            $raw = $json['books'];
        } elseif (isset($json['book']) && is_array($json['book'])) {
            $raw = [$json['book']];
        } elseif (isset($json['data']['books']) && is_array($json['data']['books'])) {
            $raw = $json['data']['books'];
        } elseif (isset($json['data']) && is_array($json['data']) && isset($json['data']['id'])) {
            $raw = [$json['data']];
        } elseif (isset($json['id'])) {
            $raw = [$json];
        }

        $out = [];

        foreach ($raw as $b) {
            if (!is_array($b)) {
                continue;
            }

            $mapped = self::mapLocalBook($b);
            if ($mapped) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    private static function searchLocalBooks(string $keyword): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        $url = self::localApiBase() . '/v2/book/search?q=' . rawurlencode($keyword);
        $json = self::httpJson($url);

        if (isset($json['success']) && !$json['success']) {
            $msg = $json['message'] ?? '本地豆瓣 API 搜索失败';
            throw new RuntimeException((string)$msg);
        }

        return self::extractBooksFromApi($json);
    }

    public static function searchSubjectIds(string $keyword): array
    {
        $ids = [];

        foreach (self::searchLocalBooks($keyword) as $b) {
            $id = (string)($b['douban_subject_id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    public static function fetchBookBySubjectId(string $id): array
    {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException('豆瓣 ID 为空');
        }

        /*
         * 先尝试常见详情接口。
         * 如果当前镜像不支持，再用 search?q=id 兜底。
         */
        $urls = [
            self::localApiBase() . '/v2/book/' . rawurlencode($id),
            self::localApiBase() . '/v2/book/subject/' . rawurlencode($id),
            self::localApiBase() . '/v2/book/search?q=' . rawurlencode($id),
        ];

        $lastErr = null;

        foreach ($urls as $url) {
            try {
                $json = self::httpJson($url);
                $books = self::extractBooksFromApi($json);

                foreach ($books as $b) {
                    if ((string)($b['douban_subject_id'] ?? '') === $id) {
                        return $b;
                    }
                }

                if ($books) {
                    return $books[0];
                }
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
                continue;
            }
        }

        throw new RuntimeException('本地豆瓣 API 未找到 subject_id=' . $id . ($lastErr ? '；最后错误：' . $lastErr : ''));
    }

    public static function autoBindBookById(int $bookId, int $userId): bool
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM books WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$bookId, $userId]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$book) {
            return false;
        }

        self::markTry($bookId, $userId);

        try {
            $subjectId = null;

            if (!empty($book['douban_subject_id'])) {
                $subjectId = (string)$book['douban_subject_id'];
            }

            if (!$subjectId && !empty($book['douban_url'])) {
                $subjectId = self::extractSubjectId((string)$book['douban_url']);
            }

            if ($subjectId) {
                $data = self::fetchBookBySubjectId($subjectId);
                self::bindBook($bookId, $userId, $data, 100);
                self::markStatus($bookId, $userId, 'matched', null, 100);
                return true;
            }

            $candidates = self::findCandidatesForBook($book);

            if (!$candidates) {
                self::markStatus($bookId, $userId, 'not_found', '本地豆瓣 API 没有找到候选', 0);
                return false;
            }

            $best = self::chooseBestCandidate($book, $candidates);

            if (!$best) {
                self::markStatus($bookId, $userId, 'not_found', '没有可用候选', 0);
                return false;
            }

            $score = (int)($best['_match_score'] ?? self::matchScore($book, $best));

            if ($score < 35) {
                self::markStatus($bookId, $userId, 'low_confidence', '候选匹配度过低', $score);
                return false;
            }

            self::bindBook($bookId, $userId, $best, $score);
            self::markStatus($bookId, $userId, 'matched', null, $score);
            return true;
        } catch (Throwable $e) {
            self::markStatus($bookId, $userId, 'fetch_failed', $e->getMessage(), 0);
            return false;
        }
    }

    public static function findCandidatesForBook(array $book): array
    {
        $queries = [];

        if (!empty($book['isbn'])) {
            $queries[] = trim((string)$book['isbn']);
        }

        $title = trim((string)($book['title'] ?? ''));
        $author = trim((string)($book['author'] ?? ''));
        $publisher = trim((string)($book['publisher'] ?? ''));

        if ($title !== '') {
            $queries[] = $title;

            if ($author !== '') {
                $queries[] = $title . ' ' . $author;
            }

            if ($publisher !== '') {
                $queries[] = $title . ' ' . $publisher;
            }
        }

        $items = [];

        foreach (array_unique(array_filter($queries)) as $q) {
            try {
                foreach (self::searchLocalBooks($q) as $b) {
                    $id = (string)($b['douban_subject_id'] ?? '');
                    if ($id !== '') {
                        $items[$id] = $b;
                    }
                }
            } catch (Throwable $e) {
                continue;
            }

            if (count($items) >= 20) {
                break;
            }
        }

        return array_values($items);
    }

    public static function chooseBestCandidate(array $book, array $candidates): ?array
    {
        if (!$candidates) {
            return null;
        }

        $scored = [];

        foreach ($candidates as $c) {
            $score = self::matchScore($book, $c);
            $c['_match_score'] = $score;
            $c['_read_count_sort'] = (int)($c['douban_read_count'] ?? 0);
            $c['_rating_count_sort'] = (int)($c['douban_rating_count'] ?? 0);
            $scored[] = $c;
        }

        usort($scored, function ($a, $b) {
            return ((int)$b['_match_score'] <=> (int)$a['_match_score'])
                ?: ((int)$b['_read_count_sort'] <=> (int)$a['_read_count_sort'])
                ?: ((float)($b['douban_rating'] ?? 0) <=> (float)($a['douban_rating'] ?? 0));
        });

        return $scored[0] ?? null;
    }

    public static function matchScore(array $book, array $candidate): int
    {
        $score = 0;

        $bookIsbn = preg_replace('/\D+/', '', (string)($book['isbn'] ?? ''));
        $candIsbn = preg_replace('/\D+/', '', (string)($candidate['isbn'] ?? ''));

        if ($bookIsbn !== '' && $candIsbn !== '' && $bookIsbn === $candIsbn) {
            $score += 100;
        }

        $bt = self::normTitle((string)($book['title'] ?? ''));
        $ct = self::normTitle((string)($candidate['douban_title'] ?? $candidate['title'] ?? ''));

        if ($bt !== '' && $ct !== '') {
            if ($bt === $ct) {
                $score += 75;
            } else {
                $sim = self::titleSimilar($bt, $ct);

                if ($sim >= 0.92) {
                    $score += 65;
                } elseif ($sim >= 0.82) {
                    $score += 50;
                } elseif (str_contains($ct, $bt) || str_contains($bt, $ct)) {
                    $score += 42;
                }
            }
        }

        $ba = self::normText((string)($book['author'] ?? ''));
        $ca = self::normText((string)($candidate['douban_author'] ?? $candidate['author'] ?? ''));

        if ($ba !== '' && $ca !== '') {
            if (str_contains($ca, $ba) || str_contains($ba, $ca)) {
                $score += 18;
            }
        }

        $bp = self::normText((string)($book['publisher'] ?? ''));
        $cp = self::normText((string)($candidate['douban_publisher'] ?? $candidate['publisher'] ?? ''));

        if ($bp !== '' && $cp !== '') {
            if (str_contains($cp, $bp) || str_contains($bp, $cp)) {
                $score += 8;
            }
        }

        return min(100, $score);
    }

    public static function bindBook(int $bookId, int $userId, array $data, int $score = 0): void
    {
        $pdo = db();
        $id = $data['douban_subject_id'] ?? null;

        if (!$id) {
            return;
        }

        $stmt = $pdo->prepare('
            UPDATE books SET
                douban_subject_id=?,
                douban_url=?,
                douban_title=?,
                douban_author=?,
                douban_publisher=?,
                douban_publish_date=?,
                douban_cover_url=?,
                douban_rating=?,
                douban_rating_count=?,
                douban_read_count=?,
                douban_wish_count=?,
                douban_summary=?,
                douban_updated_at=NOW(),
                douban_match_score=?,
                cover_url=COALESCE(NULLIF(cover_url,""), ?)
            WHERE id=? AND user_id=?
        ');

        $cover = $data['douban_cover_url'] ?? null;

        $stmt->execute([
            $id,
            $data['douban_url'] ?? self::urlFromId((string)$id),
            $data['douban_title'] ?? $data['title'] ?? null,
            $data['douban_author'] ?? $data['author'] ?? null,
            $data['douban_publisher'] ?? $data['publisher'] ?? null,
            $data['douban_publish_date'] ?? $data['publish_date'] ?? null,
            $cover,
            ($data['douban_rating'] ?? null) ?: null,
            ($data['douban_rating_count'] ?? null) ?: null,
            ($data['douban_read_count'] ?? null) ?: null,
            ($data['douban_wish_count'] ?? null) ?: null,
            $data['douban_summary'] ?? null,
            $score,
            $cover,
            $bookId,
            $userId
        ]);
    }

    private static function markTry(int $bookId, int $userId): void
    {
        try {
            db()->prepare('UPDATE books SET douban_last_try_at=NOW(), douban_last_error=NULL WHERE id=? AND user_id=?')
                ->execute([$bookId, $userId]);
        } catch (Throwable $e) {}
    }

    private static function markStatus(int $bookId, int $userId, string $status, ?string $error = null, int $score = 0): void
    {
        try {
            db()->prepare('
                UPDATE books
                SET douban_match_status=?,
                    douban_last_error=?,
                    douban_match_score=?,
                    douban_last_try_at=NOW()
                WHERE id=? AND user_id=?
            ')->execute([$status, $error, $score, $bookId, $userId]);
        } catch (Throwable $e) {}
    }

    private static function normTitle(string $s): string
    {
        $s = self::normText($s);
        $s = preg_replace('/[《》〈〉“”"\'：:，,。\.·\-—\s\(\)（）\[\]【】]+/u', '', $s);
        return $s ?? '';
    }

    private static function normText(string $s): string
    {
        $s = trim(strip_tags(html_entity_decode($s, ENT_QUOTES, 'UTF-8')));
        $s = preg_replace('/\s+/u', '', $s);
        return mb_strtolower($s ?? '', 'UTF-8');
    }

    private static function titleSimilar(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $pct);
        return $pct / 100;
    }
}
