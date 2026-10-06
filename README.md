# vegax-support — Vegax AI Support cho WooCommerce

Plugin WordPress/WooCommerce kết nối store vào cloud Vegax (`https://office.vegaxclaw.com`).
Đây là **adapter WooCommerce** của Vegax AI Support: bơm widget chat, đồng bộ tri thức có chữ ký,
và mở đường **tra đơn (WISMO)** để AI trả lời đúng trạng thái đơn thật của khách.

**Version:** 1.0.0 · **License:** GPL-2.0-or-later · **PHP:** 7.4+ · **WP:** 6.0+ · **Woo:** 7.0+ · HPOS compatible

## 1. Plugin làm gì

| Khả năng | Chi tiết |
|---|---|
| Bơm widget chat | chèn `widget.js` vào storefront qua `wp_footer` (không sửa theme) |
| Đồng bộ tri thức | `POST /api/woo/knowledge` — sản phẩm + policy, **giữ nguyên** `brand_color`/`custom_rules` do chủ shop đặt |
| Heartbeat | `POST /api/woo/heartbeat` — báo cloud store còn sống, kèm phiên bản WP/Woo/HPOS |
| Tra đơn (WISMO) | cloud gọi `GET /wp-json/vegax/v1/order-lookup` (có chữ ký) → AI trả trạng thái/tổng tiền/tracking thật |
| Sự kiện đơn | `POST /api/woo/order-event` khi đơn đổi trạng thái |

## 2. Cài đặt

1. WP Admin → **Plugins → Add New → Upload Plugin** → chọn `vegax-support-<version>.zip` → **Install** → **Activate**.
2. Vào **Settings → Vegax AI Support**.
3. Điền: `store_token`, `store_secret`, `api_base` (mặc định `https://office.vegaxclaw.com`).
4. Bấm kiểm tra kết nối → phải thấy `connected`, đúng Woo/WP version, số sản phẩm.
5. Widget xuất hiện trên storefront (kiểm bằng mắt + `curl` tìm tag `widget.js`).

`store_id()` tự lấy host của `home_url()`; đặt `store_id_override` khi store chạy sau CDN/proxy/staging
hoặc khi WP constants ghi đè site URL.

## 3. Hợp đồng chữ ký (verified 2 chiều)

**Plugin → cloud**
```
POST /api/woo/{heartbeat,knowledge,order-event}
X-Vegax-Token:     <store_token>
X-Vegax-Timestamp: <unix ts>
X-Vegax-Signature: hex(hmac_sha256(ts + "." + raw_body, store_secret))
```

**Cloud → plugin**
```
GET /wp-json/vegax/v1/order-lookup?store=&email=&order=&ts=&sig=
sig = hex(hmac_sha256("order-lookup|store|email|order|ts", store_secret))
```
Cửa sổ lệch thời gian 300 s · `store` phải khớp site · 60 lookup/IP/giờ ·
**rate limit plugin: 240/giờ/STORE** (key trên `store_id()`, KHÔNG key trên IP — mọi lookup đều tới từ
một IP của cloud, key theo IP sẽ chặn chéo tất cả store).

REST routes: `/health`, `/order-lookup`, `/knowledge`.

## 4. Quyền riêng tư

- Bản tóm tắt đơn **KHÔNG** chứa địa chỉ, số điện thoại, email.
- Đơn chỉ được trả khi email khách cung cấp **đúng là chủ đơn** (chống dò đơn).
- `share_customer_email` (mặc định **tắt**) mới là thứ thêm email thô vào sự kiện đơn.

## 5. Cấu trúc

```
vegax-support/
  vegax-support.php          # bootstrap + constants + activation
  uninstall.php                 # dọn option khi gỡ
  readme.txt                    # readme kiểu WordPress.org
  includes/
    class-vegax-ais-settings.php   # 1 option: vegax_ais_settings
    class-vegax-ais-safe.php       # 1 chỗ lọc/escape dùng chung (Vegax_AIS_Safe)
    class-vegax-ais-admin.php      # trang cài đặt + nút kiểm tra kết nối
    class-vegax-ais-widget.php     # bơm widget.js (+ chặn cache cho phiên cá nhân hoá)
    class-vegax-ais-identity.php   # token danh tính người dùng cuối (HMAC + hạn)
    class-vegax-ais-cloud.php      # gọi cloud có chữ ký
    class-vegax-ais-rest.php       # REST: health / order-lookup / knowledge
    class-vegax-ais-orders.php     # đọc đơn (HPOS + legacy)
    class-vegax-ais-knowledge.php  # dựng payload tri thức
  build.sh                      # đóng gói zip + md5 (lặp lại được)
tests/                          # PHPUnit (không nằm trong zip phát hành)
composer.json · phpunit.xml.dist · phpcs.xml.dist
.github/workflows/ci.yml        # CI: php -l nhiều phiên bản + PHPUnit + PHPCS
```

## 6. Developers — điểm mở rộng (hooks)

Plugin chỉ thêm filter — **không** tự gọi nên hành vi mặc định không đổi. Thêm vào
`functions.php` của theme (hoặc một mu-plugin):

| Hook | Kiểu | Việc |
|---|---|---|
| `vegax_ais_widget_tag_attributes` | filter | `(array $thuộc_tính, string $store)` — đổi/thêm thuộc tính tag widget, ví dụ đổi nhãn nút: `$a['data-label'] = 'Hỏi đáp';` |
| `vegax_ais_knowledge_product` | filter | `(array $sản_phẩm, WC_Product $p)` — thêm/bớt trường gửi cho cloud theo từng sản phẩm |
| `vegax_ais_heartbeat_payload` | filter | `(array $payload)` — thêm trường vào heartbeat hằng ngày |
| `vegax_ais_order_event_payload` | filter | `(array $event, WC_Order $order)` — sửa payload sự kiện đổi trạng thái đơn |
| `vegax_ais_widget_should_render` · `vegax_ais_widget_on_checkout` | filter | ẩn/hiện widget theo điều kiện (đã có từ 1.0.6) |
| `vegax_ais_tracking_items` | filter | thêm nguồn mã vận đơn riêng |

Ví dụ: `add_filter( 'vegax_ais_widget_tag_attributes', function ( $a ) { $a['data-label'] = 'Chat với shop'; return $a; } );`

## 7. Build bản phát hành

```bash
./build.sh          # → dist/vegax-support-<version>.zip + .md5
```

**Quy tắc:** mọi thay đổi plugin phải vào repo này trước, rồi mới build zip để phát.
Đừng sửa trực tiếp file trong container WordPress — bản trong container không phải nguồn sự thật.

## 8. Bản quyền

GPL-2.0-or-later. Copyright (c) NCK Group LLC / Vegax.
