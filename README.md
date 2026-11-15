# Sách Hay

Ứng dụng đọc truyện tiếng Việt dùng PHP modular monolith, MySQL/MariaDB và HTML/CSS/JavaScript. Giao diện dùng Bootstrap 5.3.3 CSS từ jsDelivr (Bootstrap phát hành theo giấy phép MIT), không cần Node.js hoặc bước build frontend. Thông báo được lưu trực tiếp trong MySQL và tải khi mở trang, chuyển trang hoặc bấm nút làm mới; không có polling nền hay realtime.

## Yêu cầu môi trường

- PHP 8.2+ với `pdo_mysql`, `mbstring`, `json`, `dom`, `fileinfo`, `zip`
- MySQL 5.7+ hoặc MariaDB tương thích, có InnoDB, JSON và `utf8mb4`
- XAMPP Apache với `mod_rewrite` và `mod_headers`
- Composer để cài PDF parser cho tính năng nhập PDF
- Kết nối Internet để tải Bootstrap 5.3.3 và font từ CDN

## Thiết lập local

1. Cài/khởi động Apache và MySQL trong XAMPP. Trong môi trường đã kiểm tra, PHP CLI là 8.2.12 và MariaDB là 10.4.32; xác minh runtime XAMPP của bạn bằng `C:\xampp\php\php.exe -v` và `C:\xampp\mysql\bin\mysql.exe --version`. Kiểm tra extension với `C:\xampp\php\php.exe -m`; cần `pdo_mysql`, `mbstring`, `json`, `dom`, `fileinfo`, `zip`. Nếu `zip` chưa bật, bỏ dấu `;` trước `extension=zip` trong `C:\xampp\php\php.ini` (DLL có tại `C:\xampp\php\ext\php_zip.dll`) rồi khởi động lại Apache.

2. Đặt dự án ở `C:\xampp\htdocs\sach-hay` hoặc giữ worktree hiện tại và cấu hình Apache VirtualHost. Document root phải trỏ tới thư mục `public`, không phải thư mục gốc repository. Bật `mod_rewrite` và `mod_headers` trong `C:\xampp\apache\conf\httpd.conf`, đồng thời bật include `conf/extra/httpd-vhosts.conf`. Thêm vào `C:\xampp\apache\conf\extra\httpd-vhosts.conf` (điều chỉnh đường dẫn nếu cần):

```apache
<VirtualHost *:80>
    ServerName sach-hay.test
    DocumentRoot "C:/xampp/htdocs/sach-hay/public"
    <Directory "C:/xampp/htdocs/sach-hay/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Thêm `127.0.0.1 sach-hay.test` vào `C:\Windows\System32\drivers\etc\hosts`, khởi động lại Apache và mở `http://sach-hay.test`. `public/.htaccess` xử lý API, route giao diện và các header bảo mật.

3. Tạo database MySQL/MariaDB `trang_thu` với charset `utf8mb4` (ví dụ dưới đây dùng tài khoản XAMPP `root` không mật khẩu; nếu đã đặt mật khẩu, thêm `-p`):

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' --default-character-set=utf8mb4 -u root -e 'CREATE DATABASE trang_thu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
```
4. Sao chép `.env.example` thành `.env`, đặt DB credentials đúng với XAMPP, rồi tạo `APP_KEY` riêng bằng `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Chỉ dùng `SESSION_SECURE_COOKIE=false` ở local.
5. Cài thư viện PHP để đọc PDF:

```powershell
composer install
```

6. Chạy các migration và seed theo thứ tự. Migration 008 loại bỏ bảng outbox cũ sau khi đã áp migration 006; không sửa các migration lịch sử đã có:

```powershell
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$files = @(
    'database/migrations/001_initial_schema.sql',
    'database/migrations/002_login_throttling.sql',
    'database/migrations/003_reading_progress.sql',
    'database/migrations/004_moderation_audit.sql',
    'database/migrations/005_import_book_link.sql',
    'database/migrations/006_notification_outbox.sql',
    'database/migrations/007_chapter_moderation.sql',
    'database/migrations/008_remove_notification_outbox.sql',
    'database/seeds/001_genres.sql',
    'database/seeds/002_demo_catalog.sql'
)
foreach ($file in $files) {
    & $mysql --default-character-set=utf8mb4 -u root trang_thu -e "SOURCE $file"
    if ($LASTEXITCODE -ne 0) { throw "Database script failed: $file" }
}
```

Nếu database hiện tại đã áp dụng migrations 001–007, không chạy lại các migration lịch sử; chỉ áp dụng `database/migrations/008_remove_notification_outbox.sql`.

