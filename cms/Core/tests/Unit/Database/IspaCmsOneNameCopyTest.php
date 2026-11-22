<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

/*
 * "One iSPA CMS": the platform is called iSPA CMS on every
 * admin screen, so no Vietnamese text a person can see says "lõi" (it reads as the cms/Core folder), and the approved
 * wording is in place on the existing keys plus the four new ones.
 */

/** @return array<string, array{en: string, vi: string}> Core admin sidecar texts by key. */
function oneNameRows(): array
{
    $texts = [];
    foreach (require base_path('cms/Core/database/seeders/sql/translate-admin-authored.php') as $row) {
        $texts[(string) $row['name']] = ['en' => (string) $row['text']['en'], 'vi' => (string) $row['text']['vi']];
    }

    return $texts;
}

test('no Vietnamese text in the Core admin sidecar says "lõi"', function () {
    // Keys whose Vietnamese text is never shown to a person may be listed here, each with its reason. None today.
    $notUserFacing = [];

    $hits = [];
    foreach (oneNameRows() as $name => $text) {
        if (! in_array($name, $notUserFacing, true) && mb_stripos($text['vi'], 'lõi') !== false) {
            $hits[] = $name;
        }
    }

    expect($hits)->toBe([]);
});

test('the approved iSPA CMS wording is on the existing keys and the new keys', function () {
    $rows = oneNameRows();
    $expected = [
        'text.modules.core_update.title' => ['iSPA CMS', 'iSPA CMS'],
        'text.modules.core_update.up_to_date' => ['Up to date', 'Đang dùng bản mới nhất'],
        'text.modules.core_update.running' => ['iSPA CMS is being updated. Keep this page open or come back later.', 'Đang cập nhật iSPA CMS. Bạn có thể giữ trang này hoặc quay lại sau.'],
        'text.modules.core_update.blocked' => ['iSPA CMS cannot be updated from here yet:', 'Chưa thể cập nhật iSPA CMS từ trang này:'],
        'text.modules.core_update.load_failed' => ['Could not read the iSPA CMS update status. Try again.', 'Không đọc được tình trạng cập nhật iSPA CMS. Hãy thử lại.'],
        'text.modules.core_update.confirm.title' => ['Update iSPA CMS to {version}', 'Cập nhật iSPA CMS lên {version}'],
        'text.modules.core_update.issue.CORE_MODIFIED' => ['Some iSPA CMS files were changed on this site. Move your changes into your own project or module, or update from the command line.', 'Một số tệp của iSPA CMS đã bị sửa trên website này. Hãy chuyển phần sửa vào dự án hoặc module riêng, hoặc cập nhật bằng dòng lệnh.'],
        'text.modules.core_update.issue.CORE_CUSTOM_DEPS_CONFLICT' => ['This site\'s own PHP packages do not fit the new iSPA CMS version. Update from the command line to see what conflicts.', 'Gói PHP riêng của website không khớp với bản iSPA CMS mới. Hãy cập nhật bằng dòng lệnh để xem chỗ xung đột.'],
        'text.modules.core_update.issue.CORE_NOT_WRITABLE' => ['The server is not allowed to write some iSPA CMS folders. Fix the folder permissions or update from the command line.', 'Máy chủ chưa được ghi vào một số thư mục của iSPA CMS. Hãy sửa quyền thư mục hoặc cập nhật bằng dòng lệnh.'],
        'text.modules.core_update.issue.CORE_PHP' => ['The PHP version on the server is too old for the new iSPA CMS version.', 'Phiên bản PHP trên máy chủ quá cũ so với bản iSPA CMS mới.'],
        'text.modules.core_update.issue.CORE_NO_RUNNER' => ['This server has no background runner that can update iSPA CMS yet.', 'Máy chủ chưa có tiến trình nền để cập nhật iSPA CMS.'],
        'text.modules.core_update.restore_confirm.title' => ['Put the previous iSPA CMS files back?', 'Khôi phục tệp iSPA CMS bản trước?'],
        'text.modules.core_update.result.updated' => ['iSPA CMS was updated to {version}.', 'Đã cập nhật iSPA CMS lên bản {version}.'],
        'text.modules.core_update.result.restored' => ['The previous iSPA CMS files were put back.', 'Đã khôi phục tệp iSPA CMS bản trước.'],
        'text.background_tasks.type.core_upgrade' => ['iSPA CMS update', 'Cập nhật iSPA CMS'],
        'text.modules.core_locked' => ['Always on', 'Luôn bật'],
        'nav.module.core.name' => ['Platform', 'Nền tảng'],
        'text.agentic.module.core' => ['Platform', 'Nền tảng'],
        'text.modules.support.env.core_version' => ['iSPA CMS version', 'Phiên bản iSPA CMS'],
        'text.modules.error.core_protected' => ['This module ships with iSPA CMS and cannot be uninstalled.', 'Module này đi kèm iSPA CMS nên không thể gỡ.'],
        'text.modules.error.table_not_owned' => ['These tables belong to another module or to iSPA CMS itself and cannot be dropped: {tables}. The module is not removed.', 'Các bảng này thuộc module khác hoặc chính iSPA CMS nên không thể xoá: {tables}. Module chưa được gỡ.'],
        'text.acc.profile-overview-desc' => ['Review the main identity and access details of this account.', 'Xem lại thông tin danh tính và quyền truy cập chính của tài khoản này.'],
        'text.modules.core_update.newer' => ['New version {version}', 'Có bản mới {version}'],
        'text.modules.core_update.managed' => ['iSPA keeps this website up to date.', 'Website này do iSPA cập nhật.'],
        'text.modules.core_row_note' => ['Updated together with iSPA CMS.', 'Được cập nhật cùng iSPA CMS.'],
        'text.modules.core_update.needs_module_update' => ['Update these modules first: {modules}', 'Hãy cập nhật các module này trước: {modules}'],
    ];

    foreach ($expected as $name => [$en, $vi]) {
        expect($rows[$name] ?? null)->toBe(['en' => $en, 'vi' => $vi], $name);
    }
    // Kept as keys although the card no longer shows them.
    expect($rows)->toHaveKeys(['text.modules.core_update.installed', 'text.modules.core_update.latest', 'text.modules.core_update.latest_unknown']);
});
