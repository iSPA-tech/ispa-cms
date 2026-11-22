<?php

declare(strict_types=1);

/*
 * retrieval evaluation set (pilot Task 6, step 4). Synthetic posts and 31 labeled queries for the real
 * PostRepository::searchAgentEligible(); no production content.
 *
 * posts: key => [title, published_at (Asia/Ho_Chi_Minh), flags, body?]. Flags: unapproved, draft, future, deleted, page.
 * The body defaults to the title.
 * queries: label => [query, class, relevant keys, ordered]. `ordered` means the relevant keys must lead the result in
 * that order. added the `natural` class: questions as a reader asks them, matched against title, description
 * and body.
 */

return [
    'posts' => [
        'abs_exact' => ['Phanh ABS', '2026-08-20 08:00:00', [], 'Phanh ABS giúp bánh xe không bị bó cứng khi phanh gấp.'],
        'abs_how' => ['Phanh ABS hoạt động thế nào', '2026-09-01 08:00:00', [], 'Cảm biến đo tốc độ bánh xe, van điều áp nhả phanh liên tục.'],
        'abs_system' => ['Hệ thống phanh ABS trên xe máy', '2026-09-04 08:00:00', [], 'Xe máy có ABS thường gắn cảm biến ở cả hai bánh.'],
        'ebd' => ['EBD phân phối lực phanh điện tử', '2026-08-25 08:00:00', [], 'EBD chia lực phanh giữa bánh trước và bánh sau theo tải.'],
        'esp' => ['Cân bằng điện tử ESP là gì', '2026-08-26 08:00:00', [], 'ESP giảm công suất động cơ khi xe bị trượt ngang.'],
        'tyre_pressure' => ['Áp suất lốp xe máy bao nhiêu là đủ', '2026-08-27 08:00:00', [], 'Lốp trước nên bơm 29 psi, lốp sau 33 psi khi chở một người.'],
        'oil' => ['Thay dầu nhớt định kỳ cho xe tay ga', '2026-08-28 08:00:00', [], 'Nên thay dầu nhớt sau mỗi 1.500 đến 2.000 km.'],
        'headlight' => ['Đèn pha LED có tốt hơn đèn halogen', '2026-08-29 08:00:00', [], 'Đèn LED sáng hơn, tốn ít điện hơn và bền hơn bóng halogen.'],
        'fuel' => ['Tiết kiệm xăng khi đi đường dài', '2026-08-30 08:00:00', [], 'Giữ tốc độ đều khoảng 50 km/h và bơm lốp đủ áp suất giúp đỡ hao xăng.'],
        'spark_plug' => ['Bugi xe máy bao lâu thì thay', '2026-08-31 08:00:00', [], 'Bugi nên được thay sau khoảng 8.000 đến 10.000 km.'],
        'abs_unapproved' => ['Phanh ABS chưa được duyệt', '2026-09-02 08:00:00', ['unapproved']],
        'abs_draft' => ['Phanh ABS bản nháp', '2026-09-02 08:00:00', ['draft']],
        'abs_future' => ['Phanh ABS sắp đăng', '2026-09-07 08:00:00', ['future']],
        'abs_deleted' => ['Phanh ABS đã xoá', '2026-09-02 08:00:00', ['deleted']],
        'abs_page' => ['Phanh ABS trang tĩnh', '2026-09-02 08:00:00', ['page']],
    ],
    'forbidden' => ['abs_unapproved', 'abs_draft', 'abs_future', 'abs_deleted', 'abs_page'],
    'queries' => [
        'exact: phanh ABS (relevance then newest)' => ['phanh ABS', 'exact', ['abs_system', 'abs_how', 'abs_exact'], true],
        'exact: EBD' => ['EBD', 'exact', ['ebd'], false],
        'exact: áp suất lốp' => ['Áp suất lốp', 'exact', ['tyre_pressure'], false],
        'exact: thay dầu nhớt' => ['thay dầu nhớt', 'exact', ['oil'], false],
        'exact: đèn pha LED' => ['đèn pha LED', 'exact', ['headlight'], false],
        'exact: tiết kiệm xăng' => ['tiết kiệm xăng', 'exact', ['fuel'], false],
        'exact: bugi xe máy' => ['bugi xe máy', 'exact', ['spark_plug'], false],
        'folded: ap suat lop' => ['ap suat lop', 'folded', ['tyre_pressure'], false],
        'folded: thay dau nhot' => ['thay dau nhot', 'folded', ['oil'], false],
        'folded: den pha' => ['den pha', 'folded', ['headlight'], false],
        'folded: can bang dien tu' => ['can bang dien tu', 'folded', ['esp'], false],
        'folded: tiet kiem xang' => ['tiet kiem xang', 'folded', ['fuel'], false],
        'folded: he thong phanh' => ['he thong phanh', 'folded', ['abs_system'], false],
        'punctuation: EBD???' => ['EBD???', 'punctuation', ['ebd'], false],
        'punctuation: !!! bugi ...' => ['!!! bugi ...', 'punctuation', ['spark_plug'], false],
        'punctuation only' => ['?!... —', 'empty', [], true],
        'whitespace only' => ["  \t ", 'empty', [], true],
        'typo: phanhh ABS' => ['phanhh ABS', 'typo', ['abs_system', 'abs_how', 'abs_exact'], false],
        'typo: bugii' => ['bugii', 'typo', ['spark_plug'], false],
        'ranking: phanh (four approved matches, newest first within one tier)' => ['phanh', 'ranking', ['abs_system', 'abs_how', 'ebd', 'abs_exact'], true],
        'natural: thay dầu nhớt sau bao nhiêu km' => ['Thay dầu nhớt sau bao nhiêu km?', 'natural', ['oil'], false],
        'natural: bao lâu thì phải thay bugi' => ['Bao lâu thì phải thay bugi?', 'natural', ['spark_plug'], false],
        'natural: lốp trước bơm bao nhiêu psi' => ['Lốp trước nên bơm bao nhiêu psi?', 'natural', ['tyre_pressure'], false],
        'natural: ABS hoạt động như thế nào' => ['ABS hoạt động như thế nào?', 'natural', ['abs_how'], false],
        'natural: xe máy có ABS thì cảm biến ở đâu' => ['Xe máy có ABS thì có cảm biến ở đâu?', 'natural', ['abs_system'], false],
        'natural: đèn LED có sáng hơn halogen không' => ['Đèn LED có sáng hơn halogen không?', 'natural', ['headlight'], false],
        'natural: làm sao tiết kiệm xăng khi đi xa' => ['Làm sao tiết kiệm xăng khi đi xa?', 'natural', ['fuel'], false],
        'natural: EBD chia lực phanh thế nào' => ['EBD chia lực phanh thế nào?', 'natural', ['ebd'], false],
        'natural: ESP làm gì khi xe bị trượt' => ['ESP làm gì khi xe bị trượt?', 'natural', ['esp'], false],
        'natural: phanh gấp bánh xe có bị bó cứng không' => ['Phanh gấp thì bánh xe có bị bó cứng không?', 'natural', ['abs_exact'], false],
        'natural (no diacritics): thay nhot sau bao nhieu km' => ['thay nhot sau bao nhieu km', 'natural', ['oil'], false],
    ],
];