7. Mở `http://sach-hay.test`. Nếu chỉ cần kiểm tra nhanh bằng PHP built-in server nhưng vẫn dùng MySQL từ XAMPP:

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 -t public router.php
```

Để xử lý hàng đợi nhập truyện trong môi trường phát triển, chạy worker từ thư mục gốc sau khi tải tệp lên:

```powershell
php app/workers/import-worker.php
```

Mốc chia chương mặc định 7.000 từ là giá trị vận hành ban đầu, không phải giới hạn sản phẩm cố định; có thể điều chỉnh bằng `IMPORT_CHAPTER_WORD_LIMIT` trong `.env`.

Trong production, chạy worker bằng một process supervisor/queue runner riêng; không thực thi bộ phân tích tệp đồng bộ trong request web.

Thông báo được ghi trực tiếp vào MySQL trong cùng luồng xử lý thành viên/kiểm duyệt. Trình duyệt tải lại danh sách khi mở trang, chuyển màn hình hoặc người dùng bấm “Làm mới”; không có kết nối nền.

## Phần đã có trong nền tảng

- API công khai để liệt kê, lọc, tìm kiếm, sắp xếp, phân trang danh sách chương và xem truyện/chương; PDO dùng prepared statements.
- Schema MySQL ban đầu cho tài khoản, vai trò, truyện/chương, lịch sử, tủ sách, theo dõi, tương tác, bình luận, báo cáo, thông báo và hàng đợi nhập truyện.
- Đăng ký/đăng nhập bằng `password_hash`, phiên cookie HTTP-only/SameSite, kiểm tra CSRF và giới hạn thử đăng nhập.
- Quyền đọc nội dung chương được kiểm tra phía server; khách chỉ nhận metadata chương.
- Thành viên có thể lưu/gỡ truyện khỏi tủ cá nhân; lịch sử đọc và phần trăm tiến độ được lưu theo tài khoản.
- Thành viên có thể theo dõi truyện, bày tỏ cảm xúc, gửi bình luận và báo cáo; bình luận được hiển thị dưới dạng văn bản thuần.
- Quản trị viên/điều phối viên có thể duyệt truyện/chương, ẩn hoặc khôi phục chương đã duyệt, xử lý báo cáo và kiểm duyệt bình luận; quản trị viên còn có thể đổi vai trò/trạng thái tài khoản. Mọi thay đổi được ghi nhật ký kiểm duyệt.
- Chủ sở hữu truyện đã được duyệt có thể gửi chương mới; chương được ẩn cho đến khi điều phối viên/quản trị viên duyệt. Chương dài được chia tại ranh giới đoạn văn, người theo dõi chỉ nhận thông báo sau khi chương được xuất bản lần đầu.
- Thành viên có thể tải TXT/DOCX/PDF vào kho riêng ngoài web root; worker trích xuất văn bản, giữ tiêu đề chương và tách đoạn dài quanh mốc 7.000 từ để chờ duyệt.
- Thông báo theo tài khoản được lưu MySQL, có API xem/đánh dấu đã đọc; giao diện chỉ tải khi mở trang, điều hướng hoặc người dùng chủ động làm mới.
- UI kết nối danh mục, danh sách mới cập nhật/xếp hạng và phân trang chương từ PHP khi API khả dụng; khi chưa cấu hình backend, phần nội dung còn lại chỉ là dữ liệu minh họa. Reader preferences vẫn lưu local trên trình duyệt.

Nội dung seed là truyện mẫu hư cấu, không phải thư viện nội dung hoàn chỉnh. Quản trị viên đầu tiên phải được cấp quyền bằng một quy trình vận hành tin cậy; không có tài khoản quản trị mặc định trong seed. Tiến trình migration tự động, triển khai storage cloud và cấu hình production còn là các mốc phát triển tiếp theo. Thông báo không dùng Node.js/realtime.

Để cấp quyền quản trị ban đầu cho một tài khoản đã đăng ký, hãy xác minh danh tính qua kênh vận hành tin cậy rồi chạy lệnh SQL đặc quyền tương ứng, thay email bằng tài khoản thực tế:

```sql
UPDATE users SET role = 'admin' WHERE email = 'verified-admin@example.com' AND status = 'active';
```

## Kiểm tra

```powershell
& 'C:\xampp\php\php.exe' tests\run.php
```

Để chạy kiểm thử tích hợp luồng chương trên một database riêng đã áp migrations `001`–`008` và seed thể loại, cấu hình các biến `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_DATABASE` (tên chỉ gồm chữ/số/dấu gạch dưới và bắt buộc kết thúc bằng `_test`), `TEST_DB_USERNAME`, `TEST_DB_PASSWORD`, sau đó chạy:

```powershell
& 'C:\xampp\php\php.exe' tests\chapter-workflow.php
```
