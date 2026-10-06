# Changelog

## 1.0.8 — 2026-10-06
- **Token có hạn + tự làm mới** (bắt chước Shopify): khi kết nối, dịch vụ phát thêm **cặp**
  `access_token` (ngắn) + `refresh_token` (dài). Plugin **đọc `expires_in` từ phản hồi** (KHÔNG gõ cứng),
  lưu cả cặp, và **làm mới trong NỀN** bằng cron WordPress (`vegax_ais_cron_refresh`, lịch 15 phút) —
  KHÔNG bao giờ gọi lúc khách đang xem ⇒ không làm chậm trang. Phía office: `vegax_woo.py` thêm
  `/api/woo/token/refresh` (refresh token **dùng-1-lần**, xoay mỗi lần, so `hmac.compare_digest`, buộc theo
  host) + khoá theo store chống race acquire/refresh. **Hỏng cặp ⇒ xử lý mềm**: `Vegax_AIS_Cloud::post`
  ngừng gọi mạng (`vegax_token_expired`), màn admin hiện thông báo + nút **Reconnect with Vegax**;
  **KHÔNG làm sập trang khách**.
- **Tương thích ngược**: store đang nối bằng **khoá tĩnh** (`store_token`/`store_secret`) chạy y như cũ —
  không có `refresh_token` ⇒ mọi nhánh token mới thành no-op. Ghi rõ trong readme.txt + CHANGELOG.
- **Test mới**: `tests/TokenTest.php` (đọc `expires_in`; hết hạn ⇒ tự làm mới; HTTP lỗi/transport lỗi ⇒
  xử lý mềm; store tĩnh không gọi mạng; khoá single-flight chống làm mới trùng; cổng soft-fail của Cloud).
- **Consent chuyển sang opt-in**: `consent_sync` mặc định `1 → 0` (`class-vegax-ais-settings.php`). Cài mới **không gửi**
  gì cho tới khi chủ shop tự tick ô "Data sharing" — đúng luật WordPress.org #7. (Ép ở lớp gửi vẫn giữ:
  `Vegax_AIS_Cloud::post` trả `vegax_no_consent` khi chưa đồng ý.)
- **Thống nhất 1 tên `vegax-support`**: đổi thư mục plugin `vegax-ai-support/ → vegax-support/`, file chính
  `vegax-support.php`, **text-domain** mọi chuỗi dịch, `data-plugin="vegax-support/<ver>"` ở widget, key REST
  `plugin`, `X-Vegax-Plugin`, build script + readme + repo. Grep tên cũ: **0**.
- Đổi `User-Agent` gửi cloud `VegaxAI-Support/… → Vegax-Support/…`; docblock `@package Vegax_Support`.
- readme.txt: thêm mục Changelog/Upgrade Notice 1.0.8; sửa câu "External services" nêu rõ cần tick consent mới gửi.
- Giữ nguyên (định danh kỹ thuật nội bộ, KHÔNG hiển thị): tiền tố class/hàm `Vegax_AIS_*` / `vegax_ais_*`,
  tên file `class-vegax-ais-*.php`, và **option key `vegax_ais_settings`/`vegax_ais_status`** (đổi sẽ mồ côi cấu hình
  đã lưu của store đang chạy — rủi ro, chưa đổi).
- **Chống gửi nhầm người do cache** (bài học Intercom): `Vegax_AIS_Widget::maybe_disable_cache()` — khi *Identity
  verification* BẬT và có khách đăng nhập, response được đánh `DONOTCACHEPAGE` + `nocache_headers()` + `Vary: Cookie`
  TRƯỚC khi in, nên cache trang/CDN **không thể** lưu HTML mang token rồi phục vụ cho khách khác. Khách ẩn danh
  (không token) vẫn cache bình thường ⇒ không giảm hiệu năng.
- **Gom lọc/escape về 1 chỗ**: `includes/class-vegax-ais-safe.php` (`Vegax_AIS_Safe::post_*` đầu vào,
  `::text/attr/url/textarea` đầu ra) — dùng ở admin + widget; hành vi khách thấy không đổi.
