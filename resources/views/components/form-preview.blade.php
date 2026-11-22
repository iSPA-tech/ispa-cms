<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Xem trước biểu mẫu</title>
    @vite(['resources/css/app.css', config('ispa-cms.template_tailwind_css')])
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script defer src="https://ispa.io/docs/assets/all-icons.min.js"></script>
    <script defer src="{{ asset('js/alpine.min.js') }}"></script>
</head>
<body class="bg-white p-4 text-gray-900">
    @include('components.form-generate-reviewed', [
        'fields' => $fields,
        'form' => $form,
        'actionUrl' => $actionUrl,
        'submissionToken' => $submissionToken,
        'previewOnly' => $previewOnly,
    ])
    @stack('script')
</body>
</html>
