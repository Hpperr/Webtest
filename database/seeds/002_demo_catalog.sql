INSERT INTO books (genre_id, slug, title, author_name, description, status, visibility, moderation_status, read_count)
SELECT id, 'nguoi-giu-vuon', 'Người giữ vườn', 'Mai Chi',
       'Một khu vườn lạ mở ra những câu chuyện về ký ức và cơ hội bắt đầu lại. Nội dung minh họa.',
       'ongoing', 'public', 'approved', 0
FROM genres WHERE slug = 'huyen-huyen'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO books (genre_id, slug, title, author_name, description, status, visibility, moderation_status, read_count)
SELECT id, 'di-qua-pho-cu', 'Đi qua phố cũ', 'An Nhiên',
       'Một câu chuyện hư cấu về những người trẻ tìm lại nhịp sống giữa thành phố. Nội dung minh họa.',
       'completed', 'public', 'approved', 0
FROM genres WHERE slug = 'thanh-xuan'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO books (genre_id, slug, title, author_name, description, status, visibility, moderation_status, read_count)
SELECT id, 'ban-do-nhung-vi-sao', 'Bản đồ những vì sao', 'Khải Nguyên',
       'Một chuyến du hành hư cấu qua những trạm dừng xa lạ. Nội dung minh họa.',
       'ongoing', 'public', 'approved', 0
FROM genres WHERE slug = 'khoa-huyen'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO chapters (book_id, chapter_number, part_number, title, content, word_count, visibility, published_at)
SELECT id, 1, 1, 'Cánh cửa phía đông',
       'Sáng hôm ấy, Lam nghe thấy tiếng chuông trong khu vườn lần đầu tiên.\n\nSương vẫn còn đọng trên những phiến lá. Cô đặt chiếc bình tưới xuống bậc thềm, ngẩng nhìn bức tường phủ dây leo. Giữa những chiếc lá xanh sẫm, một cánh cửa gỗ hiện ra.\n\nCô bước đến gần. Những bông hoa ven tường khẽ rung, dù gió đã ngừng từ lúc bình minh.\n\nCó những lối đi không dẫn ta đến một nơi xa lạ. Chúng chỉ đưa ta trở về gần hơn với chính mình.',
       71, 'public', CURRENT_TIMESTAMP
FROM books WHERE slug = 'nguoi-giu-vuon'
AND NOT EXISTS (SELECT 1 FROM chapters WHERE book_id = books.id AND chapter_number = 1 AND part_number = 1);

INSERT INTO chapters (book_id, chapter_number, part_number, title, content, word_count, visibility, published_at)
SELECT id, 1, 1, 'Con đường quen',
       'Chiều xuống chậm trên con phố nhỏ. An dừng chân trước mái hiên cũ, nơi tiếng mưa đầu mùa vẫn còn đọng trong trí nhớ.\n\nMột ô cửa sáng đèn. Có người đang đợi, và có những câu chuyện chỉ bắt đầu khi ta chịu quay về.\n\n— Hết chương —',
       42, 'public', CURRENT_TIMESTAMP
FROM books WHERE slug = 'di-qua-pho-cu'
AND NOT EXISTS (SELECT 1 FROM chapters WHERE book_id = books.id AND chapter_number = 1 AND part_number = 1);

INSERT INTO chapters (book_id, chapter_number, part_number, title, content, word_count, visibility, published_at)
SELECT id, 1, 1, 'Trạm dừng thứ nhất',
       'Con tàu rời quỹ đạo vào lúc bình minh. Trên tấm bản đồ, một chấm sáng vừa xuất hiện ở phía bên kia dải ngân hà.\n\nKhải ghi lại tọa độ, rồi nhìn những vì sao xa lạ qua ô cửa. Hành trình của cậu bắt đầu từ một câu hỏi chưa ai trả lời.\n\n— Hết chương —',
       39, 'public', CURRENT_TIMESTAMP
FROM books WHERE slug = 'ban-do-nhung-vi-sao'
AND NOT EXISTS (SELECT 1 FROM chapters WHERE book_id = books.id AND chapter_number = 1 AND part_number = 1);
