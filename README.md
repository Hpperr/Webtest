# Sách Hay

Ứng dụng đọc truyện tiếng Việt đang được phát triển theo hướng PHP modular monolith, MySQL và một Node.js service riêng cho thông báo realtime. Frontend hiện dùng HTML/CSS/JavaScript thuần; không cần build frontend.

## Yêu cầu môi trường

- PHP 8.2+ với `pdo_mysql`, `mbstring`, `json`, `dom`, `fileinfo`, `zip`
- MySQL 5.7+ có hỗ trợ InnoDB, JSON và `utf8mb4` (khuyến nghị MySQL 8+ cho môi trường mới)
- Node.js 20+ cho service realtime
- Apache với `mod_rewrite` hoặc PHP built-in server khi phát triển

## Thiết lập local

1. Tạo database MySQL `trang_thu` với charset `utf8mb4`:

```powershell
mysql --default-character-set=utf8mb4 -u root -p -e 'CREATE DATABASE trang_thu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
```
2. Sao chép `.env.example` thành `.env`; đặt `APP_KEY` và `REALTIME_SERVICE_TOKEN` thành hai secret ngẫu nhiên riêng biệt, dài ít nhất 32 ký tự. Không dùng các giá trị mẫu trong production.
3. Cài thư viện PHP để đọc PDF:

```powershell
composer install
```

4. Chạy các migration và seed theo thứ tự:

```powershell
mysql --default-character-set=utf8mb4 -u root -p trang_thu -e 'SOURCE database/migrations/001_initial_schema.sql'
mysql --default-character-set=utf8mb4 -u root -p trang_thu -e 'SOURCE database/migrations/002_login_throttling.sql'
mysql --default-character-set=utf8mb4 -u root -p trang_thu -e 'SOURCE database/migrations/003_reading_progress.sql'
mysql --default-character-set=utf8mb4 -u root -p trang_thu -e 'SOURCE database/migrations/004_moderation_audit.sql'
mysql --default-character-set=utf8mb4 -u root -p trang_thu -e 'SOURCE database/migrations/005_import_book_link.sql'
mysql --default-character-set=utf8mb4 -u root -p trang_thu -e 'SOURCE database/migrations/006_notification_outbox.sql'
mysql --default-character-set=utf8mb4 -u root -p trang_thu -e 'SOURCE database/migrations/007_chapter_moderation.sql'
mysql --default-character-set=utf8mb4 -u root -p trang_thu -e 'SOURCE database/seeds/001_genres.sql'
mysql --default-character-set=utf8mb4 -u root -p trang_thu -e 'SOURCE database/seeds/002_demo_catalog.sql'
```

5. Chạy PHP từ thư mục gốc:

```powershell
php -S 127.0.0.1:8000 -t public router.php
```

Mở `http://127.0.0.1:8000`. Với Apache, đặt document root vào thư mục `public`; không đặt document root ở thư mục gốc repository.

Lưu ý: PHP đi kèm VertrigoServ trên máy phát triển hiện là 7.1, thấp hơn yêu cầu PHP 8.2 của ứng dụng. Cần cấu hình PHP 8.2 làm CLI/FastCGI cho site local hoặc nâng cấp runtime trước khi chạy ứng dụng trong Apache của stack đó. PHP CLI 8.2.12 có sẵn tại `C:\xampp\php\php.exe`; lệnh phục vụ ở trên nên chạy bằng executable phù hợp.

6. Chạy dịch vụ realtime ở terminal riêng (chưa cần npm install):

```powershell
$settings = Get-Content .env | Where-Object { $_ -match '^[A-Z_]+=' } | ConvertFrom-StringData
$env:APP_KEY = $settings.APP_KEY
$env:REALTIME_SERVICE_TOKEN = $settings.REALTIME_SERVICE_TOKEN
$env:REALTIME_ALLOWED_ORIGINS = $settings.REALTIME_ALLOWED_ORIGINS
npm --prefix node-realtime start
```

`REALTIME_URL` phải là địa chỉ Node.js mà trình duyệt truy cập được; `REALTIME_ALLOWED_ORIGINS` phải liệt kê origin của giao diện, ngăn SSE từ origin ngoài. Worker hiện xử lý một mục mỗi lần gọi; production cần supervisor lập lịch lặp và giám sát retry.

Để xử lý hàng đợi nhập truyện trong môi trường phát triển, chạy worker từ thư mục gốc sau khi tải tệp lên:

```powershell
php app/workers/import-worker.php
```

Mốc chia chương mặc định 7.000 từ là giá trị vận hành ban đầu, không phải giới hạn sản phẩm cố định; có thể điều chỉnh bằng `IMPORT_CHAPTER_WORD_LIMIT` trong `.env`.

Trong production, chạy worker bằng một process supervisor/queue runner riêng; không thực thi bộ phân tích tệp đồng bộ trong request web.

Thông báo được ghi vào MySQL cùng giao dịch với thao tác thành viên/kiểm duyệt; worker chuyển outbox sang Node.js để đẩy tới các kết nối SSE. Chạy một worker thông báo riêng:

```powershell
php app/workers/notification-outbox-worker.php
```

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
- Thông báo theo tài khoản được lưu MySQL, có API xem/đánh dấu đã đọc, outbox retry và SSE Node.js xác minh JWT; thay đổi từ PHP được phát qua service token riêng.
- UI kết nối danh mục, danh sách mới cập nhật/xếp hạng và phân trang chương từ PHP khi API khả dụng; khi chưa cấu hình backend, phần nội dung còn lại chỉ là dữ liệu minh họa. Reader preferences vẫn lưu local trên trình duyệt.

Nội dung seed là truyện mẫu hư cấu, không phải thư viện nội dung hoàn chỉnh. Quản trị viên đầu tiên phải được cấp quyền bằng một quy trình vận hành tin cậy; không có tài khoản quản trị mặc định trong seed. Tiến trình migration tự động, triển khai storage cloud và cấu hình production còn là các mốc phát triển tiếp theo. Nhà cung cấp hosting/realtime production chưa được chọn nên chưa có cấu hình triển khai gắn với một nhà cung cấp.

Để cấp quyền quản trị ban đầu cho một tài khoản đã đăng ký, hãy xác minh danh tính qua kênh vận hành tin cậy rồi chạy lệnh SQL đặc quyền tương ứng, thay email bằng tài khoản thực tế:

```sql
UPDATE users SET role = 'admin' WHERE email = 'verified-admin@example.com' AND status = 'active';
```

## Kiểm tra

```powershell
npm --prefix node-realtime test
php tests/run.php
```

Để chạy kiểm thử tích hợp luồng chương trên một database riêng đã áp migrations `001`–`007` và seed thể loại, cấu hình các biến `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_DATABASE` (tên chỉ gồm chữ/số/dấu gạch dưới và bắt buộc kết thúc bằng `_test`), `TEST_DB_USERNAME`, `TEST_DB_PASSWORD`, sau đó chạy:

```powershell
php tests/chapter-workflow.php
```
