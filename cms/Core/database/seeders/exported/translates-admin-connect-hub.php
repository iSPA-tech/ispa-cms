<?php

/**
 * Admin translations for the "Connect to iSPA Hub" screen (since 2026-11-19 opened from Modules after sign-in; it
 * shows before sign-in only when the site backend does not answer) and the site connect and site status messages
 *. The rows have no `id`: an id only links a new name to an existing row, so a guessed one would
 * attach the key to an unrelated translation.
 *
 * @return array<int, array<string, mixed>>
 */

$entry = static function (string $name, string $en, string $vi, string $updatedAt = '2026-11-15 00:00:00'): array {
    return [
        'modules' => ['Core'],
        'group_name' => 'text',
        'name' => $name,
        'text' => [
            'en' => $en,
            'vi' => $vi,
        ],
        'for_web' => 0,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-11-15 00:00:00',
        'updated_at' => $updatedAt,
    ];
};

return [
    $entry('text.site_connect.title', 'Connect to iSPA Hub', 'Kết nối iSPA Hub'),
    $entry('text.site_connect.intro', 'This website is not connected to iSPA Hub yet. You only need to do this once: after that you can use the module store, paid modules and the iSPA services.', 'Website này chưa được nối với iSPA Hub. Bạn chỉ cần nối một lần: sau đó dùng được kho module, module trả phí và các dịch vụ iSPA.', '2026-11-19 12:00:00'),
    $entry('text.site_connect.portal_hint', 'No secret code yet? Create an account at ispa.tech, open "Websites & domains", choose "Connect a website", enter this domain and choose "Create website codes".', 'Chưa có mã bí mật? Tạo tài khoản tại ispa.tech, vào "Website & tên miền", chọn "Kết nối website", nhập tên miền này rồi bấm "Tạo mã cho website".', '2026-11-19 23:00:00'),
    $entry('text.site_connect.portal_link', 'Open the iSPA customer portal', 'Mở cổng khách hàng iSPA'),
    $entry('text.site_connect.site_id', 'Website address', 'Địa chỉ website'),
    $entry('text.site_connect.site_id_help', 'The domain registered in the portal, for example example.com', 'Tên miền đã đăng ký trên cổng, ví dụ example.com'),
    $entry('text.site_connect.site_secret', 'Secret code', 'Mã bí mật', '2026-11-19 23:00:00'),
    $entry('text.site_connect.site_secret_help', 'The portal shows this code only once, right after "Create website codes". Lost it? Create a new one there.', 'Cổng chỉ hiện mã này một lần, ngay sau khi bấm "Tạo mã cho website". Mất mã thì tạo mã mới trên cổng.', '2026-11-19 23:00:00'),
    $entry('text.site_connect.setup_code', 'Setup code', 'Mã cài đặt'),
    $entry('text.site_connect.setup_code_help', 'A code of 8 groups of characters, printed when the website was installed. If it is lost, ask whoever installed the website for a new one.', 'Mã gồm 8 nhóm ký tự, được in ra khi cài đặt website. Mất mã thì nhờ người cài đặt tạo mã mới.'),
    $entry('text.site_connect.required', 'Please fill in {field}.', 'Hãy điền {field}.'),
    $entry('text.site_connect.submit', 'Connect', 'Kết nối'),
    $entry('text.site_connect.submit_again', 'Try to connect again', 'Thử kết nối lại'),
    $entry('text.site_connect.submitting', 'Connecting…', 'Đang kết nối…'),
    $entry('text.site_connect.pending_title', 'Waiting for iSPA Hub to check the domain', 'Đang chờ iSPA Hub kiểm tra tên miền'),
    $entry('text.site_connect.pending_text', 'iSPA Hub has received the code and is checking that this website really is yours. This usually takes a few minutes.', 'iSPA Hub đã nhận mã và đang kiểm tra website này có đúng là của bạn không. Việc này thường mất vài phút.', '2026-11-19 23:00:00'),
    $entry('text.site_connect.recheck', 'Check again', 'Kiểm tra lại'),
    $entry('text.site_connect.unavailable_title', 'Could not check the iSPA Hub connection', 'Chưa kiểm tra được kết nối iSPA Hub'),
    $entry('text.site_connect.unavailable_text', 'The website did not answer. Please try again in a few minutes.', 'Website chưa trả lời. Hãy thử lại sau ít phút.'),
    $entry('text.site_connect.retry', 'Try again', 'Thử lại'),
    $entry('text.site_connect.connected', 'Connected to iSPA Hub.', 'Đã kết nối iSPA Hub.'),
    $entry('text.site_connect.pending', 'iSPA Hub could not confirm the website yet. Please try again in a few minutes.', 'iSPA Hub chưa xác nhận được website. Hãy thử lại sau ít phút.'),
    $entry('text.site_connect.invalid_input', 'Please fill in the website address, the secret code and the setup code.', 'Hãy điền đủ địa chỉ website, mã bí mật và mã cài đặt.', '2026-11-19 23:00:00'),
    $entry('text.site_connect.setup_code_invalid', 'The setup code is wrong or has expired. Check it, or ask whoever installed the website for a new one.', 'Mã cài đặt không đúng hoặc đã hết hạn. Kiểm tra lại mã, hoặc nhờ người cài đặt tạo mã mới.'),
    $entry('text.site_connect.credentials_refused', 'iSPA Hub does not accept this website address or secret code. Check what you copied from the portal.', 'iSPA Hub không nhận địa chỉ website hoặc mã bí mật này. Kiểm tra lại thông tin sao chép từ cổng.', '2026-11-19 23:00:00'),
    $entry('text.site_connect.hub_unreachable', 'Could not reach iSPA Hub. Please try again in a few minutes.', 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút.'),
    $entry('text.site_connect.managed_by_env', 'This website already has its code in the server settings, so it cannot be changed here. Please contact the server administrator.', 'Mã của website này đã được cài sẵn trong cấu hình máy chủ nên không đổi được ở đây. Hãy liên hệ người quản trị máy chủ.', '2026-11-19 23:00:00'),
    $entry('text.site_connect.too_many_attempts_here', 'Too many attempts. Wait a minute and try again.', 'Bạn thử quá nhiều lần. Hãy đợi một phút rồi thử lại.'),
    $entry('text.site_connect.unavailable', 'The website did not answer. Please try again.', 'Website chưa trả lời. Hãy thử lại.'),
    $entry('text.site_connect.reason.not_resolved', 'iSPA Hub could not find this website address. Check that the domain points to the server.', 'iSPA Hub không tìm thấy địa chỉ website này. Kiểm tra tên miền đã trỏ về máy chủ chưa.'),
    $entry('text.site_connect.reason.private_address', 'The website address points to an internal network, so iSPA Hub cannot check it.', 'Địa chỉ website đang trỏ về mạng nội bộ nên iSPA Hub không kiểm tra được.'),
    $entry('text.site_connect.reason.unreachable', 'iSPA Hub could not open the website over HTTPS. Check that it is running and has an HTTPS certificate.', 'iSPA Hub chưa mở được website qua HTTPS. Kiểm tra website đã chạy và có chứng chỉ HTTPS.'),
    $entry('text.site_connect.reason.redirect', 'The website redirects to another address, so iSPA Hub cannot check it.', 'Website chuyển hướng sang địa chỉ khác nên iSPA Hub không kiểm tra được.'),
    $entry('text.site_connect.reason.no_answer', 'The website did not answer the iSPA Hub check.', 'Website không trả lời phép kiểm tra của iSPA Hub.'),
    $entry('text.site_connect.reason.wrong_answer', 'The secret code does not match this website. Check the code.', 'Mã bí mật không khớp với website này. Kiểm tra lại mã.', '2026-11-19 23:00:00'),
    $entry('text.site_connect.reason.secret_expired', 'The secret code waited too long to be connected. Create a new code in the portal.', 'Mã bí mật đã hết thời gian chờ kết nối. Hãy tạo mã mới trên cổng.', '2026-11-19 23:00:00'),
    $entry('text.site_connect.reason.too_many_attempts', 'Too many checks in one hour. Please try again later.', 'Đã kiểm tra quá nhiều lần trong một giờ. Hãy thử lại sau.'),
    $entry('text.site_connect.reason.managed', 'This website is managed by iSPA. Please contact support.', 'Website này do iSPA quản lý. Hãy liên hệ bộ phận hỗ trợ.'),
    $entry('text.site_status.not_connected', 'iSPA Hub does not accept this website. Connect again with new details from the portal.', 'iSPA Hub không nhận website này. Hãy kết nối lại bằng thông tin mới từ cổng.'),
    $entry('text.site_status.hub_unreachable', 'Could not reach iSPA Hub. Please try again in a few minutes.', 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút.'),
    $entry('text.site_status.connected', 'This website is connected to iSPA Hub.', 'Website này đã được kết nối với iSPA Hub.'),
    $entry('text.site_status.pending', 'iSPA Hub is checking the domain of this website.', 'iSPA Hub đang kiểm tra tên miền của website này.'),
    $entry('text.site_status.not_configured', 'This website is not connected to iSPA Hub yet.', 'Website này chưa được kết nối với iSPA Hub.'),
    $entry('text.site_status.hub_unreachable_grace', 'Could not reach iSPA Hub right now. The website keeps working with its last known connection.', 'Chưa nối được tới iSPA Hub lúc này. Website vẫn hoạt động theo kết nối gần nhất.'),
];
