<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSku;
use Illuminate\Validation\Rule;

class SeckillOrderRequest extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'address_id' => [
                'required',
                Rule::exists('user_addresses', 'id')->where('user_id', $this->user()->id)
            ],
            'sku_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (!$sku = ProductSku::find($value)) {
                        return $fail('该商品不存在');
                    }
                    if ($sku->product->type !== Product::TYPE_SECKILL) {
                        return $fail('该商品不支持秒杀');
                    }
                    if ($sku->product->seckill->is_before_start) {
                        return $fail('该秒杀尚未开始');
                    }
                    if ($sku->product->seckill->is_after_end) {
                        return $fail('该秒杀已经结束');
                    }
                    if (!$sku->product->on_sale) {
                        return $fail('该商品尚未上架');
                    }
                    if ($sku->stock < 1) {
                        return $fail('该商品已售罄');
                    }
                    if ($order = Order::query()
                            // 筛选除该用户的所有的订单
                            ->where('user_id', $this->user()->id)
                            ->whereHas('items', function ($query) use ($value) {
                                // 筛选除包含当前 SKU 的订单
                                $query->where('product_sku_id', $value);
                            })->where(function ($query) {
                                // 已经支付的订单
                                $query->whereNotNull('paid_at')
                                    ->orWhere('closed', false);
                            })->first()
                    ) {
                        if ($order->paid_at) {
                            return $fail('你已经购买了该商品');
                        }

                        return $fail('你已经抢购了该商品， 请尽快支付');
                    }
                }
            ]
        ];
    }
}
