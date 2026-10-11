<?php
function book_form(array $b=[]){
    $v = fn($k, $d='') => e($b[$k] ?? $d);
?>
<div class="form-grid">
    <div><label>书名 *</label><input name="title" required value="<?= $v('title') ?>"></div>
    <div><label>副标题</label><input name="subtitle" value="<?= $v('subtitle') ?>"></div>
    <div><label>原书名</label><input name="original_title" value="<?= $v('original_title') ?>"></div>
    <div><label>作者</label><input name="author" value="<?= $v('author') ?>"></div>
    <div><label>作者国籍</label><input name="author_country" value="<?= $v('author_country') ?>"></div>
    <div><label>国别原文</label><input name="author_country_raw" value="<?= $v('author_country_raw') ?>"></div>
    <div><label>译者</label><input name="translator" value="<?= $v('translator') ?>"></div>
    <div><label>出版社</label><input name="publisher" value="<?= $v('publisher') ?>"></div>
    <div><label>出版日期</label><input name="publish_date" value="<?= $v('publish_date') ?>" placeholder="例：2024-05 / 2024"></div>
    <div><label>出版年份</label><input name="publish_year" value="<?= $v('publish_year') ?>" placeholder="例：2024"></div>
    <div><label>ISBN</label><input name="isbn" value="<?= $v('isbn') ?>"></div>
    <div><label>豆瓣 URL</label><input name="douban_url" value="<?= $v('douban_url') ?>" placeholder="https://book.douban.com/subject/xxxxxxx/"></div>
    <div><label>书籍类型</label><input name="book_type" value="<?= $v('book_type') ?>"></div>
    <div><label>标签</label><input name="tags" value="<?= $v('tags') ?>"></div>
    <div><label>载体</label><input name="media_text" value="<?= $v('media_text') ?>"></div>
    <div><label>阅读形式</label><select name="format"><?php foreach(['unknown'=>'未选择','paper'=>'纸质书','ebook'=>'电子书','audiobook'=>'有声书'] as $k=>$label):?><option value="<?= $k ?>" <?= ($b['format']??'unknown')===$k?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select></div>
    <div><label>平台</label><input name="platform" value="<?= $v('platform') ?>"></div>
    <div><label>是否全本</label><input name="is_full" value="<?= $v('is_full') ?>"></div>
    <div><label><input type="checkbox" name="is_paper" value="1" <?= !empty($b['is_paper'])?'checked':'' ?>> 纸质书</label></div>
    <div><label><input type="checkbox" name="is_ebook" value="1" <?= !empty($b['is_ebook'])?'checked':'' ?>> 电子书</label></div>
    <div><label><input type="checkbox" name="is_audiobook" value="1" <?= !empty($b['is_audiobook'])?'checked':'' ?>> 有声书</label></div>
    <div><label>状态</label><select name="status"><?php foreach(['unread'=>'未读','reading'=>'在读','finished'=>'已读','unfinished'=>'未完成','abandoned'=>'弃读'] as $k=>$label):?><option value="<?= $k ?>" <?= ($b['status']??'unread')===$k?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select></div>
    <div><label>进度%</label><input type="number" min="0" max="100" name="progress" value="<?= $v('progress', 0) ?>"></div>
    <div><label>开始阅读日期</label><input type="date" name="first_start_date" value="<?= $v('first_start_date') ?>"></div>
    <div><label>读完日期</label><input type="date" name="final_finish_date" value="<?= $v('final_finish_date') ?>"></div>
    <div><label>我的评分</label><input type="number" step="0.1" min="0" max="10" name="rating_score" value="<?= $v('rating_score') ?>"></div>
    <div><label>我的等级</label><select name="rating_level"><option value="">未评</option><?php foreach(['masterpiece'=>'神作','good'=>'好书','normal'=>'一般','bad'=>'烂书','abandoned'=>'弃读'] as $k=>$label):?><option value="<?= $k ?>" <?= ($b['rating_level']??'')===$k?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select></div>
    <div><label>封面 URL</label><input name="cover_url" value="<?= $v('cover_url') ?>"></div>
</div>
<p><label>短评</label><textarea name="short_review"><?= $v('short_review') ?></textarea></p>
<p><label>笔记</label><textarea name="notes"><?= $v('notes') ?></textarea></p>
<?php } ?>
