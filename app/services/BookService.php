<?php

class BookService
{
    public static function createFromRow(array $row, int $userId): int
    {
        $pdo = db();
        $doubanId = $row['douban_subject_id'] ?? null;

        $stmt = $pdo->prepare('
            INSERT INTO books (
                user_id,original_index,title,subtitle,original_title,author,translator,author_country,author_country_raw,
                publisher,publish_date,publish_year,isbn,book_type,tags,media_text,format,platform,
                is_full,is_paper,is_ebook,is_audiobook,
                status,progress,first_start_date,final_finish_date,rating_score,self_rating_note,rating_level,short_review,notes,
                douban_subject_id,douban_url,douban_title,douban_author,douban_publisher,douban_publish_date,
                douban_cover_url,douban_rating,douban_rating_count,douban_read_count,douban_wish_count,
                douban_summary,douban_updated_at
            )
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(? IS NULL,NULL,NOW()))
        ');

        $stmt->execute([
            $userId,
            $row['original_index'] ?: null,
            $row['title'] ?: '未命名',
            $row['subtitle'] ?: null,
            $row['original_title'] ?: null,
            $row['author'] ?: null,
            $row['translator'] ?: null,
            $row['author_country'] ?: null,
            $row['author_country_raw'] ?: null,
            $row['publisher'] ?: null,
            $row['publish_date'] ?: null,
            $row['publish_year'] ?: null,
            $row['isbn'] ?: null,
            $row['book_type'] ?: null,
            $row['tags'] ?: null,
            $row['media_text'] ?: null,
            $row['format'] ?: 'unknown',
            $row['platform'] ?: null,
            $row['is_full'] ?: null,
            $row['is_paper'],
            $row['is_ebook'],
            $row['is_audiobook'],
            $row['status'] ?: 'unread',
            (int)($row['progress'] ?? 0),
            $row['first_start_date'] ?: null,
            $row['final_finish_date'] ?: null,
            ($row['rating_score'] ?? null) ?: null,
            $row['self_rating_note'] ?: null,
            ($row['rating_level'] ?? null) ?: null,
            $row['short_review'] ?: null,
            $row['notes'] ?: null,
            $doubanId,
            $row['douban_url'] ?: ($doubanId ? 'https://book.douban.com/subject/' . $doubanId . '/' : null),
            $row['douban_title'] ?: null,
            $row['douban_author'] ?: null,
            $row['douban_publisher'] ?: null,
            $row['douban_publish_date'] ?: null,
            $row['douban_cover_url'] ?? null,
            ($row['douban_rating'] ?? null) ?: null,
            ($row['douban_rating_count'] ?? null) ?: null,
            ($row['douban_read_count'] ?? null) ?: null,
            ($row['douban_wish_count'] ?? null) ?: null,
            $row['douban_summary'] ?? null,
            $doubanId
        ]);

        return (int)$pdo->lastInsertId();
    }
}
