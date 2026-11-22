<?php

return array (
  0 => 
  (object) array(
     'id' => 1,
     'name' => 'Đơn hàng {order_code} vừa được khởi tạo',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'user_on_new_order',
     'content' => '<h1>Xin chào <b>{{customer_name}}</b>,</h1>
<p>Cảm ơn bạn đã đặt hàng tại <strong>{{store_name}}</strong>.</p>
<p>Đơn hàng <strong>#{{order_code}}</strong> của bạn đã được thanh toán thành công vào ngày {{payment_date}} bằng phương thức {{payment_method}}.</p>
<h3>Chi tiết đơn hàng:</h3>
<table border="1" cellpadding="6" cellspacing="2"><thead> <tr> <th>Sản phẩm</th> <th>Số lượng</th> <th>Đơn giá</th> <th>Thành tiền</th> </tr> </thead> <tbody> {{order_items}} </tbody></table>
<p><strong>Tổng cộng:</strong> {{order_total}}</p>
<p>Địa chỉ giao hàng: {{shipping_address}}</p>
<p>Chúng tôi sẽ thông báo cho bạn khi đơn hàng được xử lý và giao đến.</p><p>Trân trọng,<br>{{store_name}}</p>',
     'variables' => '[{"label": "Customer Name", "value": "customer_name"}, {"label": "Store Name", "value": "store_name"}, {"label": "Order Code", "value": "order_code"}, {"label": "Payment Date", "value": "payment_date"}, {"label": "Payment Method", "value": "payment_method"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "store_name": "Store Name", "order_items": "<tr><td>Cà phê Arabica</td><td>2</td><td>120.000đ</td><td>240.000đ</td></tr><tr><td>Bánh ngọt Pháp</td><td>1</td><td>80.000đ</td><td>80.000đ</td></tr>", "order_total": "320.000đ", "payment_date": "2025-08-01 00:00:00", "customer_name": "Nguyễn Văn A", "payment_method": "card", "shipping_address": "123 Tên đường, Phường, Tỉnh/Thành"}',
     'description' => 'Xin chào {{customer_name}},
Cảm ơn bạn đã đặt hàng tại {{store_name}}.
Đơn hàng #{{order_code}} của bạn đã được thanh toán thành công vào ngày {{payment_date}} bằn...',
     'language_id' => 1,
     'user_id' => NULL,
     'created_at' => '2025-08-24 09:00:03',
     'updated_at' => '2025-09-04 09:19:00',
     'deleted_at' => NULL,
  ),
  1 => 
  (object) array(
     'id' => 2,
     'name' => 'Thông báo: Có đơn hàng mới #{order_code} từ khách hàng {customer_name}',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'admin_on_new_order',
     'content' => '<h1>📢 Thông báo đơn hàng mới</h1>

<p>Xin chào quản trị viên,</p>
<p>Một đơn hàng mới vừa được tạo trên hệ thống <strong>{{store_name}}</strong>.</p>

<p><strong>Mã đơn hàng:</strong> #{{order_code}}</p>
<p><strong>Khách hàng:</strong> {{customer_name}}</p>
<p><strong>Email:</strong> {{customer_email}}</p>
<p><strong>Số điện thoại:</strong> {{customer_phone}}</p>
<p><strong>Ngày đặt hàng:</strong> {{order_date}}</p>
<p><strong>Phương thức thanh toán:</strong> {{payment_method}}</p>

<h3>Chi tiết đơn hàng:</h3>
<table border="1" cellpadding="6" cellspacing="2" width="100%">
 <thead>
 <tr>
 <th>Sản phẩm</th>
 <th>Số lượng</th>
 <th>Đơn giá</th>
 <th>Thành tiền</th>
 </tr>
 </thead>
 <tbody>
 {{order_items}}
 </tbody>
</table>

<p><strong>Tổng cộng:</strong> {{order_total}}</p>
<p><strong>Địa chỉ giao hàng:</strong> {{shipping_address}}</p>

<hr>
<p>Vui lòng đăng nhập vào hệ thống để xử lý đơn hàng.</p>
<p>Trân trọng,<br>{{store_name}}',
     'variables' => '[{"label": "Store Name", "value": "store_name"}, {"label": "Order Code", "value": "order_code"}, {"label": "Customer Name", "value": "customer_name"}, {"label": "Customer Email", "value": "customer_email"}, {"label": "Customer Phone", "value": "customer_phone"}, {"label": "Order Date", "value": "order_date"}, {"label": "Payment Method", "value": "payment_method"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "order_date": "2025-08-01 00:00:00", "store_name": "Store Name", "order_items": "<tr><td>Cà phê Robusta</td><td>3</td><td>90.000đ</td><td>270.000đ</td></tr><tr><td>Bánh mì bơ tỏi</td><td>2</td><td>50.000đ</td><td>100.000đ</td></tr>", "order_total": "370.000đ", "customer_name": "Nguyễn Văn A", "customer_email": "nguyen.van.a@example.com", "customer_phone": "0987654321", "payment_method": "card", "shipping_address": "53 D Street, Lakeview"}',
     'description' => '📢 Thông báo đơn hàng mới

Xin chào quản trị viên,
Một đơn hàng mới vừa được tạo trên hệ thống {{store_name}}.

Mã đơn hàng: #{{order_code}}
Khách hàng: {{c...',
     'language_id' => 1,
     'user_id' => NULL,
     'created_at' => '2025-08-25 13:58:42',
     'updated_at' => '2025-08-25 13:58:51',
     'deleted_at' => NULL,
  ),
  2 => 
  (object) array(
     'id' => 3,
     'name' => 'Đơn hàng {order_code} vừa được cập nhật trạng thái',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'user_on_status_change',
     'content' => '<h1>Xin chào <b>{{customer_name}}</b>,</h1>
<p>Đơn hàng <strong>#{{order_code}}</strong> của bạn tại <strong>{{store_name}}</strong> vừa được cập nhật trạng thái.</p><p><strong>Trạng thái mới:</strong> {{order_status}}</p><p><strong>Ngày cập nhật:</strong> {{updated_at}}</p><h3>Chi tiết đơn hàng:</h3><table border="1" cellpadding="6" cellspacing="2" width="100%"> <thead> <tr> <th>Sản phẩm</th> <th>Số lượng</th> <th>Đơn giá</th> <th>Thành tiền</th> </tr> </thead> <tbody> {{order_items}} </tbody></table><p><strong>Tổng cộng:</strong> {{order_total}}</p><p><strong>Địa chỉ giao hàng:</strong> {{shipping_address}}</p><p>Nếu bạn có bất kỳ thắc mắc nào, vui lòng liên hệ với chúng tôi để được hỗ trợ.</p><p>Trân trọng,<br>{{store_name}} Team</p>',
     'variables' => '[{"label": "Customer Name", "value": "customer_name"}, {"label": "Order Code", "value": "order_code"}, {"label": "Store Name", "value": "store_name"}, {"label": "Order Status", "value": "order_status"}, {"label": "Updated At", "value": "updated_at"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "store_name": "Store Name", "updated_at": "2025-08-01 01:00:00", "order_items": "<tr><td>Espresso</td><td>1</td><td>60.000đ</td><td>60.000đ</td></tr><tr><td>Cookie Socola</td><td>2</td><td>30.000đ</td><td>60.000đ</td></tr>", "order_total": "120.000đ", "order_status": "Đã thanh toán", "customer_name": "Nguyễn Văn A", "shipping_address": "53 D Street, Lakeview"}',
     'description' => 'Xin chào {{customer_name}},
Đơn hàng #{{order_code}} của bạn tại {{store_name}} vừa được cập nhật trạng thái.Trạng thái mới: {{order_status}}Ngày cập nhật: {{update...',
     'language_id' => 1,
     'user_id' => NULL,
     'created_at' => '2025-08-25 14:07:23',
     'updated_at' => '2025-08-25 14:07:23',
     'deleted_at' => NULL,
  ),
  3 => 
  (object) array(
     'id' => 4,
     'name' => 'Đơn hàng {order_code} vừa được cập nhật trạng thái {order_status}',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'admin_on_status_change',
     'content' => '<h1>📢 Cập nhật trạng thái đơn hàng</h1><p>Xin chào quản trị viên,</p><p>Đơn hàng <strong>#{{order_code}}</strong> trên hệ thống <strong>{{store_name}}</strong> vừa được cập nhật trạng thái.</p><p><strong>Khách hàng:</strong> {{customer_name}} ({{customer_email}}, {{customer_phone}})</p><p><strong>Trạng thái mới:</strong> {{order_status}}</p><p><strong>Ngày cập nhật:</strong> {{updated_at}}</p><h3>Chi tiết đơn hàng:</h3><table border="1" cellpadding="6" cellspacing="2" width="100%"> <thead> <tr> <th>Sản phẩm</th> <th>Số lượng</th> <th>Đơn giá</th> <th>Thành tiền</th> </tr> </thead> <tbody> {{order_items}} </tbody></table><p><strong>Tổng cộng:</strong> {{order_total}}</p><p><strong>Địa chỉ giao hàng:</strong> {{shipping_address}}</p><hr><p>Vui lòng đăng nhập vào hệ thống để theo dõi và xử lý đơn hàng.</p><p>Trân trọng,<br>{{store_name}} System</p>',
     'variables' => '[{"label": "Order Code", "value": "order_code"}, {"label": "Store Name", "value": "store_name"}, {"label": "Customer Name", "value": "customer_name"}, {"label": "Customer Email", "value": "customer_email"}, {"label": "Customer Phone", "value": "customer_phone"}, {"label": "Order Status", "value": "order_status"}, {"label": "Updated At", "value": "updated_at"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "store_name": "Store Name", "updated_at": "2025-08-01 01:00:00", "order_items": "<tr><td>Cappuccino</td><td>2</td><td>70.000đ</td><td>140.000đ</td></tr><tr><td>Bánh Croissant</td><td>1</td><td>45.000đ</td><td>45.000đ</td></tr>", "order_total": "185.000đ", "order_status": "Đã thanh toán", "customer_name": "Nguyễn Văn A", "customer_email": "nguyen.van.a@example.com", "customer_phone": "0987654321", "shipping_address": "53 D Street, Lakeview"}',
     'description' => '📢 Cập nhật trạng thái đơn hàngXin chào quản trị viên,Đơn hàng #{{order_code}} trên hệ thống {{store_name}} vừa được cập nhật trạng thái.Khách hàng: {{custo...',
     'language_id' => 1,
     'user_id' => NULL,
     'created_at' => '2025-08-25 14:11:25',
     'updated_at' => '2025-08-25 14:11:25',
     'deleted_at' => NULL,
  ),
  4 => 
  (object) array(
     'id' => 5,
     'name' => 'Cảnh báo: Yêu cầu hoàn tiền mới từ đơn hàng {order_code}',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'admin_on_new_refund_request',
     'content' => '<h1>⚠️ Cảnh báo: Yêu cầu hoàn tiền mới</h1><p>Xin chào quản trị viên,</p><p>Khách hàng vừa tạo một yêu cầu hoàn tiền mới trên hệ thống <strong>{{store_name}}</strong>.</p><p><strong>Mã đơn hàng:</strong> #{{order_code}}</p><p><strong>Khách hàng:</strong> {{customer_name}} ({{customer_email}}, {{customer_phone}})</p><p><strong>Ngày yêu cầu:</strong> {{request_date}}</p><p><strong>Lý do hoàn tiền:</strong> {{refund_reason}}</p><p><strong>Số tiền yêu cầu hoàn:</strong> {{refund_amount}}</p><h3>Chi tiết đơn hàng:</h3><table border=\\"1\\" cellpadding=\\"6\\" cellspacing=\\"2\\" width=\\"100%\\"> <thead> <tr> <th>Sản phẩm</th> <th>Số lượng</th> <th>Đơn giá</th> <th>Thành tiền</th> </tr> </thead> <tbody> {{order_items}} </tbody></table><p><strong>Tổng cộng đơn hàng:</strong> {{order_total}}</p><p><strong>Địa chỉ giao hàng:</strong> {{shipping_address}}</p><hr><p>⚡ Vui lòng đăng nhập vào hệ thống để xem xét và xử lý yêu cầu hoàn tiền này.</p><p>Trân trọng,<br>{{store_name}} System</p>',
     'variables' => '[{"label": "Store Name", "value": "store_name"}, {"label": "Order Code", "value": "order_code"}, {"label": "Customer Name", "value": "customer_name"}, {"label": "Customer Email", "value": "customer_email"}, {"label": "Customer Phone", "value": "customer_phone"}, {"label": "Request Date", "value": "request_date"}, {"label": "Refund Reason", "value": "refund_reason"}, {"label": "Refund Amount", "value": "refund_amount"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "store_name": "Store Name", "order_items": "<tr><td>Latte</td><td>1</td><td>60.000đ</td><td>60.000đ</td></tr><tr><td>Bánh Cheese Cake</td><td>1</td><td>60.000đ</td><td>60.000đ</td></tr>", "order_total": "120.000đ", "request_date": "2025-08-01 02:00:00", "customer_name": "Nguyễn Văn A", "refund_amount": "100.000đ", "refund_reason": "Chọn nhầm hàng", "customer_email": "nguyen.van.a@example.com", "customer_phone": "0987654321", "shipping_address": "53 D Street, Lakeview"}',
     'description' => '⚠️ Cảnh báo: Yêu cầu hoàn tiền mớiXin chào quản trị viên,Khách hàng vừa tạo một yêu cầu hoàn tiền mới trên hệ thống {{store_name}}.Mã đơn hàng: #{{orde...',
     'language_id' => 1,
     'user_id' => NULL,
     'created_at' => '2025-08-25 14:17:42',
     'updated_at' => '2025-08-25 22:16:05',
     'deleted_at' => NULL,
  ),
  5 => 
  (object) array(
     'id' => 6,
     'name' => 'Order {order_code} has been created',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'user_on_new_order',
     'content' => '<h1>Hello <b>{{customer_name}}</b>,</h1>
<p>Thank you for shopping at <strong>{{store_name}}</strong>.</p>
<p>Your order <strong>#{{order_code}}</strong> has been successfully paid on {{payment_date}} via {{payment_method}}.</p>
<h3>Order Details:</h3>
<table border="1" cellpadding="6" cellspacing="2"><thead>   <tr>     <th>Product</th>      <th>Quantity</th>      <th>Unit Price</th>     <th>Total</th>    </tr> </thead>  <tbody>    {{order_items}} </tbody></table>
<p><strong>Grand Total:</strong> {{order_total}}</p>
<p>Shipping Address: {{shipping_address}}</p>
<p>We will notify you once your order is processed and shipped.</p><p>Sincerely,<br>{{store_name}}</p>',
     'variables' => '[{"label": "Customer Name", "value": "customer_name"}, {"label": "Store Name", "value": "store_name"}, {"label": "Order Code", "value": "order_code"}, {"label": "Payment Date", "value": "payment_date"}, {"label": "Payment Method", "value": "payment_method"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "store_name": "Store Name", "order_items": "<tr><td>Arabica Coffee</td><td>2</td><td>120,000 VND</td><td>240,000 VND</td></tr><tr><td>French Pastry</td><td>1</td><td>80,000 VND</td><td>80,000 VND</td></tr>", "order_total": "320,000 VND", "payment_date": "2025-08-01 00:00:00", "customer_name": "John Doe", "payment_method": "Credit Card", "shipping_address": "123 Street, Ward, City"}',
     'description' => 'Hello {{customer_name}},
Thank you for shopping at {{store_name}}.
Your order #{{order_code}} has been successfully paid on {{payment_date}} via {{payment_method}}.
Order Details:
        Product  ...',
     'language_id' => 2,
     'user_id' => NULL,
     'created_at' => '2025-08-24 09:00:03',
     'updated_at' => '2025-09-04 09:19:08',
     'deleted_at' => NULL,
  ),
  6 => 
  (object) array(
     'id' => 7,
     'name' => 'Notification: New order #{order_code} from {customer_name}',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'admin_on_new_order',
     'content' => '<h1>📢 New Order Notification</h1>

<p>Hello Admin,</p>
<p>A new order has just been placed on <strong>{{store_name}}</strong>.</p>

<p><strong>Order Code:</strong> #{{order_code}}</p>
<p><strong>Customer:</strong> {{customer_name}}</p>
<p><strong>Email:</strong> {{customer_email}}</p>
<p><strong>Phone:</strong> {{customer_phone}}</p>
<p><strong>Order Date:</strong> {{order_date}}</p>
<p><strong>Payment Method:</strong> {{payment_method}}</p>

<h3>Order Details:</h3>
<table border="1" cellpadding="6" cellspacing="2" width="100%">
    <thead>
        <tr>
            <th>Product</th>
            <th>Quantity</th>
            <th>Unit Price</th>
            <th>Total</th>
        </tr>
    </thead>
    <tbody>
        {{order_items}}
    </tbody>
</table>

<p><strong>Grand Total:</strong> {{order_total}}</p>
<p><strong>Shipping Address:</strong> {{shipping_address}}</p>

<hr>
<p>Please log in to the system to process this order.</p>
<p>Sincerely,<br>{{store_name}}</p>',
     'variables' => '[{"label": "Store Name", "value": "store_name"}, {"label": "Order Code", "value": "order_code"}, {"label": "Customer Name", "value": "customer_name"}, {"label": "Customer Email", "value": "customer_email"}, {"label": "Customer Phone", "value": "customer_phone"}, {"label": "Order Date", "value": "order_date"}, {"label": "Payment Method", "value": "payment_method"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "order_date": "2025-08-01 00:00:00", "store_name": "Store Name", "order_items": "<tr><td>Robusta Coffee</td><td>3</td><td>90,000 VND</td><td>270,000 VND</td></tr><tr><td>Garlic Bread</td><td>2</td><td>50,000 VND</td><td>100,000 VND</td></tr>", "order_total": "370,000 VND", "customer_name": "John Doe", "customer_email": "john.doe@example.com", "customer_phone": "0987654321", "payment_method": "Credit Card", "shipping_address": "53 D Street, Lakeview"}',
     'description' => '📢 New order notification

Hello Admin,
A new order has been created in {{store_name}}.

Order Code: #{{order_code}}
Customer: {{customer_name}}',
     'language_id' => 2,
     'user_id' => NULL,
     'created_at' => '2025-08-25 13:58:42',
     'updated_at' => '2025-08-25 13:58:51',
     'deleted_at' => NULL,
  ),
  7 => 
  (object) array(
     'id' => 8,
     'name' => 'Order {order_code} status updated',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'user_on_status_change',
     'content' => '<h1>Hello <b>{{customer_name}}</b>,</h1>
<p>Your order <strong>#{{order_code}}</strong> at <strong>{{store_name}}</strong> has just been updated.</p><p><strong>New Status:</strong> {{order_status}}</p><p><strong>Updated At:</strong> {{updated_at}}</p><h3>Order Details:</h3><table border="1" cellpadding="6" cellspacing="2" width="100%">  <thead>    <tr>      <th>Product</th>      <th>Quantity</th>      <th>Unit Price</th>      <th>Total</th>    </tr>  </thead> <tbody>   {{order_items}} </tbody></table><p><strong>Grand Total:</strong> {{order_total}}</p><p><strong>Shipping Address:</strong> {{shipping_address}}</p><p>If you have any questions, please contact us for support.</p><p>Sincerely,<br>{{store_name}} Team</p>',
     'variables' => '[{"label": "Customer Name", "value": "customer_name"}, {"label": "Order Code", "value": "order_code"}, {"label": "Store Name", "value": "store_name"}, {"label": "Order Status", "value": "order_status"}, {"label": "Updated At", "value": "updated_at"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "store_name": "Store Name", "updated_at": "2025-08-01 01:00:00", "order_items": "<tr><td>Espresso</td><td>1</td><td>60,000 VND</td><td>60,000 VND</td></tr><tr><td>Chocolate Cookie</td><td>2</td><td>30,000 VND</td><td>60,000 VND</td></tr>", "order_total": "120,000 VND", "order_status": "Paid", "customer_name": "John Doe", "shipping_address": "53 D Street, Lakeview"}',
     'description' => 'Hello {{customer_name}},
Your order #{{order_code}} at {{store_name}} has been updated.
New status: {{order_status}}
Updated at: {{updated_at}}',
     'language_id' => 2,
     'user_id' => NULL,
     'created_at' => '2025-08-25 14:07:23',
     'updated_at' => '2025-08-25 14:07:23',
     'deleted_at' => NULL,
  ),
  8 => 
  (object) array(
     'id' => 9,
     'name' => 'Order {order_code} status changed to {order_status}',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'admin_on_status_change',
     'content' => '<h1>📢 Order Status Update</h1><p>Hello Admin,</p><p>The order <strong>#{{order_code}}</strong> at <strong>{{store_name}}</strong> has just been updated.</p><p><strong>Customer:</strong> {{customer_name}} ({{customer_email}}, {{customer_phone}})</p><p><strong>New Status:</strong> {{order_status}}</p><p><strong>Updated At:</strong> {{updated_at}}</p><h3>Order Details:</h3><table border="1" cellpadding="6" cellspacing="2" width="100%">  <thead>    <tr>      <th>Product</th>      <th>Quantity</th>      <th>Unit Price</th>      <th>Total</th>    </tr>  </thead>  <tbody>    {{order_items}}  </tbody></table><p><strong>Grand Total:</strong> {{order_total}}</p><p><strong>Shipping Address:</strong> {{shipping_address}}</p><hr><p>Please log in to the system to monitor and process this order.</p><p>Sincerely,<br>{{store_name}} System</p>',
     'variables' => '[{"label": "Order Code", "value": "order_code"}, {"label": "Store Name", "value": "store_name"}, {"label": "Customer Name", "value": "customer_name"}, {"label": "Customer Email", "value": "customer_email"}, {"label": "Customer Phone", "value": "customer_phone"}, {"label": "Order Status", "value": "order_status"}, {"label": "Updated At", "value": "updated_at"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "store_name": "Store Name", "updated_at": "2025-08-01 01:00:00", "order_items": "<tr><td>Cappuccino</td><td>2</td><td>70,000 VND</td><td>140,000 VND</td></tr><tr><td>Croissant</td><td>1</td><td>45,000 VND</td><td>45,000 VND</td></tr>", "order_total": "185,000 VND", "order_status": "Paid", "customer_name": "John Doe", "customer_email": "john.doe@example.com", "customer_phone": "0987654321", "shipping_address": "53 D Street, Lakeview"}',
     'description' => '📢 Order status update
Hello Admin,
Order #{{order_code}} in {{store_name}} has been updated.
Customer: {{customer_name}}',
     'language_id' => 2,
     'user_id' => NULL,
     'created_at' => '2025-08-25 14:11:25',
     'updated_at' => '2025-08-25 14:11:25',
     'deleted_at' => NULL,
  ),
  9 => 
  (object) array(
     'id' => 10,
     'name' => 'Alert: New refund request for order {order_code}',
     'group' => 'order',
     'blade_layout' => 'mail.order.layout',
     'key_name' => 'admin_on_new_refund_request',
     'content' => '<h1>⚠️ Alert: New Refund Request</h1><p>Hello Admin,</p><p>A customer has just created a new refund request in <strong>{{store_name}}</strong>.</p><p><strong>Order Code:</strong> #{{order_code}}</p><p><strong>Customer:</strong> {{customer_name}} ({{customer_email}}, {{customer_phone}})</p><p><strong>Request Date:</strong> {{request_date}}</p><p><strong>Refund Reason:</strong> {{refund_reason}}</p><p><strong>Refund Amount:</strong> {{refund_amount}}</p><h3>Order Details:</h3><table border="1" cellpadding="6" cellspacing="2" width="100%">  <thead>    <tr>      <th>Product</th>      <th>Quantity</th>      <th>Unit Price</th>      <th>Total</th>    </tr>  </thead>  <tbody>    {{order_items}}  </tbody></table><p><strong>Grand Total:</strong> {{order_total}}</p><p><strong>Shipping Address:</strong> {{shipping_address}}</p><hr><p>⚡ Please log in to the system to review and process this refund request.</p><p>Sincerely,<br>{{store_name}} System</p>',
     'variables' => '[{"label": "Store Name", "value": "store_name"}, {"label": "Order Code", "value": "order_code"}, {"label": "Customer Name", "value": "customer_name"}, {"label": "Customer Email", "value": "customer_email"}, {"label": "Customer Phone", "value": "customer_phone"}, {"label": "Request Date", "value": "request_date"}, {"label": "Refund Reason", "value": "refund_reason"}, {"label": "Refund Amount", "value": "refund_amount"}, {"label": "Order Items", "value": "order_items"}, {"label": "Order Total", "value": "order_total"}, {"label": "Shipping Address", "value": "shipping_address"}]',
     'sample_data' => '{"order_code": "ORD250801000001", "store_name": "Store Name", "order_items": "<tr><td>Latte</td><td>1</td><td>60,000 VND</td><td>60,000 VND</td></tr>\\n<tr><td>Cheesecake</td><td>1</td><td>60,000 VND</td><td>60,000 VND</td></tr>", "order_total": "120,000 VND", "request_date": "2025-08-01 02:00:00", "customer_name": "John Doe", "refund_amount": "100,000 VND", "refund_reason": "Ordered wrong item", "customer_email": "john.doe@example.com", "customer_phone": "0987654321", "shipping_address": "53 D Street, Lakeview"}',
     'description' => '⚠️ Alert: New refund request
Hello Admin,
A customer has just submitted a new refund request in {{store_name}}.
Order Code: #{{order_code}}',
     'language_id' => 2,
     'user_id' => NULL,
     'created_at' => '2025-08-25 14:17:42',
     'updated_at' => '2025-08-25 14:17:42',
     'deleted_at' => NULL,
  ),
);
