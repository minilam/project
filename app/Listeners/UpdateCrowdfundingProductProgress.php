<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Models\Order;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateCrowdfundingProductProgress implements ShouldQueue
{
    /**
     * Handle the event.
     *
     * @param  OrderPaid  $event
     * @return void
     */
    public function handle(OrderPaid $event)
    {
        $order = $event->getOrder();
        // 如果订单类型不是拼团商品订单，则无需处理
        if ($order->type !== Order::TYPE_CROWDFUNDING) {
            return;
        }
        $crowdfunding = $order->itmes[0]->product->crowdfunding;

        $data = Order::query()->where('type', Order::TYPE_CROWDFUNDING)
            ->whereNotNull('paid_at')
            ->whereHas('items', function($query){
                $query->where('product_id', $crowdfunding->product_id);
            })->first([
                //  取出订单总额
                \DB::raw('sum(total_amount) as total_amount'),
                // 去除去重的支持团友数
                \DB::raw('count(distinct(user_id) as user_count'),
            ]);
        
        $crowdfunding->update([
            'total_amount' => $data->total_amount,
            'user_count' => $user_count
        ]);
    }
}
