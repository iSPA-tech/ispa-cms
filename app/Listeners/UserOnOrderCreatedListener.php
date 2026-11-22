<?php

namespace App\Listeners;

use Throwable;
use Cms\Ecommerce\Models\Order;
use Cms\Core\Facades\CMSLogger;
use App\Events\OrderCreatedEvent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Cms\Ecommerce\Services\Order\OrderNotificationService;

class UserOnOrderCreatedListener implements ShouldQueue   // ✅
{
    use InteractsWithQueue;

    public bool $afterCommit = true;                // ✅ (khuyến nghị với email)

    // Tên queue riêng (tuỳ chọn)
    public string $queue = 'emails';

    // Backoff thời gian retry (tuỳ chọn)
    public function backoff(): int|array
    {
        return [10, 30, 60]; // giây
    }

    // Tag để dễ theo dõi trong horizon (nếu dùng)
    public function tags(): array
    {
        return ['order:created'];
    }

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderCreatedEvent $event): void
    {
        CMSLogger::info([
            'message' => 'UserOnOrderCreatedListener triggered',
            'order_id' => $event->orderId,
        ]);

        if (!iSetting('order.order.user_on_new_order')) {
            return;
        }

        $order = Order::query()->find($event->orderId);
        if (!$order) {
            CMSLogger::warning([
                'message' => 'Order not found in UserOnOrderCreatedListener',
                'order_id' => $event->orderId,
            ]);
            return;
        }

        (new OrderNotificationService())->sendNewOrderNotificationToUser($order);
        CMSLogger::info([
            'message' => 'User new order notification dispatched',
            'order_id' => $order->id,
        ]);
    }

    // Log khi thất bại (tuỳ chọn)
    public function failed(OrderCreatedEvent $event, Throwable $e): void
    {
        CMSLogger::error([
            'message' => $e->getMessage(),
            'exception' => $e, 'event' => $event,
        ]);
    }
}
