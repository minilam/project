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
            'address.province' => 'required',
            'address.city' => 'required',
            'address.district' => 'required',
            'address.address' => 'required',
            'address.zip' => 'required',
            'address.contact_name' => 'required',
            'address.contact_phone' => 'required',
            'sku_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    // 从redis中读取数据
                    $stock = \Redis::get('seckill_sku_' . $value);
                    if (is_null($stock)) {
                        return $fail('该商品不存在');
                    }
                    // 判断库存
                    if ($stock < 0) {
                        return $fail('该商品已售罄');
                    }
                    $sku = ProductSku::find($value);
                    if ($sku->product->seckill->is_before_start) {
                        return $fail('该秒杀尚未开始');
                    }
                    if ($sku->product->seckill->is_after_end) {
                        return $fail('该秒杀已经结束');
                    }
                    if (!$user = \Auth::user()) {
                        throw new AuthenticationException('请先登录');
                    }
                    if (!$user->email_verified_at) {
                        throw new InvalidRequestException('请先验证邮箱');
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