- **Điểm mở rộng**: `vegax_ais_widget_tag_attributes`, `vegax_ais_knowledge_product`, `vegax_ais_heartbeat_payload`,
  `vegax_ais_order_event_payload` + mục "Developers" trong README.
- **Bộ kiểm tự động**: `tests/` (PHPUnit) + `composer.json` + `phpcs.xml.dist` + `.github/workflows/ci.yml`
  (php -l nhiều phiên bản PHP + PHPUnit + PHPCS). Không nằm trong zip phát hành.
- `Vegax_AIS_Identity::verify()` (dùng thật trong widget — in token nào phải tự kiểm được token đó, nếu không thì bỏ).

## 1.0.7 — 2026-10-05
- **readme.txt viết lại thành listing WordPress.org đầy đủ**: thêm mục `== External services ==` (khai báo dịch vụ
  ngoài + dữ liệu gửi đi), mục Free trial/plans, Screenshots, Upgrade Notice.
- **Bỏ chữ "AI" khỏi mọi văn bản người dùng thấy** (header Description, readme, tag) — tên hiển thị giữ **Vegax Support**.
- **Thêm ô consent tường minh** ("Data sharing") trên màn hình Settings và **ép ở lớp gửi** (`Vegax_AIS_Cloud::post`
  trả lỗi khi chưa đồng ý) ⇒ đúng luật #7 (không gửi dữ liệu khi chưa được đồng ý). Mặc định = 1 để store đang chạy
  không bị ngắt.
- **Thêm khối "Connect to Vegax"** trên màn hình Settings: nút dẫn trang đăng ký + ô dán mã ghép nối một lần
  (dùng handler pairing sẵn có, trước đây không có giao diện).
- **Sửa HTML lồng thẻ sai** trong bảng Settings (thiếu `</tr>`, dư `</tr>`).
- Thêm file **LICENSE** (GPL-2.0 nguyên văn) + **assets** (icon.svg, icon-128/256, banner-772x250/1544x500) vào gói.
- build.sh: chặn `.bak*` / file ẩn lọt vào zip.

## 1.0.6 — 2026-10-03
- Đổi thương hiệu hiển thị sang **Vegax Support** ở header plugin, menu/h1 admin, notice, readme.
- **Gỡ chuỗi thương hiệu cũ** (`Vegax AI S*`) khỏi gói: giá trị mặc định cũ được dựng trong mã để migrate store cài cũ, KHÔNG còn là literal trong gói.
- Đồng bộ số phiên bản ở header, hằng số `VEGAX_AIS_VERSION`, readme `Stable tag`.
- Gói đóng SẠCH: không kèm file `.bak`, không `__MACOSX`/`.DS_Store`, đúng cấu trúc WordPress (thư mục gốc `vegax-support/`).
- Bề mặt KHÁCH MUA HÀNG vẫn trung tính: plugin chỉ in `<script data-agent>`; tiêu đề hiển thị do `widget.js` phục vụ = 'Vegax Support'.

## 1.0.0 — 2026-09-30
- Bản đầu tiên. Adapter WooCommerce cho Vegax AI Support:
  - bơm widget chat vào storefront qua `wp_footer` (không sửa theme);
  - đồng bộ tri thức có chữ ký, **giữ nguyên** `brand_color`/`custom_rules` của chủ shop;
  - heartbeat báo cloud (WP/Woo version, HPOS);
  - **tra đơn (WISMO)**: cloud gọi `order-lookup` có chữ ký → AI trả trạng thái/tổng tiền/tracking thật;
  - đẩy sự kiện khi đơn đổi trạng thái.
- Tương thích HPOS (khai báo `custom_order_tables`), vẫn chạy khi HPOS tắt.
- Bảo mật:
  - rate limit key **theo STORE** (240/giờ), KHÔNG key theo IP — mọi lookup tới từ một IP cloud,
    key theo IP sẽ chặn chéo mọi store;
  - HMAC 2 chiều, cửa sổ 300 s;
  - chống dò đơn: chỉ trả đơn khi email người hỏi đúng là chủ đơn;
  - bản tóm tắt đơn không chứa địa chỉ/số điện thoại/email.
